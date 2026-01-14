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
        // Site receiving hours - will be stored in site_operating_hours table (per-day)
        $operating_hours = [];
        for ($day = 0; $day <= 6; $day++) {
            $start = trim($_POST["hours_start_{$day}"] ?? '');
            $end = trim($_POST["hours_end_{$day}"] ?? '');
            // Only add if both start and end are provided (day is enabled)
            if ($start !== '' && $end !== '') {
                $operating_hours[$day] = ['start' => $start, 'end' => $end];
            }
        }
        $reference_numbers         = ''; // Deprecated - using site_operating_hours table instead
        $instructions              = trim($_POST['instructions'] ?? '');
        $additional_notes          = trim($_POST['additional_notes'] ?? '');
        $driver_handout_url        = null; // Legacy column (now stored in project_documents)

        // Site contact fields
        $site_contact_name         = trim($_POST['site_contact_name'] ?? '');
        $site_contact_email        = trim($_POST['site_contact_email'] ?? '');
        $site_contact_phone        = trim($_POST['site_contact_phone'] ?? '');

        // Appointment window (in minutes)
        $appointment_duration      = isset($_POST['appointment_duration']) && $_POST['appointment_duration'] !== '' ? (int)$_POST['appointment_duration'] : 30;

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
        $cost_per_watt             = isset($_POST['cost_per_watt']) && $_POST['cost_per_watt'] !== '' ? floatval($_POST['cost_per_watt']) : null;

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
        $image_url = "pictures/project_default.png";

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
                driver_handout_url,
                site_contact_name,
                site_contact_email,
                site_contact_phone,
                appointment_duration
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        if (!$stmt) {
            throw new Exception("Error preparing project insert: " . $conn->error);
        }
        $stmt->bind_param(
            "isdssssssdssssssssssi",
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
            $driver_handout_url,
            $site_contact_name,
            $site_contact_email,
            $site_contact_phone,
            $appointment_duration
        );
        if (!$stmt->execute()) {
            throw new Exception("Error inserting project: " . $stmt->error);
        }
        $project_id = $stmt->insert_id;
        $stmt->close();

        // Insert site operating hours (per-day from form)
        // day_of_week: 0=Sunday, 1=Monday, 2=Tuesday, 3=Wednesday, 4=Thursday, 5=Friday, 6=Saturday
        $stmtHours = $conn->prepare("
            INSERT INTO site_operating_hours (project_id, day_of_week, start_time, end_time)
            VALUES (?, ?, ?, ?)
        ");
        if ($stmtHours && !empty($operating_hours)) {
            foreach ($operating_hours as $day => $hours) {
                $start = $hours['start'];
                $end = $hours['end'];
                $stmtHours->bind_param("iiss", $project_id, $day, $start, $end);
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

        // Save Site Documents to project_documents with selected type/sub-type
        if (isset($_FILES['site_documents'])) {
            $site_doc_type = trim($_POST['site_doc_type'] ?? 'other');
            $site_doc_sub_type = trim($_POST['site_doc_sub_type'] ?? 'General');

            // Normalize to arrays for iteration
            $names = (array)$_FILES['site_documents']['name'];
            $types = (array)$_FILES['site_documents']['type'];
            $tmps  = (array)$_FILES['site_documents']['tmp_name'];
            $errs  = (array)$_FILES['site_documents']['error'];
            $sizes = (array)$_FILES['site_documents']['size'];

            $allowed_ext = ['pdf','doc','docx','jpg','jpeg','png','xls','xlsx'];

            foreach ($names as $i => $orig) {
                if (!isset($errs[$i]) || $errs[$i] === UPLOAD_ERR_NO_FILE) continue;
                if ($errs[$i] !== UPLOAD_ERR_OK) {
                    throw new Exception('Site document upload error for file: ' . $orig);
                }

                $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
                if (!in_array($ext, $allowed_ext)) {
                    throw new Exception('Invalid file type for: ' . $orig);
                }

                $tmpName = $tmps[$i];
                $mime    = mime_content_type($tmpName);
                $doc = [
                    'project_id' => $project_id,
                    'document_type' => $site_doc_type ?: 'other',
                    'document_sub_type' => $site_doc_sub_type ?: 'General',
                    'original_name' => $orig,
                    'file_size' => $sizes[$i],
                    'mime_type' => $mime,
                    'uploaded_by' => $user_id,
                    'tmp_name' => $tmpName,
                    'description' => 'Site Document'
                ];
                saveDocumentToProjectDocuments($conn, $doc);
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

            // First, check if there are any valid wattage/quantity pairs
            // Skip module batch creation entirely if all entries are empty
            $valid_pairs = [];
            for ($i = 0; $i < count($wattages); $i++) {
                $w_val = trim($wattages[$i]);
                $q_val = trim($quantities[$i]);

                // Skip completely empty entries
                if ($w_val === '' && $q_val === '') {
                    continue;
                }

                // If one is filled but not the other, that's an error
                if ($w_val === '' || $q_val === '') {
                    throw new Exception("Both Wattage and Quantity must be provided for each entry, or leave both empty.");
                }

                $w_int = filter_var($w_val, FILTER_VALIDATE_INT);
                $q_int = filter_var($q_val, FILTER_VALIDATE_INT);

                if ($w_int === false || $q_int === false) {
                    throw new Exception("Wattage and Quantity must be valid integers.");
                }
                if ($w_int <= 0 || $q_int <= 0) {
                    throw new Exception("Wattage and Quantity must be positive integers.");
                }

                $valid_pairs[] = ['wattage' => $w_int, 'quantity' => $q_int];
            }

            // Only proceed with module batch creation if there are valid pairs
            if (count($valid_pairs) > 0) {
                // Create entries in project_wattage_orders table for project size calculation
                $stmt_wattage = $conn->prepare("
                    INSERT INTO project_wattage_orders (project_id, wattage, total_order)
                    VALUES (?, ?, ?)
                ");
                if (!$stmt_wattage) {
                    throw new Exception("Error preparing wattage orders insert: " . $conn->error);
                }

                foreach ($valid_pairs as $pair) {
                    $w_int = $pair['wattage'];
                    $q_int = $pair['quantity'];
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
                    module_notes, module_docs_url, cost_per_watt
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            if (!$stmt_module) {
                throw new Exception("Error preparing module batch insert: " . $conn->error);
            }
            $stmt_module->bind_param(
                "issiiiiiiiissiiiissd",
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
                $module_docs_url,
                $cost_per_watt
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

            foreach ($valid_pairs as $pair) {
                $w_int = $pair['wattage'];
                $q_int = $pair['quantity'];

                $stmt_item->bind_param("iii", $module_batch_id, $w_int, $q_int);
                if (!$stmt_item->execute()) {
                    throw new Exception("Error inserting module item (Wattage: {$w_int}W, Quantity: {$q_int}): " . $stmt_item->error);
                }
            }
            $stmt_item->close();
            } // End of if (count($valid_pairs) > 0)
        }

        // Set a success message to be displayed with the form below
        $successMessage = "Project added successfully! <a href='project_overview?project_id=" . $project_id . "' style='color: #488C9A; text-decoration: underline;'>View Project</a>.";

        // If modules were created, enhance the success message with module count
        if (isset($valid_pairs) && count($valid_pairs) > 0) {
            $totalModulesCreated = 0;
            foreach ($valid_pairs as $pair) {
                $totalModulesCreated += $pair['quantity'];
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

        /* Form Subsection Titles */
        .form-subsection-title {
            font-size: 0.95rem;
            font-weight: 600;
            color: #293E4C;
            margin: 24px 0 12px 0;
            padding-bottom: 8px;
            border-bottom: 2px solid #e9ecef;
        }
        .form-subsection-title:first-of-type {
            margin-top: 0;
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

        /* Daily Hours Schedule */
        .receiving-schedule-container {
            background: #f8f9fa;
            border-radius: 12px;
            padding: 20px;
            margin-top: 16px;
        }
        .schedule-quick-set {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-bottom: 16px;
            padding-bottom: 16px;
            border-bottom: 1px solid #e9ecef;
        }
        .quick-set-btn {
            padding: 8px 14px;
            background: #fff;
            border: 1px solid #dee2e6;
            border-radius: 6px;
            font-size: 0.85rem;
            color: #495057;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        .quick-set-btn:hover {
            border-color: #488C9A;
            color: #488C9A;
        }
        .quick-set-btn.active {
            background: #488C9A;
            border-color: #488C9A;
            color: #fff;
        }
        .daily-hours-grid {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }
        .day-row {
            display: grid;
            grid-template-columns: 100px 1fr auto;
            gap: 12px;
            align-items: center;
            padding: 10px 12px;
            background: #fff;
            border-radius: 8px;
            border: 1px solid #e9ecef;
        }
        .day-row.disabled {
            background: #f8f9fa;
            opacity: 0.6;
        }
        .day-row .day-name {
            font-weight: 500;
            color: #293E4C;
            font-size: 0.9rem;
        }
        .day-row .day-name abbr {
            display: none;
        }
        .day-row .hours-inputs {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .day-row .hours-inputs input[type="time"] {
            padding: 6px 8px;
            border: 1px solid #dee2e6;
            border-radius: 6px;
            font-size: 0.9rem;
            width: 110px;
        }
        .day-row .hours-inputs input[type="time"]:focus {
            outline: none;
            border-color: #488C9A;
        }
        .day-row .hours-inputs span {
            color: #6c757d;
            font-size: 0.85rem;
        }
        .day-row .day-toggle {
            width: 44px;
            height: 24px;
            background: #dee2e6;
            border-radius: 12px;
            position: relative;
            cursor: pointer;
            transition: background 0.2s ease;
        }
        .day-row .day-toggle.active {
            background: #488C9A;
        }
        .day-row .day-toggle::after {
            content: '';
            position: absolute;
            width: 20px;
            height: 20px;
            background: #fff;
            border-radius: 50%;
            top: 2px;
            left: 2px;
            transition: transform 0.2s ease;
            box-shadow: 0 1px 3px rgba(0,0,0,0.2);
        }
        .day-row .day-toggle.active::after {
            transform: translateX(20px);
        }
        @media (max-width: 600px) {
            .day-row {
                grid-template-columns: 80px 1fr auto;
                gap: 8px;
                padding: 8px 10px;
            }
            .day-row .day-name span {
                display: none;
            }
            .day-row .day-name abbr {
                display: inline;
            }
            .day-row .hours-inputs input[type="time"] {
                width: 90px;
                font-size: 0.8rem;
            }
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

        /* Module Setup uses component CSS from module_batch_section.php */

        /* Photo Upload - Profile Image Style */
        .photo-upload-container {
            display: flex;
            gap: 24px;
            align-items: flex-start;
        }
        .profile-image-preview {
            position: relative;
            width: 180px;
            height: 180px;
            border-radius: 12px;
            overflow: hidden;
            flex-shrink: 0;
            background: #f8f9fa;
            border: 2px solid #e9ecef;
        }
        .profile-image-preview img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        .profile-image-preview .profile-badge {
            position: absolute;
            bottom: 8px;
            left: 8px;
            background: rgba(41, 62, 76, 0.85);
            color: #fff;
            font-size: 0.7rem;
            font-weight: 600;
            padding: 4px 8px;
            border-radius: 4px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .profile-image-preview .change-photo-btn {
            position: absolute;
            bottom: 8px;
            right: 8px;
            background: rgba(72, 140, 154, 0.9);
            color: #fff;
            font-size: 0.75rem;
            font-weight: 500;
            padding: 6px 10px;
            border-radius: 6px;
            border: none;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        .profile-image-preview .change-photo-btn:hover {
            background: rgba(72, 140, 154, 1);
        }
        .photo-upload-info {
            flex: 1;
        }
        .photo-upload-info h4 {
            margin: 0 0 8px 0;
            font-size: 1rem;
            color: #293E4C;
        }
        .photo-upload-info p {
            margin: 0 0 12px 0;
            font-size: 0.9rem;
            color: #6c757d;
            line-height: 1.5;
        }
        .photo-upload-info .info-note {
            display: flex;
            align-items: flex-start;
            gap: 8px;
            padding: 10px 12px;
            background: #f0f8ff;
            border: 1px solid #b8daff;
            border-radius: 8px;
            font-size: 0.85rem;
            color: #0056b3;
        }
        .photo-upload-info .info-note i {
            flex-shrink: 0;
            margin-top: 2px;
        }
        .photo-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(100px, 1fr));
            gap: 10px;
            margin-top: 16px;
        }
        .photo-grid-item {
            position: relative;
            border-radius: 8px;
            overflow: hidden;
            aspect-ratio: 1;
            background: #f8f9fa;
        }
        .photo-grid-item img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        .photo-grid-item .photo-loading {
            position: absolute;
            inset: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #f8f9fa;
        }
        .photo-grid-item .photo-loading::after {
            content: '';
            width: 24px;
            height: 24px;
            border: 3px solid #e9ecef;
            border-top-color: #488C9A;
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
        }
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        .photo-grid-item .remove-photo-btn {
            position: absolute;
            top: 4px;
            right: 4px;
            background: rgba(0,0,0,0.6);
            color: #fff;
            border: none;
            border-radius: 50%;
            width: 22px;
            height: 22px;
            cursor: pointer;
            font-size: 14px;
            line-height: 1;
            opacity: 0;
            transition: opacity 0.2s ease;
        }
        .photo-grid-item:hover .remove-photo-btn {
            opacity: 1;
        }
        .add-more-photos-btn {
            display: flex;
            align-items: center;
            justify-content: center;
            aspect-ratio: 1;
            border: 2px dashed #dee2e6;
            border-radius: 8px;
            cursor: pointer;
            color: #6c757d;
            font-size: 1.5rem;
            transition: all 0.2s ease;
            background: #fafafa;
        }
        .add-more-photos-btn:hover {
            border-color: #488C9A;
            color: #488C9A;
            background: rgba(72, 140, 154, 0.05);
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

        /* Site Documents Upload Button & Modal */
        .site-docs-upload-area {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }
        .btn-upload-docs {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            padding: 14px 24px;
            background: linear-gradient(135deg, #488C9A 0%, #3d7a87 100%);
            color: #fff;
            border: none;
            border-radius: 10px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
            width: fit-content;
        }
        .btn-upload-docs:hover {
            background: linear-gradient(135deg, #3d7a87 0%, #326a75 100%);
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(72, 140, 154, 0.3);
        }
        .btn-upload-docs .upload-icon { font-size: 1.2rem; }
        .uploaded-docs-list {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }
        .uploaded-doc-item {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 12px;
            background: #e8f4f7;
            border-radius: 8px;
            font-size: 0.85rem;
            color: #293E4C;
        }
        .uploaded-doc-item .remove-doc {
            cursor: pointer;
            color: #dc3545;
            font-weight: bold;
        }

        /* Site Docs Modal */
        .site-docs-modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 9999;
            align-items: center;
            justify-content: center;
        }
        .site-docs-modal.open { display: flex; }
        .site-docs-modal-content {
            background: #fff;
            border-radius: 16px;
            width: 90%;
            max-width: 500px;
            max-height: 90vh;
            overflow: hidden;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
        }
        .site-docs-modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 20px 24px;
            border-bottom: 1px solid #e9ecef;
            background: #f8f9fa;
        }
        .site-docs-modal-header h3 { margin: 0; color: #293E4C; font-size: 1.1rem; }
        .modal-close-btn {
            background: none;
            border: none;
            font-size: 1.5rem;
            color: #6c757d;
            cursor: pointer;
            line-height: 1;
        }
        .modal-close-btn:hover { color: #293E4C; }
        .site-docs-modal-body { padding: 24px; }
        .site-docs-modal-body .form-group { margin-bottom: 20px; }
        .site-docs-modal-body .form-group:last-child { margin-bottom: 0; }
        .file-drop-zone {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 32px;
            border: 2px dashed #488C9A;
            border-radius: 12px;
            background: #f8fbfc;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        .file-drop-zone:hover {
            background: #e8f4f7;
            border-color: #3d7a87;
        }
        .file-drop-zone .drop-icon { font-size: 2rem; margin-bottom: 8px; }
        .file-drop-zone .drop-text { font-weight: 600; color: #293E4C; }
        .file-drop-zone .drop-hint { font-size: 0.8rem; color: #6c757d; margin-top: 4px; }
        .selected-files-list { margin-top: 12px; }
        .selected-file-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 10px 12px;
            background: #f8f9fa;
            border-radius: 8px;
            margin-bottom: 8px;
            font-size: 0.9rem;
        }
        .selected-file-item .file-name { color: #293E4C; }
        .selected-file-item .file-size { color: #6c757d; font-size: 0.8rem; }
        .selected-file-item .remove-file {
            color: #dc3545;
            cursor: pointer;
            font-weight: bold;
            padding: 2px 6px;
        }
        .site-docs-modal-footer {
            display: flex;
            justify-content: flex-end;
            gap: 12px;
            padding: 16px 24px;
            border-top: 1px solid #e9ecef;
            background: #f8f9fa;
        }
        .btn-modal-cancel {
            padding: 10px 20px;
            background: #fff;
            border: 1px solid #dee2e6;
            border-radius: 8px;
            color: #6c757d;
            font-weight: 500;
            cursor: pointer;
        }
        .btn-modal-cancel:hover { background: #f8f9fa; }
        .btn-modal-confirm {
            padding: 10px 20px;
            background: #488C9A;
            border: none;
            border-radius: 8px;
            color: #fff;
            font-weight: 600;
            cursor: pointer;
        }
        .btn-modal-confirm:hover { background: #3d7a87; }

        /* Module Setup Toggle - Enhanced */
        .module-setup-toggle {
            display: flex;
            background: #e9ecef;
            border-radius: 14px;
            padding: 6px;
            gap: 6px;
            margin-bottom: 24px;
        }
        .module-setup-toggle button {
            flex: 1;
            padding: 16px 28px;
            border-radius: 10px;
            border: none;
            background: transparent;
            color: #6c757d;
            font-weight: 700;
            font-size: 1rem;
            cursor: pointer;
            transition: all 0.25s ease;
        }
        .module-setup-toggle button:hover {
            background: rgba(255,255,255,0.6);
            color: #293E4C;
        }
        .module-setup-toggle button.active {
            background: #fff;
            color: #488C9A;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }
        .module-mode-content {
            display: none;
        }
        .module-mode-content.active {
            display: block;
        }
        .import-later-card {
            padding: 32px;
            background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%);
            border-radius: 16px;
            border: 2px solid #e9ecef;
            text-align: center;
        }
        .import-later-card .icon {
            font-size: 3rem;
            margin-bottom: 16px;
        }
        .import-later-card h4 {
            color: #293E4C;
            margin: 0 0 12px 0;
            font-size: 1.2rem;
        }
        .import-later-card p {
            color: #6c757d;
            margin: 0 0 8px 0;
            font-size: 0.95rem;
        }
        .import-later-card .features {
            display: flex;
            justify-content: center;
            gap: 24px;
            margin-top: 20px;
            flex-wrap: wrap;
        }
        .import-later-card .feature {
            display: flex;
            align-items: center;
            gap: 8px;
            color: #488C9A;
            font-size: 0.9rem;
            font-weight: 500;
        }
        .import-later-card .feature .check {
            color: #28a745;
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
                        <label>Project Size (MW)<span class="optional-tag">(optional)</span></label>
                        <input type="number" name="project_size" id="project_size" step="0.001" min="0" placeholder="e.g. 1.5">
                        <span class="help-text">Target size in megawatts</span>
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
                        <div class="photo-upload-container">
                            <div class="profile-image-preview" id="profileImagePreview">
                                <img src="pictures/project_default.png" alt="Project Photo" id="profileImageImg">
                                <span class="profile-badge">Profile</span>
                                <button type="button" class="change-photo-btn" onclick="document.getElementById('prePhotoInput').click()">
                                    <i class="fas fa-camera"></i> Change
                                </button>
                            </div>
                            <div class="photo-upload-info">
                                <h4>Project Profile Image</h4>
                                <p>This image will be displayed on the project card and overview. If you don't upload an image, a default placeholder will be used.</p>
                                <div class="info-note">
                                    <i class="fas fa-info-circle"></i>
                                    <span>You can upload additional photos from the Project Overview page after creating the project.</span>
                                </div>
                            </div>
                        </div>
                        <input type="file" id="prePhotoInput" accept="image/*" style="display:none" multiple>
                        <div class="photo-grid" id="prePhotoGrid">
                            <!-- Additional photos will appear here -->
                        </div>
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

                <h4 class="form-subsection-title">Site Contact</h4>
                <div class="form-row">
                    <div class="form-group">
                        <label>Contact Name<span class="optional-tag">(optional)</span></label>
                        <input type="text" name="site_contact_name" placeholder="e.g. John Smith">
                    </div>
                    <div class="form-group">
                        <label>Contact Email<span class="optional-tag">(optional)</span></label>
                        <input type="email" name="site_contact_email" placeholder="e.g. john@example.com">
                    </div>
                    <div class="form-group">
                        <label>Contact Phone<span class="optional-tag">(optional)</span></label>
                        <input type="tel" name="site_contact_phone" placeholder="e.g. 555-555-5555">
                    </div>
                </div>

                <h4 class="form-subsection-title">Receiving Schedule</h4>
                <div class="form-row">
                    <div class="form-group">
                        <label>Timezone</label>
                        <select name="timezone" id="timezone">
                            <option value="America/New_York" selected>Eastern</option>
                            <option value="America/Chicago">Central</option>
                            <option value="America/Denver">Mountain</option>
                            <option value="America/Los_Angeles">Pacific</option>
                            <option value="UTC">UTC</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Appointment Window<span class="optional-tag">(optional)</span></label>
                        <select name="appointment_duration">
                            <option value="15">15 minutes</option>
                            <option value="30" selected>30 minutes</option>
                            <option value="45">45 minutes</option>
                            <option value="60">1 hour</option>
                            <option value="90">1.5 hours</option>
                            <option value="120">2 hours</option>
                        </select>
                        <span class="help-text">Duration of each delivery appointment slot</span>
                    </div>
                </div>

                <div class="form-row single">
                    <div class="form-group">
                        <label>Site Receiving Hours</label>
                        <div class="receiving-schedule-container">
                            <div class="schedule-quick-set">
                                <button type="button" class="quick-set-btn active" onclick="setQuickSchedule('business')">Mon-Fri 8am-5pm</button>
                                <button type="button" class="quick-set-btn" onclick="setQuickSchedule('extended')">Mon-Sat 7am-6pm</button>
                                <button type="button" class="quick-set-btn" onclick="setQuickSchedule('24-7')">24/7</button>
                                <button type="button" class="quick-set-btn" onclick="setQuickSchedule('custom')">Custom</button>
                            </div>
                            <div class="daily-hours-grid" id="dailyHoursGrid">
                                <div class="day-row" data-day="0">
                                    <div class="day-name"><span>Sunday</span><abbr>Sun</abbr></div>
                                    <div class="hours-inputs">
                                        <input type="time" name="hours_start_0" value="08:00" disabled>
                                        <span>to</span>
                                        <input type="time" name="hours_end_0" value="17:00" disabled>
                                    </div>
                                    <div class="day-toggle" onclick="toggleDay(this, 0)"></div>
                                </div>
                                <div class="day-row" data-day="1">
                                    <div class="day-name"><span>Monday</span><abbr>Mon</abbr></div>
                                    <div class="hours-inputs">
                                        <input type="time" name="hours_start_1" value="08:00">
                                        <span>to</span>
                                        <input type="time" name="hours_end_1" value="17:00">
                                    </div>
                                    <div class="day-toggle active" onclick="toggleDay(this, 1)"></div>
                                </div>
                                <div class="day-row" data-day="2">
                                    <div class="day-name"><span>Tuesday</span><abbr>Tue</abbr></div>
                                    <div class="hours-inputs">
                                        <input type="time" name="hours_start_2" value="08:00">
                                        <span>to</span>
                                        <input type="time" name="hours_end_2" value="17:00">
                                    </div>
                                    <div class="day-toggle active" onclick="toggleDay(this, 2)"></div>
                                </div>
                                <div class="day-row" data-day="3">
                                    <div class="day-name"><span>Wednesday</span><abbr>Wed</abbr></div>
                                    <div class="hours-inputs">
                                        <input type="time" name="hours_start_3" value="08:00">
                                        <span>to</span>
                                        <input type="time" name="hours_end_3" value="17:00">
                                    </div>
                                    <div class="day-toggle active" onclick="toggleDay(this, 3)"></div>
                                </div>
                                <div class="day-row" data-day="4">
                                    <div class="day-name"><span>Thursday</span><abbr>Thu</abbr></div>
                                    <div class="hours-inputs">
                                        <input type="time" name="hours_start_4" value="08:00">
                                        <span>to</span>
                                        <input type="time" name="hours_end_4" value="17:00">
                                    </div>
                                    <div class="day-toggle active" onclick="toggleDay(this, 4)"></div>
                                </div>
                                <div class="day-row" data-day="5">
                                    <div class="day-name"><span>Friday</span><abbr>Fri</abbr></div>
                                    <div class="hours-inputs">
                                        <input type="time" name="hours_start_5" value="08:00">
                                        <span>to</span>
                                        <input type="time" name="hours_end_5" value="17:00">
                                    </div>
                                    <div class="day-toggle active" onclick="toggleDay(this, 5)"></div>
                                </div>
                                <div class="day-row" data-day="6">
                                    <div class="day-name"><span>Saturday</span><abbr>Sat</abbr></div>
                                    <div class="hours-inputs">
                                        <input type="time" name="hours_start_6" value="08:00" disabled>
                                        <span>to</span>
                                        <input type="time" name="hours_end_6" value="17:00" disabled>
                                    </div>
                                    <div class="day-toggle" onclick="toggleDay(this, 6)"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <h4 class="form-subsection-title">Instructions</h4>
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

                <h4 class="form-subsection-title">Site Documents</h4>
                <div class="site-docs-upload-area">
                    <button type="button" class="btn-upload-docs" onclick="openSiteDocsModal()">
                        <span class="upload-icon">📄</span>
                        <span class="upload-text">Upload Documents</span>
                    </button>
                    <div id="site-docs-list" class="uploaded-docs-list"></div>
                    <span class="help-text">Upload driver handouts, site maps, SOPs, and other site documents</span>
                </div>
                <!-- Hidden inputs for form submission -->
                <input type="hidden" name="site_doc_type" id="site_doc_type_hidden">
                <input type="hidden" name="site_doc_sub_type" id="site_doc_sub_type_hidden">

                <div class="section-actions">
                    <button type="button" class="btn-back-step" onclick="goToStep(1)">
                        <span>&larr;</span> Back
                    </button>
                    <button type="button" class="btn-continue" onclick="goToStep(3)">
                        Continue to Modules <span>&rarr;</span>
                    </button>
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
                    Choose how you want to set up modules for this project.
                </div>

                <div class="module-setup-toggle">
                    <button type="button" class="active" onclick="setModuleMode('import')" id="btn-import-mode">Import Schedule Later</button>
                    <button type="button" onclick="setModuleMode('manual')" id="btn-manual-mode">Manual Setup</button>
                </div>

                <!-- Import Later Mode (Default) -->
                <div class="module-mode-content active" id="mode-import">
                    <div class="import-later-card">
                        <div class="icon">&#128230;</div>
                        <h4>You're All Set!</h4>
                        <p>Create your project now and import the manufacturer's shipping schedule later.</p>
                        <p style="font-size: 0.85rem;">You can add modules anytime from the Project Overview page.</p>
                        <div class="features">
                            <span class="feature"><span class="check">&#10003;</span> Auto-creates pallets from BOL data</span>
                            <span class="feature"><span class="check">&#10003;</span> Links deliveries automatically</span>
                            <span class="feature"><span class="check">&#10003;</span> Tracks serial numbers</span>
                        </div>
                    </div>
                </div>

                <!-- Manual Setup Mode -->
                <div class="module-mode-content" id="mode-manual">
                    <?php
                    // Include the shared module batch section component
                    $prefManufacturerId = null;
                    $prefLocationId = null;
                    $existingWattages = [];
                    $module = null; // No existing module data for new project
                    include __DIR__ . '/components/module_batch_section.php';
                    ?>
                </div>

                <div class="section-actions">
                    <button type="button" class="btn-back-step" onclick="goToStep(2)">
                        <span>&larr;</span> Back
                    </button>
                    <button type="submit" class="btn-submit" id="btn-create-project">
                        Create Project <span>&#10003;</span>
                    </button>
                </div>
            </div>
        </div>

        <!-- Site Documents Upload Modal -->
        <div id="siteDocsModal" class="site-docs-modal">
            <div class="site-docs-modal-content">
                <div class="site-docs-modal-header">
                    <h3>Upload Site Documents</h3>
                    <button type="button" class="modal-close-btn" onclick="closeSiteDocsModal()">&times;</button>
                </div>
                <div class="site-docs-modal-body">
                    <input type="hidden" id="site_doc_type" value="site">
                    <div class="form-group">
                        <label>Document Sub-Type <span class="required-star">*</span></label>
                        <select id="site_doc_sub_type">
                            <option value="">Select sub-type...</option>
                            <option value="Delivery SOP">Delivery SOP / Driver Handout</option>
                            <option value="Site Map">Site Map</option>
                            <option value="Safety Document">Safety Document</option>
                            <option value="Permit">Permit</option>
                            <option value="Other">Other</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Select Files</label>
                        <div class="file-drop-zone" onclick="document.getElementById('site_documents').click()">
                            <span class="drop-icon">📁</span>
                            <span class="drop-text">Click to select files or drag & drop</span>
                            <span class="drop-hint">PDF, DOC, JPG, PNG, XLS (max 10MB each)</span>
                        </div>
                        <input type="file" name="site_documents[]" id="site_documents" multiple accept=".pdf,.doc,.docx,.jpg,.jpeg,.png,.xls,.xlsx" style="display:none" onchange="handleSiteDocsSelection(event)">
                        <div id="selected-files-list" class="selected-files-list"></div>
                    </div>
                </div>
                <div class="site-docs-modal-footer">
                    <button type="button" class="btn-modal-cancel" onclick="closeSiteDocsModal()">Cancel</button>
                    <button type="button" class="btn-modal-confirm" onclick="confirmSiteDocs()">Add Documents</button>
                </div>
            </div>
        </div>
    </form>
</main>

<script>
let currentStep = 1;

// ========== Receiving Schedule Functions ==========
function toggleDay(toggleEl, dayNum) {
    const row = document.querySelector(`.day-row[data-day="${dayNum}"]`);
    const inputs = row.querySelectorAll('input[type="time"]');
    const isActive = toggleEl.classList.toggle('active');

    inputs.forEach(input => {
        input.disabled = !isActive;
        if (!isActive) {
            // Clear the name attribute so disabled days don't submit
            input.dataset.originalName = input.name;
            input.name = '';
        } else {
            // Restore the name attribute
            if (input.dataset.originalName) {
                input.name = input.dataset.originalName;
            }
        }
    });

    row.classList.toggle('disabled', !isActive);
    updateQuickSetButtons();
}

function setQuickSchedule(preset) {
    const schedules = {
        'business': {
            days: [1, 2, 3, 4, 5],
            start: '08:00',
            end: '17:00'
        },
        'extended': {
            days: [1, 2, 3, 4, 5, 6],
            start: '07:00',
            end: '18:00'
        },
        '24-7': {
            days: [0, 1, 2, 3, 4, 5, 6],
            start: '00:00',
            end: '23:59'
        },
        'custom': null
    };

    if (preset === 'custom') {
        // Just highlight the custom button, don't change anything
        document.querySelectorAll('.quick-set-btn').forEach(btn => btn.classList.remove('active'));
        document.querySelector('.quick-set-btn:last-child').classList.add('active');
        return;
    }

    const schedule = schedules[preset];
    if (!schedule) return;

    // Reset all days first
    for (let day = 0; day <= 6; day++) {
        const row = document.querySelector(`.day-row[data-day="${day}"]`);
        const toggle = row.querySelector('.day-toggle');
        const inputs = row.querySelectorAll('input[type="time"]');
        const isEnabled = schedule.days.includes(day);

        // Set toggle state
        toggle.classList.toggle('active', isEnabled);
        row.classList.toggle('disabled', !isEnabled);

        // Set input values and enabled state
        inputs.forEach((input, idx) => {
            input.disabled = !isEnabled;
            if (idx === 0) input.value = schedule.start;
            if (idx === 1) input.value = schedule.end;

            if (!isEnabled) {
                input.dataset.originalName = input.name;
                input.name = '';
            } else {
                const baseName = idx === 0 ? `hours_start_${day}` : `hours_end_${day}`;
                input.name = baseName;
            }
        });
    }

    // Update button states
    document.querySelectorAll('.quick-set-btn').forEach(btn => btn.classList.remove('active'));
    event.target.classList.add('active');
}

function updateQuickSetButtons() {
    // Check if current schedule matches any preset
    const rows = document.querySelectorAll('.day-row');
    let enabledDays = [];
    let startTime = null;
    let endTime = null;

    rows.forEach(row => {
        const day = parseInt(row.dataset.day);
        const toggle = row.querySelector('.day-toggle');
        if (toggle.classList.contains('active')) {
            enabledDays.push(day);
            const inputs = row.querySelectorAll('input[type="time"]');
            if (!startTime) startTime = inputs[0].value;
            if (!endTime) endTime = inputs[1].value;
        }
    });

    // Check against presets
    const isBusiness = JSON.stringify(enabledDays.sort()) === JSON.stringify([1,2,3,4,5]) &&
                       startTime === '08:00' && endTime === '17:00';
    const isExtended = JSON.stringify(enabledDays.sort()) === JSON.stringify([1,2,3,4,5,6]) &&
                       startTime === '07:00' && endTime === '18:00';
    const is247 = JSON.stringify(enabledDays.sort()) === JSON.stringify([0,1,2,3,4,5,6]) &&
                  startTime === '00:00' && endTime === '23:59';

    document.querySelectorAll('.quick-set-btn').forEach(btn => btn.classList.remove('active'));

    if (isBusiness) {
        document.querySelector('.quick-set-btn:nth-child(1)').classList.add('active');
    } else if (isExtended) {
        document.querySelector('.quick-set-btn:nth-child(2)').classList.add('active');
    } else if (is247) {
        document.querySelector('.quick-set-btn:nth-child(3)').classList.add('active');
    } else {
        document.querySelector('.quick-set-btn:nth-child(4)').classList.add('active');
    }
}

// Initialize schedule form - disable inputs for non-active days
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.day-row').forEach(row => {
        const toggle = row.querySelector('.day-toggle');
        const inputs = row.querySelectorAll('input[type="time"]');
        const isActive = toggle.classList.contains('active');

        if (!isActive) {
            inputs.forEach(input => {
                input.dataset.originalName = input.name;
                input.name = '';
            });
        }
    });
});

let selectedSiteFiles = [];

function openSiteDocsModal() {
    document.getElementById('siteDocsModal').classList.add('open');
    document.getElementById('site_doc_sub_type').value = '';
    document.getElementById('selected-files-list').innerHTML = '';
    selectedSiteFiles = [];
}

function closeSiteDocsModal() {
    document.getElementById('siteDocsModal').classList.remove('open');
}

// Sub-types are now static in HTML - this function is no longer needed
function updateSiteDocSubTypes() {
    return;
}

function handleSiteDocsSelection(event) {
    const files = Array.from(event.target.files);
    selectedSiteFiles = files;
    renderSelectedFiles();
}

function renderSelectedFiles() {
    const container = document.getElementById('selected-files-list');
    container.innerHTML = '';

    selectedSiteFiles.forEach((file, index) => {
        const size = (file.size / 1024).toFixed(1) + ' KB';
        const div = document.createElement('div');
        div.className = 'selected-file-item';
        div.innerHTML = `
            <span class="file-name">${file.name}</span>
            <span class="file-size">${size}</span>
            <span class="remove-file" onclick="removeSiteFile(${index})">&times;</span>
        `;
        container.appendChild(div);
    });
}

function removeSiteFile(index) {
    selectedSiteFiles.splice(index, 1);
    renderSelectedFiles();
    // Update the file input
    const dt = new DataTransfer();
    selectedSiteFiles.forEach(f => dt.items.add(f));
    document.getElementById('site_documents').files = dt.files;
}

function confirmSiteDocs() {
    const docType = document.getElementById('site_doc_type').value || 'site';
    const subType = document.getElementById('site_doc_sub_type').value;

    if (!subType) {
        alert('Please select a document sub-type');
        return;
    }

    if (selectedSiteFiles.length === 0) {
        alert('Please select at least one file');
        return;
    }

    // Store type and sub-type in hidden fields
    document.getElementById('site_doc_type_hidden').value = docType;
    document.getElementById('site_doc_sub_type_hidden').value = subType;

    // Update the file input with selected files
    const dt = new DataTransfer();
    selectedSiteFiles.forEach(f => dt.items.add(f));
    document.getElementById('site_documents').files = dt.files;

    // Update the visible list
    const listContainer = document.getElementById('site-docs-list');
    listContainer.innerHTML = '';
    selectedSiteFiles.forEach((file, index) => {
        const div = document.createElement('div');
        div.className = 'uploaded-doc-item';
        div.innerHTML = `
            <span>${file.name}</span>
            <span class="remove-doc" onclick="removeUploadedDoc(${index})">&times;</span>
        `;
        listContainer.appendChild(div);
    });

    closeSiteDocsModal();
}

function removeUploadedDoc(index) {
    selectedSiteFiles.splice(index, 1);
    const dt = new DataTransfer();
    selectedSiteFiles.forEach(f => dt.items.add(f));
    document.getElementById('site_documents').files = dt.files;

    // Re-render the list
    const listContainer = document.getElementById('site-docs-list');
    listContainer.innerHTML = '';
    selectedSiteFiles.forEach((file, i) => {
        const div = document.createElement('div');
        div.className = 'uploaded-doc-item';
        div.innerHTML = `
            <span>${file.name}</span>
            <span class="remove-doc" onclick="removeUploadedDoc(${i})">&times;</span>
        `;
        listContainer.appendChild(div);
    });

    if (selectedSiteFiles.length === 0) {
        document.getElementById('site_doc_type_hidden').value = '';
        document.getElementById('site_doc_sub_type_hidden').value = '';
    }
}

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

// Module mode uses the shared component from module_batch_section.php
// Functions like mb_addWattageField, mb_handleManufacturerChange are provided by the component

let currentModuleMode = 'import';

function setModuleMode(mode) {
    currentModuleMode = mode;

    // Update toggle buttons
    document.getElementById('btn-import-mode').classList.toggle('active', mode === 'import');
    document.getElementById('btn-manual-mode').classList.toggle('active', mode === 'manual');

    // Show/hide content
    document.getElementById('mode-import').classList.toggle('active', mode === 'import');
    document.getElementById('mode-manual').classList.toggle('active', mode === 'manual');

    // The module_batch_section.php component already initializes a wattage field on DOMContentLoaded
}

// Photo upload handling
(function() {
    const profilePreview = document.getElementById('profileImagePreview');
    const profileImg = document.getElementById('profileImageImg');
    const input = document.getElementById('prePhotoInput');
    const grid = document.getElementById('prePhotoGrid');
    const token = document.getElementById('tempPhotoToken');
    const orderInput = document.getElementById('tempPhotoOrder');
    const defaultImageSrc = 'pictures/project_default.png';
    let uploadedPhotos = [];
    let isFirstPhoto = true;

    if (!input) return;

    input.addEventListener('change', async () => {
        if (input.files?.length) {
            for (const file of input.files) {
                await uploadPhoto(file);
            }
            input.value = '';
        }
    });

    // Drag and drop on profile preview
    if (profilePreview) {
        profilePreview.addEventListener('dragover', e => {
            e.preventDefault();
            profilePreview.style.borderColor = '#488C9A';
        });
        profilePreview.addEventListener('dragleave', () => {
            profilePreview.style.borderColor = '';
        });
        profilePreview.addEventListener('drop', async e => {
            e.preventDefault();
            profilePreview.style.borderColor = '';
            if (e.dataTransfer?.files?.length) {
                for (const file of e.dataTransfer.files) {
                    await uploadPhoto(file);
                }
            }
        });
    }

    async function uploadPhoto(file) {
        // Show loading state
        const tileId = 'photo-' + Date.now();
        const isFirst = uploadedPhotos.length === 0;

        if (isFirst) {
            // Show loading on profile preview
            profileImg.style.opacity = '0.5';
        }

        // Add loading tile to grid for additional photos
        if (!isFirst) {
            addLoadingTile(tileId);
        }

        const fd = new FormData();
        fd.append('file', file);
        fd.append('token', token.value);

        try {
            const res = await fetch('upload_temp_photo.php', { method: 'POST', body: fd });
            const data = await res.json();
            if (data.success) {
                uploadedPhotos.push({ name: data.name, path: data.path });

                if (isFirst) {
                    // Update profile preview with first photo
                    updateProfileImage(data.path);
                } else {
                    // Replace loading tile with actual photo
                    replaceLoadingTile(tileId, data);
                }

                updatePhotoOrder();
                updateAddMoreButton();
            } else {
                // Remove loading tile on error
                removeLoadingTile(tileId);
                profileImg.style.opacity = '1';
            }
        } catch (err) {
            console.error('Upload failed', err);
            removeLoadingTile(tileId);
            profileImg.style.opacity = '1';
        }
    }

    function updateProfileImage(path) {
        const tempImg = new Image();
        tempImg.onload = function() {
            profileImg.src = path;
            profileImg.style.opacity = '1';
        };
        tempImg.onerror = function() {
            profileImg.src = defaultImageSrc;
            profileImg.style.opacity = '1';
        };
        tempImg.src = path;
    }

    function addLoadingTile(tileId) {
        const tile = document.createElement('div');
        tile.className = 'photo-grid-item';
        tile.id = tileId;
        tile.innerHTML = '<div class="photo-loading"></div>';

        // Insert before the add more button if it exists
        const addMoreBtn = grid.querySelector('.add-more-photos-btn');
        if (addMoreBtn) {
            grid.insertBefore(tile, addMoreBtn);
        } else {
            grid.appendChild(tile);
        }
    }

    function replaceLoadingTile(tileId, data) {
        const tile = document.getElementById(tileId);
        if (!tile) return;

        const img = document.createElement('img');
        img.onload = function() {
            tile.innerHTML = '';
            tile.appendChild(img);
            const removeBtn = document.createElement('button');
            removeBtn.type = 'button';
            removeBtn.className = 'remove-photo-btn';
            removeBtn.innerHTML = '&times;';
            removeBtn.onclick = () => removePhoto(data.name);
            tile.appendChild(removeBtn);
        };
        img.onerror = function() {
            tile.remove();
        };
        img.src = data.path;
        tile.dataset.name = data.name;
    }

    function removeLoadingTile(tileId) {
        const tile = document.getElementById(tileId);
        if (tile) tile.remove();
    }

    function removePhoto(name) {
        const index = uploadedPhotos.findIndex(p => p.name === name);
        if (index === -1) return;

        uploadedPhotos.splice(index, 1);

        // Remove tile from grid
        const tile = grid.querySelector(`[data-name="${name}"]`);
        if (tile) tile.remove();

        // If we removed the first photo, update profile preview
        if (index === 0) {
            if (uploadedPhotos.length > 0) {
                // Move the next photo to be the profile
                updateProfileImage(uploadedPhotos[0].path);
                // Remove it from grid since it's now the profile
                const nextTile = grid.querySelector(`[data-name="${uploadedPhotos[0].name}"]`);
                if (nextTile) nextTile.remove();
            } else {
                // No more photos, show default
                profileImg.src = defaultImageSrc;
            }
        }

        updatePhotoOrder();
        updateAddMoreButton();
    }

    function updateAddMoreButton() {
        // Add the "add more" button if there are photos and no button exists
        let addMoreBtn = grid.querySelector('.add-more-photos-btn');

        if (uploadedPhotos.length > 0 && !addMoreBtn) {
            addMoreBtn = document.createElement('div');
            addMoreBtn.className = 'add-more-photos-btn';
            addMoreBtn.innerHTML = '+';
            addMoreBtn.onclick = () => input.click();
            grid.appendChild(addMoreBtn);
        } else if (uploadedPhotos.length === 0 && addMoreBtn) {
            addMoreBtn.remove();
        }
    }

    window.updatePhotoOrder = function() {
        orderInput.value = uploadedPhotos.map(p => p.name).join(',');
    };

    window.removePhoto = removePhoto;
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
