<?php

session_name("logistics_session");
session_start();


// 2) Ensure user has role admin, global_admin, or customer_admin
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin','global_admin','customer_admin'])) {
    header("Location: unauthorized");
     exit();
}

// Database connection
require_once '../config.php';
require_once 'document_helpers.php';
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
    // If admin or customer_admin, fetch exactly one account_id from bridging table
    $sqlOne = "
        SELECT account_id
        FROM customer_account_users
        WHERE user_id = ?
          AND role IN ('admin', 'customer_admin')
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
        // Project size in GW (stored as float, user inputs in GW)
        $project_size              = isset($_POST['project_size']) && $_POST['project_size'] !== '' ? floatval($_POST['project_size']) : 0.0;

        // New site information fields (optional)
        $phone1                    = trim($_POST['phone1'] ?? '');
        $phone2                    = trim($_POST['phone2'] ?? '');
        $timezone                  = trim($_POST['timezone'] ?? 'America/New_York');
        // Site receiving hours - will be stored in site_operating_hours table
        $receiving_hours_start     = trim($_POST['receiving_hours_start'] ?? '08:00');
        $receiving_hours_end       = trim($_POST['receiving_hours_end'] ?? '17:00');
        $reference_numbers         = ''; // Deprecated - using site_operating_hours table instead
        $instructions              = trim($_POST['instructions'] ?? '');
        $additional_notes          = trim($_POST['additional_notes'] ?? '');
        $driver_handout_url        = null; // Legacy column (now stored in project_documents)

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
        $module_docs_sub_type      = trim($_POST['module_docs_sub_type'] ?? '');
        $module_docs_description   = trim($_POST['module_docs_description'] ?? '');
        $module_docs_url           = null; // Legacy column (now stored in project_documents)

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

        // Default project image (can be set via Project Photos manager later)
        $image_url = "pictures/default_project.png"; // default cover until photos are arranged

        // Note: Driver Handout and Module Documentation now save to project_documents

        // Insert into projects with separate address fields and new site information
        $stmt = $conn->prepare("
            INSERT INTO projects (
                account_id,
                project_name,
                project_size,
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
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        if (!$stmt) {
            throw new Exception("Error preparing project insert: " . $conn->error);
        }
        $stmt->bind_param(
            "isdssssssdsssssss",
            $account_id,
            $project_name,
            $project_size,
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

        // Insert site operating hours (Mon-Fri with the specified times)
        // day_of_week: 0=Sunday, 1=Monday, 2=Tuesday, 3=Wednesday, 4=Thursday, 5=Friday, 6=Saturday
        $stmtHours = $conn->prepare("
            INSERT INTO site_operating_hours (project_id, day_of_week, start_time, end_time)
            VALUES (?, ?, ?, ?)
        ");
        if ($stmtHours) {
            // Insert for Monday (1) through Friday (5)
            for ($day = 1; $day <= 5; $day++) {
                $stmtHours->bind_param("iiss", $project_id, $day, $receiving_hours_start, $receiving_hours_end);
                $stmtHours->execute();
            }
            $stmtHours->close();
        }

        // Project photos are now managed on a dedicated page; cover image is set from the first photo there.

        // Move pre-saved temporary photos into project_documents and set cover image
        try {
            $temp_token = trim($_POST['temp_photo_token'] ?? '');
            if ($temp_token !== '') {
                $temp_dir = __DIR__ . '/uploads/tmp_photos/' . preg_replace('/[^A-Za-z0-9_-]/','', $temp_token);
                if (is_dir($temp_dir)) {
                    require_once 'document_helpers.php';
                    // Build order from hidden input
                    $order_csv = trim($_POST['temp_photo_order'] ?? '');
                    $ordered = array_filter(array_map('trim', explode(',', $order_csv)));
                    // Gather all files in dir
                    $files = array_values(array_filter(scandir($temp_dir), function($f){ return $f !== '.' && $f !== '..'; }));
                    // Reconcile order: first those in ordered list, then the rest
                    $ordered_set = [];
                    $queue = [];
                    foreach ($ordered as $name) { $ordered_set[$name] = true; if (in_array($name, $files)) $queue[] = $name; }
                    foreach ($files as $name) { if (!isset($ordered_set[$name])) $queue[] = $name; }
                    $cover_set = false;
                    foreach ($queue as $name) {
                        $src = $temp_dir . '/' . $name;
                        if (!is_file($src)) continue;
                        $doc = [
                            'project_id' => $project_id,
                            'document_type' => 'pictures',
                            'document_sub_type' => 'Project Photo',
                            'original_name' => $name,
                            'uploaded_by' => $user_id,
                            'description' => 'Project Photo'
                        ];
                        $res = importExistingFileToProjectDocuments($conn, $doc, $src);
                        if (!$cover_set && isset($res['file_path'])) {
                            $img_path = $res['file_path'];
                            $u = $conn->prepare('UPDATE projects SET image_url = ? WHERE id = ?');
                            if ($u) { $u->bind_param('si', $img_path, $project_id); $u->execute(); $u->close(); }
                            $cover_set = true;
                        }
                    }
                    // Cleanup temp dir
                    @array_map('unlink', glob($temp_dir.'/*'));
                    @rmdir($temp_dir);
                }
            }
        } catch (Exception $e) {
            // Non-fatal; continue
        }

        // Save Driver Handout to project_documents as Shipments -> Delivery SOP (new format)
        if (isset($_FILES['driver_handout']) && $_FILES['driver_handout']['error'] !== UPLOAD_ERR_NO_FILE) {
            if ($_FILES['driver_handout']['error'] === UPLOAD_ERR_OK) {
                // Build document data and save via helper
                $doc_data = [
                    'project_id' => $project_id,
                    'document_type' => 'shipments',
                    'document_sub_type' => 'Delivery SOP',
                    'original_name' => $_FILES['driver_handout']['name'],
                    'file_size' => $_FILES['driver_handout']['size'],
                    'mime_type' => mime_content_type($_FILES['driver_handout']['tmp_name']),
                    'uploaded_by' => $user_id,
                    'tmp_name' => $_FILES['driver_handout']['tmp_name'],
                    'description' => 'Driver Handout'
                ];
                // Validate extension and size similar to global uploads
                $allowed_ext = ['pdf','doc','docx','jpg','jpeg','png'];
                $ext = strtolower(pathinfo($doc_data['original_name'], PATHINFO_EXTENSION));
                if (!in_array($ext, $allowed_ext)) {
                    throw new Exception('Invalid driver handout file type.');
                }
                saveDocumentToProjectDocuments($conn, $doc_data);
            } else {
                throw new Exception('Driver handout upload error code: ' . $_FILES['driver_handout']['error']);
            }
        }

        // Save Module Documentation (uploaded before creation) into project_documents under Modules with provided sub-type
        if (isset($_FILES['module_docs'])) {
            // If any file selected, require a sub-type
            $hasFile = false;
            if (is_array($_FILES['module_docs']['name'])) {
                foreach ($_FILES['module_docs']['error'] as $err) {
                    if ($err !== UPLOAD_ERR_NO_FILE) { $hasFile = true; break; }
                }
            } else {
                $hasFile = $_FILES['module_docs']['error'] !== UPLOAD_ERR_NO_FILE;
            }

            if ($hasFile) {
                if ($module_docs_sub_type === '') {
                    throw new Exception('Please enter a Module Documentation Sub-Type when uploading files.');
                }

                // Normalize to arrays for iteration
                $names = (array)$_FILES['module_docs']['name'];
                $types = (array)$_FILES['module_docs']['type'];
                $tmps  = (array)$_FILES['module_docs']['tmp_name'];
                $errs  = (array)$_FILES['module_docs']['error'];
                $sizes = (array)$_FILES['module_docs']['size'];

                foreach ($names as $i => $orig) {
                    if (!isset($errs[$i]) || $errs[$i] === UPLOAD_ERR_NO_FILE) continue;
                    if ($errs[$i] !== UPLOAD_ERR_OK) {
                        throw new Exception('Module document upload error for file: ' . $orig);
                    }

                    $tmpName = $tmps[$i];
                    $mime    = mime_content_type($tmpName);
                    $doc = [
                        'project_id' => $project_id,
                        'document_type' => 'modules',
                        'document_sub_type' => $module_docs_sub_type,
                        'original_name' => $orig,
                        'file_size' => $sizes[$i],
                        'mime_type' => $mime,
                        'uploaded_by' => $user_id,
                        'tmp_name' => $tmpName,
                        'description' => ($module_docs_description !== '' ? $module_docs_description : 'Module Documentation')
                    ];
                    saveDocumentToProjectDocuments($conn, $doc);
                }
            }
        }

        // If wattage and quantities are provided, create a module batch for them
        if (isset($_POST['wattages'], $_POST['quantities'])) {
            $wattages   = $_POST['wattages'];
            $quantities = $_POST['quantities'];
            $manufacturer_id_for_batch = isset($_POST['manufacturer_id']) ? intval($_POST['manufacturer_id']) : null;
            $location_id_for_batch = isset($_POST['location_id']) ? intval($_POST['location_id']) : null;

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

            if ($manufacturer_id_for_batch && $location_id_for_batch) {
                // Get manufacturer details for vendor name and initial location (from selected location)
                $stmt_mfg = $conn->prepare("
                    SELECT
                        m.name,
                        ml.street_address,
                        ml.city,
                        ml.state,
                        ml.zip_code
                    FROM manufacturers m
                    LEFT JOIN manufacturer_locations ml ON m.id = ml.manufacturer_id
                    WHERE m.id = ? AND ml.id = ?");
                if ($stmt_mfg) {
                    $stmt_mfg->bind_param("ii", $manufacturer_id_for_batch, $location_id_for_batch);
                    $stmt_mfg->execute();
                    $stmt_mfg->bind_result($mfg_name, $mfg_street, $mfg_city, $mfg_state, $mfg_zip);
                    if ($stmt_mfg->fetch()) {
                        $manufacturer_name = $mfg_name;
                        $address_parts = array_filter([$mfg_street, $mfg_city, $mfg_state, $mfg_zip]);
                        $manufacturer_address = implode(', ', $address_parts);
                    }
                    $stmt_mfg->close();
                }
            } else if ($manufacturer_id_for_batch) {
                // Fallback to primary location if no specific location was selected
                $stmt_mfg = $conn->prepare("
                    SELECT
                        m.name,
                        ml.street_address,
                        ml.city,
                        ml.state,
                        ml.zip_code
                    FROM manufacturers m
                    LEFT JOIN manufacturer_locations ml ON m.id = ml.manufacturer_id AND ml.is_primary = TRUE
                    WHERE m.id = ?");
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
        $successMessage = "Project added successfully! <a href='project_overview?project_id=" . $project_id . "' style='color: #488C9A; text-decoration: underline;'>View Project</a>.";

        // If modules were created, enhance the success message with module count
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
    <title>Create New Project</title>
    <link rel="stylesheet" href="portal.css">
    <link rel="icon" href="pictures/favicon.png" type="image/x-icon">
    <link href="https://fonts.googleapis.com/css?family=Poppins:300,400,500,600,700&display=swap" rel="stylesheet">
    <style>
        /* Modern Page Header */
        .add-project-header {
            background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%);
            border-radius: 24px;
            padding: 32px;
            margin-bottom: 32px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.06);
            border: 1px solid rgba(72, 140, 154, 0.08);
            position: relative;
            overflow: hidden;
        }
        .add-project-header::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, #488C9A 0%, #293E4C 100%);
        }
        .add-project-header-content {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 20px;
        }
        .add-project-header h1 {
            font-size: 2.5em;
            font-weight: 700;
            background: linear-gradient(135deg, #293E4C 0%, #488C9A 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            margin: 0 0 8px 0;
            line-height: 1.2;
        }
        .add-project-header .subtitle {
            color: #6c757d;
            font-size: 1.1em;
            font-weight: 500;
            margin: 0;
        }
        .header-actions {
            display: flex;
            gap: 12px;
        }
        .btn-back {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 12px 24px;
            background: #fff;
            color: #488C9A;
            border: 2px solid #488C9A;
            border-radius: 12px;
            font-size: 0.95rem;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
        }
        .btn-back:hover {
            background: #488C9A;
            color: #fff;
        }

        /* Step Indicator */
        .step-indicator {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 0;
            margin-bottom: 32px;
            padding: 20px;
            background: #fff;
            border-radius: 16px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.06);
        }
        .step {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 12px 20px;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        .step-number {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background: #e9ecef;
            color: #6c757d;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 0.9rem;
            transition: all 0.3s ease;
        }
        .step.active .step-number {
            background: linear-gradient(135deg, #488C9A 0%, #3a7a87 100%);
            color: #fff;
        }
        .step.completed .step-number {
            background: #28a745;
            color: #fff;
        }
        .step.completed .step-number::after {
            content: '\2713';
        }
        .step-label {
            font-weight: 500;
            color: #6c757d;
            font-size: 0.95rem;
        }
        .step.active .step-label {
            color: #293E4C;
        }
        .step-connector {
            width: 60px;
            height: 3px;
            background: #e9ecef;
            margin: 0 5px;
        }
        .step-connector.completed {
            background: #28a745;
        }
        .step-tag {
            font-size: 0.7rem;
            padding: 2px 8px;
            border-radius: 10px;
            margin-left: 8px;
            font-weight: 600;
        }
        .step-tag.required {
            background: #fff3cd;
            color: #856404;
        }
        .step-tag.optional {
            background: #e9ecef;
            color: #6c757d;
        }

        /* Accordion Sections */
        .accordion-section {
            background: #fff;
            border-radius: 16px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.06);
            margin-bottom: 20px;
            overflow: hidden;
            border: 1px solid #e9ecef;
        }
        .accordion-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 20px 24px;
            background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%);
            cursor: pointer;
            transition: all 0.3s ease;
            border-bottom: 1px solid transparent;
        }
        .accordion-header:hover {
            background: #f8f9fa;
        }
        .accordion-header.active {
            border-bottom: 1px solid #e9ecef;
        }
        .accordion-header h2 {
            margin: 0;
            font-size: 1.25rem;
            font-weight: 600;
            color: #293E4C;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .accordion-header h2 .step-badge {
            background: linear-gradient(135deg, #488C9A 0%, #3a7a87 100%);
            color: #fff;
            width: 28px;
            height: 28px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.85rem;
        }
        .accordion-header h2 .step-badge.completed {
            background: #28a745;
        }
        .accordion-toggle {
            font-size: 1.5rem;
            color: #6c757d;
            transition: transform 0.3s ease;
        }
        .accordion-header.active .accordion-toggle {
            transform: rotate(180deg);
        }
        .accordion-content {
            padding: 0;
            max-height: 0;
            overflow: hidden;
            transition: max-height 0.4s ease, padding 0.3s ease;
        }
        .accordion-content.open {
            padding: 24px;
            max-height: 3000px;
        }
        .section-description {
            color: #6c757d;
            margin-bottom: 24px;
            padding: 16px;
            background: #f8f9fa;
            border-radius: 12px;
            border-left: 4px solid #488C9A;
        }

        /* Form Fields */
        .form-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }
        .form-row.single {
            grid-template-columns: 1fr;
        }
        .form-group {
            display: flex;
            flex-direction: column;
        }
        .form-group label {
            font-weight: 500;
            color: #333;
            margin-bottom: 8px;
            font-size: 0.95rem;
        }
        .form-group label .required-star {
            color: #dc3545;
            margin-left: 4px;
        }
        .form-group label .optional-tag {
            color: #6c757d;
            font-weight: 400;
            font-size: 0.85rem;
            margin-left: 8px;
        }
        .form-group input,
        .form-group select,
        .form-group textarea {
            padding: 12px 16px;
            border: 2px solid #e9ecef;
            border-radius: 10px;
            font-size: 1rem;
            transition: all 0.2s ease;
            background: #fafafa;
        }
        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: #488C9A;
            background: #fff;
            box-shadow: 0 0 0 3px rgba(72, 140, 154, 0.1);
        }
        .form-group input.required-field {
            border-left: 4px solid #dc3545;
        }
        .form-group textarea {
            min-height: 100px;
            resize: vertical;
        }
        .form-group .help-text {
            font-size: 0.85rem;
            color: #6c757d;
            margin-top: 6px;
        }

        /* Address Grid */
        .address-grid {
            display: grid;
            grid-template-columns: 2fr 1fr 1fr 1fr;
            gap: 16px;
        }
        @media (max-width: 768px) {
            .address-grid {
                grid-template-columns: 1fr 1fr;
            }
        }

        /* Hours Grid */
        .hours-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
            max-width: 400px;
        }

        /* Section Actions */
        .section-actions {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 24px;
            padding-top: 24px;
            border-top: 1px solid #e9ecef;
        }
        .btn-continue {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 14px 28px;
            background: linear-gradient(135deg, #488C9A 0%, #3a7a87 100%);
            color: #fff;
            border: none;
            border-radius: 12px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        .btn-continue:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(72, 140, 154, 0.4);
        }
        .btn-back-step {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 14px 24px;
            background: #fff;
            color: #6c757d;
            border: 2px solid #e9ecef;
            border-radius: 12px;
            font-size: 1rem;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        .btn-back-step:hover {
            background: #f8f9fa;
            border-color: #dee2e6;
        }
        .btn-skip {
            padding: 14px 24px;
            background: transparent;
            color: #6c757d;
            border: none;
            font-size: 0.95rem;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        .btn-skip:hover {
            color: #488C9A;
        }

        /* Submit Button */
        .btn-submit {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            padding: 16px 40px;
            background: linear-gradient(135deg, #28a745 0%, #20963b 100%);
            color: #fff;
            border: none;
            border-radius: 12px;
            font-size: 1.1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            min-width: 200px;
        }
        .btn-submit:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(40, 167, 69, 0.4);
        }

        /* Wattage Entries */
        .wattage-container {
            margin: 20px 0;
        }
        .wattage-entry {
            display: grid;
            grid-template-columns: 1fr 1fr auto;
            gap: 16px;
            align-items: end;
            padding: 16px;
            background: #f8f9fa;
            border-radius: 12px;
            margin-bottom: 12px;
            border: 1px solid #e9ecef;
        }
        .wattage-entry .form-group {
            margin-bottom: 0;
        }
        .btn-remove {
            padding: 10px 16px;
            background: #dc3545;
            color: #fff;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 500;
            transition: background 0.2s ease;
        }
        .btn-remove:hover {
            background: #c82333;
        }
        .btn-add-wattage {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 12px 20px;
            background: #fff;
            color: #488C9A;
            border: 2px dashed #488C9A;
            border-radius: 10px;
            font-size: 0.95rem;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        .btn-add-wattage:hover {
            background: rgba(72, 140, 154, 0.1);
        }

        /* Specs Grid */
        .specs-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
            gap: 16px;
        }
        .specs-grid .form-group {
            margin-bottom: 0;
        }

        /* Photo Upload */
        .photo-upload-area {
            padding: 40px 20px;
            text-align: center;
            border: 2px dashed rgba(72, 140, 154, 0.3);
            border-radius: 12px;
            cursor: pointer;
            transition: all 0.2s ease;
            background: #fafafa;
        }
        .photo-upload-area:hover {
            border-color: #488C9A;
            background: rgba(72, 140, 154, 0.05);
        }
        .photo-upload-area .upload-icon {
            font-size: 2.5rem;
            color: #488C9A;
            margin-bottom: 12px;
        }
        .photo-upload-area .upload-text {
            color: #333;
            font-weight: 500;
            margin-bottom: 8px;
        }
        .photo-upload-area .upload-subtext {
            color: #6c757d;
            font-size: 0.9rem;
        }
        .photo-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(120px, 1fr));
            gap: 12px;
            margin-top: 16px;
        }

        /* Success/Error Messages */
        .message {
            padding: 16px 20px;
            border-radius: 12px;
            margin-bottom: 24px;
            font-weight: 500;
        }
        .message.success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        .message.error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        /* Module Options Cards */
        .module-options {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 24px;
        }
        .module-option-card {
            padding: 24px;
            border-radius: 12px;
            border: 2px solid #e9ecef;
            transition: all 0.2s ease;
        }
        .module-option-card.primary {
            border-color: #488C9A;
            background: rgba(72, 140, 154, 0.05);
        }
        .module-option-card h4 {
            display: flex;
            align-items: center;
            gap: 10px;
            margin: 0 0 12px 0;
            color: #293E4C;
        }
        .module-option-card h4 .option-number {
            width: 28px;
            height: 28px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.85rem;
            font-weight: 600;
        }
        .module-option-card.primary h4 .option-number {
            background: #488C9A;
            color: #fff;
        }
        .module-option-card:not(.primary) h4 .option-number {
            background: #6c757d;
            color: #fff;
        }
        .module-option-card p {
            color: #555;
            font-size: 0.9rem;
            margin-bottom: 12px;
        }
        .module-option-card ul {
            color: #666;
            font-size: 0.85rem;
            margin: 0;
            padding-left: 20px;
        }

        @media (max-width: 768px) {
            .module-options {
                grid-template-columns: 1fr;
            }
            .step-indicator {
                flex-wrap: wrap;
                gap: 10px;
            }
            .step-connector {
                display: none;
            }
            .step-label {
                display: none;
            }
        }
    </style>
</head>
<body>
<?php include 'header.php'; ?>
<main>
    <?php require_once 'components/breadcrumbs.php'; echo slp_render_breadcrumbs(['current_label' => 'Create Project', 'extra' => [['label' => 'Manage Projects', 'url' => 'manage_projects.php']]]); ?>

    <!-- Modern Page Header -->
    <div class="add-project-header">
        <div class="add-project-header-content">
            <div>
                <h1>Create New Project</h1>
                <p class="subtitle">Set up a new solar project in just a few steps</p>
            </div>
            <div class="header-actions">
                <a href="manage_projects.php" class="btn-back">&larr; Back to Projects</a>
            </div>
        </div>
    </div>

    <!-- Messages -->
    <?php if (!empty($successMessage)): ?>
        <div class="message success"><?php echo $successMessage; ?></div>
    <?php endif; ?>
    <?php if (!empty($errorMessage)): ?>
        <div class="message error"><?php echo htmlspecialchars($errorMessage); ?></div>
    <?php endif; ?>

    <!-- Step Indicator -->
    <div class="step-indicator">
        <div class="step active" data-step="1" onclick="goToStep(1)">
            <div class="step-number">1</div>
            <span class="step-label">Project Details</span>
            <span class="step-tag required">Required</span>
        </div>
        <div class="step-connector"></div>
        <div class="step" data-step="2" onclick="goToStep(2)">
            <div class="step-number">2</div>
            <span class="step-label">Site Info</span>
            <span class="step-tag optional">Optional</span>
        </div>
        <div class="step-connector"></div>
        <div class="step" data-step="3" onclick="goToStep(3)">
            <div class="step-number">3</div>
            <span class="step-label">Modules</span>
            <span class="step-tag optional">Optional</span>
        </div>
    </div>

    <form method="POST" action="" enctype="multipart/form-data" id="projectForm">
        <!-- Hidden fields -->
        <?php if ($role !== 'global_admin'): ?>
            <input type="hidden" name="account_id" value="<?php echo $account_id_for_admin; ?>">
        <?php endif; ?>
        <input type="hidden" id="tempPhotoToken" name="temp_photo_token" value="<?php echo htmlspecialchars(uniqid('ppt_', true)); ?>">
        <input type="hidden" id="tempPhotoOrder" name="temp_photo_order" value="">

        <!-- Step 1: Project Details (Required) -->
        <div class="accordion-section" data-section="1">
            <div class="accordion-header active" onclick="toggleAccordion(1)">
                <h2><span class="step-badge" id="badge-1">1</span> Project Details <span class="step-tag required">Required</span></h2>
                <span class="accordion-toggle">&#9660;</span>
            </div>
            <div class="accordion-content open">
                <div class="section-description">
                    Enter the essential information for your project. All fields in this section are required.
                </div>

                <?php if ($role === 'global_admin'): ?>
                <div class="form-row single">
                    <div class="form-group">
                        <label>Account<span class="required-star">*</span></label>
                        <select name="account_id" id="account_id" class="required-field" required>
                            <option value="">Select Account</option>
                            <?php foreach ($accounts as $acc): ?>
                                <option value="<?php echo $acc['id']; ?>"><?php echo htmlspecialchars($acc['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <?php endif; ?>

                <div class="form-row">
                    <div class="form-group">
                        <label>Project Name<span class="required-star">*</span></label>
                        <input type="text" name="project_name" id="project_name" class="required-field" required placeholder="e.g. Solar Farm Alpha">
                    </div>
                    <div class="form-group">
                        <label>Project Size (GW)<span class="optional-tag">(optional)</span></label>
                        <input type="number" name="project_size" id="project_size" step="0.001" min="0" placeholder="e.g. 1.5">
                        <span class="help-text">Target size in gigawatts</span>
                    </div>
                    <div class="form-group">
                        <label>Estimated Completion Date<span class="required-star">*</span></label>
                        <input type="date" name="estimated_completion_date" id="estimated_completion_date" class="required-field" required>
                    </div>
                </div>

                <div class="form-group" style="margin-bottom: 20px;">
                    <label>Project Address<span class="required-star">*</span></label>
                    <div class="address-grid">
                        <div class="form-group">
                            <input type="text" name="street_address" id="street_address" class="required-field" required placeholder="Street Address">
                        </div>
                        <div class="form-group">
                            <input type="text" name="city" id="city" class="required-field" required placeholder="City">
                        </div>
                        <div class="form-group">
                            <input type="text" name="state" id="state" class="required-field" required placeholder="State">
                        </div>
                        <div class="form-group">
                            <input type="text" name="zip_code" id="zip_code" class="required-field" required placeholder="Zip">
                        </div>
                    </div>
                </div>

                <div class="form-row single">
                    <div class="form-group">
                        <label>Project Photo<span class="optional-tag">(optional)</span></label>
                        <div class="photo-upload-area" id="prePhotoDrop" onclick="document.getElementById('prePhotoInput').click()">
                            <div class="upload-icon">&#128247;</div>
                            <div class="upload-text">Drop image here or click to browse</div>
                            <div class="upload-subtext">PNG, JPG, or GIF up to 10MB</div>
                        </div>
                        <input type="file" id="prePhotoInput" accept="image/*" style="display:none">
                        <div class="photo-grid" id="prePhotoGrid"></div>
                    </div>
                </div>

                <?php if ($role === 'global_admin'): ?>
                <div class="form-row single">
                    <div class="form-group">
                        <label>Solterra Fee<span class="optional-tag">(optional)</span></label>
                        <input type="number" name="solterra_fee" step="0.0001" placeholder="e.g. 0.0010">
                        <span class="help-text">Per-module fee for Solterra services</span>
                    </div>
                </div>
                <?php else: ?>
                <input type="hidden" name="solterra_fee" value="0.0000">
                <?php endif; ?>

                <div class="section-actions">
                    <div></div>
                    <button type="button" class="btn-continue" onclick="goToStep(2)">
                        Continue to Site Info <span>&rarr;</span>
                    </button>
                </div>
            </div>
        </div>

        <!-- Step 2: Site Information (Optional) -->
        <div class="accordion-section" data-section="2">
            <div class="accordion-header" onclick="toggleAccordion(2)">
                <h2><span class="step-badge" id="badge-2">2</span> Site Information <span class="step-tag optional">Optional</span></h2>
                <span class="accordion-toggle">&#9660;</span>
            </div>
            <div class="accordion-content">
                <div class="section-description">
                    Add contact details and receiving hours for the project site. You can skip this step and add it later.
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Primary Phone<span class="optional-tag">(optional)</span></label>
                        <input type="tel" name="phone1" placeholder="e.g. 555-555-5555">
                    </div>
                    <div class="form-group">
                        <label>Secondary Phone<span class="optional-tag">(optional)</span></label>
                        <input type="tel" name="phone2" placeholder="e.g. 555-555-5555">
                    </div>
                    <div class="form-group">
                        <label>Timezone</label>
                        <select name="timezone">
                            <option value="America/New_York" selected>Eastern</option>
                            <option value="America/Chicago">Central</option>
                            <option value="America/Denver">Mountain</option>
                            <option value="America/Los_Angeles">Pacific</option>
                            <option value="UTC">UTC</option>
                        </select>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Site Receiving Hours</label>
                        <div class="hours-grid">
                            <div class="form-group">
                                <label style="font-size: 0.85rem; color: #6c757d;">Opens</label>
                                <input type="time" name="receiving_hours_start" value="08:00">
                            </div>
                            <div class="form-group">
                                <label style="font-size: 0.85rem; color: #6c757d;">Closes</label>
                                <input type="time" name="receiving_hours_end" value="17:00">
                            </div>
                        </div>
                        <span class="help-text">Hours will be set for Monday-Friday</span>
                    </div>
                </div>

                <div class="form-row single">
                    <div class="form-group">
                        <label>Special Instructions<span class="optional-tag">(optional)</span></label>
                        <textarea name="instructions" placeholder="e.g. Gate code required, call ahead for access, etc."></textarea>
                    </div>
                </div>

                <div class="form-row single">
                    <div class="form-group">
                        <label>Additional Notes<span class="optional-tag">(optional)</span></label>
                        <textarea name="additional_notes" placeholder="Any other relevant information..."></textarea>
                    </div>
                </div>

                <div class="form-row single">
                    <div class="form-group">
                        <label>Driver Handout<span class="optional-tag">(optional)</span></label>
                        <input type="file" name="driver_handout" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png">
                        <span class="help-text">Upload driver instructions, site maps, or other handout materials (PDF, DOC, JPG, PNG - max 5MB)</span>
                    </div>
                </div>

                <div class="section-actions">
                    <button type="button" class="btn-back-step" onclick="goToStep(1)">
                        <span>&larr;</span> Back
                    </button>
                    <div style="display: flex; gap: 12px; align-items: center;">
                        <button type="button" class="btn-skip" onclick="goToStep(3)">Skip this step</button>
                        <button type="button" class="btn-continue" onclick="goToStep(3)">
                            Continue to Modules <span>&rarr;</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Step 3: Module Setup (Optional) -->
        <div class="accordion-section" data-section="3">
            <div class="accordion-header" onclick="toggleAccordion(3)">
                <h2><span class="step-badge" id="badge-3">3</span> Module Setup <span class="step-tag optional">Optional</span></h2>
                <span class="accordion-toggle">&#9660;</span>
            </div>
            <div class="accordion-content">
                <div class="section-description">
                    Set up your module orders now, or skip and add them later from the Project Overview page.
                </div>

                <div class="module-options">
                    <div class="module-option-card primary">
                        <h4><span class="option-number">1</span> Manual Setup</h4>
                        <p>Enter wattage orders below. Pallets can be created manually later.</p>
                        <ul>
                            <li>Best for: Planning ahead before manufacturer data is available</li>
                            <li>You control: Pallet creation, status updates, delivery scheduling</li>
                        </ul>
                    </div>
                    <div class="module-option-card">
                        <h4><span class="option-number">2</span> Import Schedule Later</h4>
                        <p>After creating the project, import the manufacturer's shipping schedule.</p>
                        <ul>
                            <li>Best for: When you have the manufacturer's BOL/pallet data</li>
                            <li>Auto-creates: Pallets, deliveries, links by BOL number</li>
                        </ul>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Manufacturer<span class="optional-tag">(optional)</span></label>
                        <select name="manufacturer_id" id="manufacturer_id" onchange="handleManufacturerChange(this)">
                            <option value="">Select Manufacturer</option>
                            <?php foreach ($manufacturers as $mfg): ?>
                                <option value="<?php echo $mfg['id']; ?>">
                                    <?php echo htmlspecialchars($mfg['name']); ?>
                                    <?php if (!empty($mfg['short_name'])): ?>(<?php echo htmlspecialchars($mfg['short_name']); ?>)<?php endif; ?>
                                </option>
                            <?php endforeach; ?>
                            <option value="add_new" style="font-style: italic;">+ Add New Manufacturer</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Location<span class="optional-tag">(optional)</span></label>
                        <select name="location_id" id="location_id" disabled>
                            <option value="">Select a manufacturer first</option>
                        </select>
                    </div>
                </div>

                <div class="form-group">
                    <label>Wattage Orders<span class="optional-tag">(optional)</span></label>
                    <div id="wattage-container" class="wattage-container">
                        <!-- Wattage entries added by JS -->
                    </div>
                    <button type="button" class="btn-add-wattage" onclick="addWattageField()">
                        <span>+</span> Add Wattage Order
                    </button>
                </div>

                <details style="margin-top: 24px;">
                    <summary style="cursor: pointer; font-weight: 500; color: #488C9A; padding: 12px 0;">
                        Advanced: Pallet & Logistics Specifications
                    </summary>
                    <div style="padding: 20px; background: #f8f9fa; border-radius: 12px; margin-top: 12px;">
                        <div class="specs-grid">
                            <div class="form-group">
                                <label>Modules/Pallet</label>
                                <input type="number" name="modules_per_pallet" min="1" placeholder="e.g. 30">
                            </div>
                            <div class="form-group">
                                <label>Pallets/Truck</label>
                                <input type="number" name="pallets_per_truck" min="1" placeholder="e.g. 22">
                            </div>
                            <div class="form-group">
                                <label>Length (mm)</label>
                                <input type="number" name="pallet_length_mm" min="1" placeholder="e.g. 2384">
                            </div>
                            <div class="form-group">
                                <label>Depth (mm)</label>
                                <input type="number" name="pallet_depth_mm" min="1" placeholder="e.g. 1303">
                            </div>
                            <div class="form-group">
                                <label>Stack Height (mm)</label>
                                <input type="number" name="pallet_double_stacked_height_mm" min="1" placeholder="e.g. 2200">
                            </div>
                            <div class="form-group">
                                <label>Weight (kg)</label>
                                <input type="number" name="pallet_total_weight_kg" min="1" placeholder="e.g. 1200">
                            </div>
                        </div>
                    </div>
                </details>

                <div class="section-actions">
                    <button type="button" class="btn-back-step" onclick="goToStep(2)">
                        <span>&larr;</span> Back
                    </button>
                    <div style="display: flex; gap: 12px; align-items: center;">
                        <button type="button" class="btn-skip" onclick="submitForm()">Skip & Create Project</button>
                        <button type="submit" class="btn-submit">
                            Create Project <span>&#10003;</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </form>
</main>

<script>
let currentStep = 1;

function goToStep(step) {
    // Validate current step before moving forward
    if (step > currentStep && !validateStep(currentStep)) {
        return;
    }

    // Close current accordion
    document.querySelectorAll('.accordion-header').forEach(h => h.classList.remove('active'));
    document.querySelectorAll('.accordion-content').forEach(c => c.classList.remove('open'));

    // Open target accordion
    const targetSection = document.querySelector(`[data-section="${step}"]`);
    if (targetSection) {
        targetSection.querySelector('.accordion-header').classList.add('active');
        targetSection.querySelector('.accordion-content').classList.add('open');
    }

    // Update step indicator
    document.querySelectorAll('.step').forEach(s => {
        const stepNum = parseInt(s.dataset.step);
        s.classList.remove('active', 'completed');
        if (stepNum < step) {
            s.classList.add('completed');
        } else if (stepNum === step) {
            s.classList.add('active');
        }
    });

    // Update connectors
    document.querySelectorAll('.step-connector').forEach((c, i) => {
        if (i < step - 1) {
            c.classList.add('completed');
        } else {
            c.classList.remove('completed');
        }
    });

    // Update badges
    for (let i = 1; i < step; i++) {
        const badge = document.getElementById(`badge-${i}`);
        if (badge) {
            badge.classList.add('completed');
            badge.innerHTML = '&#10003;';
        }
    }

    currentStep = step;

    // Scroll to top of section
    targetSection?.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

function toggleAccordion(section) {
    goToStep(section);
}

function validateStep(step) {
    if (step === 1) {
        const projectName = document.getElementById('project_name').value.trim();
        const street = document.getElementById('street_address').value.trim();
        const city = document.getElementById('city').value.trim();
        const state = document.getElementById('state').value.trim();
        const zip = document.getElementById('zip_code').value.trim();
        const date = document.getElementById('estimated_completion_date').value;

        if (!projectName || !street || !city || !state || !zip || !date) {
            alert('Please fill in all required fields in Project Details.');
            return false;
        }

        <?php if ($role === 'global_admin'): ?>
        const accountId = document.getElementById('account_id').value;
        if (!accountId) {
            alert('Please select an account.');
            return false;
        }
        <?php endif; ?>
    }
    return true;
}

function submitForm() {
    if (validateStep(1)) {
        document.getElementById('projectForm').submit();
    }
}

function addWattageField() {
    const container = document.getElementById('wattage-container');
    const index = container.children.length;

    const entry = document.createElement('div');
    entry.className = 'wattage-entry';
    entry.innerHTML = `
        <div class="form-group">
            <label>Wattage (W)</label>
            <input type="number" name="wattages[${index}]" placeholder="e.g. 555" step="1">
        </div>
        <div class="form-group">
            <label>Quantity</label>
            <input type="number" name="quantities[${index}]" placeholder="e.g. 1000" step="1">
        </div>
        <button type="button" class="btn-remove" onclick="this.parentElement.remove()">Remove</button>
    `;
    container.appendChild(entry);
}

function handleManufacturerChange(select) {
    if (select.value === 'add_new') {
        window.location.href = 'add_manufacturer.php';
        return;
    }

    const locationSelect = document.getElementById('location_id');
    if (select.value) {
        locationSelect.disabled = false;
        // Fetch locations via AJAX
        fetch(`get_manufacturer_locations.php?manufacturer_id=${select.value}`)
            .then(r => r.json())
            .then(data => {
                locationSelect.innerHTML = '<option value="">Select Location</option>';
                data.forEach(loc => {
                    locationSelect.innerHTML += `<option value="${loc.id}">${loc.name || loc.city}</option>`;
                });
            })
            .catch(() => {
                locationSelect.innerHTML = '<option value="">No locations found</option>';
            });
    } else {
        locationSelect.disabled = true;
        locationSelect.innerHTML = '<option value="">Select a manufacturer first</option>';
    }
}

// Photo upload handling
(function() {
    const drop = document.getElementById('prePhotoDrop');
    const input = document.getElementById('prePhotoInput');
    const grid = document.getElementById('prePhotoGrid');
    const token = document.getElementById('tempPhotoToken');
    const orderInput = document.getElementById('tempPhotoOrder');

    if (!drop || !input) return;

    input.addEventListener('change', async () => {
        if (input.files?.length) {
            for (const file of input.files) {
                await uploadPhoto(file);
            }
            input.value = '';
        }
    });

    drop.addEventListener('dragover', e => { e.preventDefault(); drop.style.borderColor = '#488C9A'; });
    drop.addEventListener('dragleave', () => { drop.style.borderColor = ''; });
    drop.addEventListener('drop', async e => {
        e.preventDefault();
        drop.style.borderColor = '';
        if (e.dataTransfer?.files?.length) {
            for (const file of e.dataTransfer.files) {
                await uploadPhoto(file);
            }
        }
    });

    async function uploadPhoto(file) {
        const fd = new FormData();
        fd.append('file', file);
        fd.append('token', token.value);
        try {
            const res = await fetch('upload_temp_photo.php', { method: 'POST', body: fd });
            const data = await res.json();
            if (data.success) {
                addPhotoTile(data);
            }
        } catch (err) {
            console.error('Upload failed', err);
        }
    }

    function addPhotoTile(data) {
        const tile = document.createElement('div');
        tile.style.cssText = 'position:relative; border-radius:8px; overflow:hidden;';
        tile.innerHTML = `
            <img src="${data.path}" style="width:100%; height:80px; object-fit:cover;">
            <button type="button" onclick="this.parentElement.remove(); updatePhotoOrder();" style="position:absolute; top:4px; right:4px; background:rgba(0,0,0,0.5); color:#fff; border:none; border-radius:50%; width:24px; height:24px; cursor:pointer;">&times;</button>
        `;
        tile.dataset.name = data.name;
        grid.appendChild(tile);
        updatePhotoOrder();
    }

    window.updatePhotoOrder = function() {
        const names = Array.from(grid.querySelectorAll('[data-name]')).map(el => el.dataset.name);
        orderInput.value = names.join(',');
    };
})();

// Google Places Address Autocomplete
function initAddressAutocomplete() {
    const streetInput = document.getElementById('street_address');
    if (!streetInput) return;

    const options = {
        types: ['address'],
        componentRestrictions: { country: 'us' }
    };

    const autocomplete = new google.maps.places.Autocomplete(streetInput, options);

    autocomplete.addListener('place_changed', function() {
        const place = autocomplete.getPlace();
        if (!place.address_components) return;

        // Clear existing values
        document.getElementById('street_address').value = '';
        document.getElementById('city').value = '';
        document.getElementById('state').value = '';
        document.getElementById('zip_code').value = '';

        let streetNumber = '';
        let route = '';

        // Parse address components
        for (const component of place.address_components) {
            const type = component.types[0];

            switch (type) {
                case 'street_number':
                    streetNumber = component.long_name;
                    break;
                case 'route':
                    route = component.long_name;
                    break;
                case 'locality':
                    document.getElementById('city').value = component.long_name;
                    break;
                case 'administrative_area_level_1':
                    document.getElementById('state').value = component.short_name;
                    break;
                case 'postal_code':
                    document.getElementById('zip_code').value = component.long_name;
                    break;
            }
        }

        // Combine street number and route
        document.getElementById('street_address').value = (streetNumber + ' ' + route).trim();
    });
}

// Initialize when Google Maps loads
if (typeof google !== 'undefined' && google.maps) {
    initAddressAutocomplete();
}
</script>

<!-- Google Maps API with Places library -->
<script src="https://maps.googleapis.com/maps/api/js?key=<?php echo htmlspecialchars($google_maps_api_key); ?>&libraries=places&callback=initAddressAutocomplete" async defer></script>
</body>
</html>
