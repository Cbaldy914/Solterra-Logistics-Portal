<?php
session_name("logistics_session");
session_start();

// Ensure the user is an admin, global_admin, or customer_admin
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin','global_admin','customer_admin'])) {
    header("Location: unauthorized");
    exit();
}

require_once '../config.php';
require_once 'document_helpers.php';
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
$user_id = $_SESSION['user_id'];
$role = $_SESSION['role'];

// Prepare variables to hold user messages:
$successMessage = "";
$errorMessage   = "";

// If POST, process form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Gather form fields
        $project_name             = trim($_POST['project_name'] ?? '');
        $street_address           = trim($_POST['street_address'] ?? '');
        $city                     = trim($_POST['city'] ?? '');
        $state                    = trim($_POST['state'] ?? '');
        $zip_code                 = trim($_POST['zip_code'] ?? '');
        $estimated_completion_date= trim($_POST['estimated_completion_date'] ?? '');
        $solterra_fee             = isset($_POST['solterra_fee']) ? floatval($_POST['solterra_fee']) : 0.0000;
        $project_size             = isset($_POST['project_size']) && $_POST['project_size'] !== '' ? floatval($_POST['project_size']) : 0.0;

        // Site information fields (optional)
        $phone1                   = trim($_POST['phone1'] ?? '');
        $phone2                   = trim($_POST['phone2'] ?? '');
        $timezone                 = trim($_POST['timezone'] ?? 'America/New_York');
        $instructions             = trim($_POST['instructions'] ?? '');
        $additional_notes         = trim($_POST['additional_notes'] ?? '');

        // Site contact fields
        $site_contact_name        = trim($_POST['site_contact_name'] ?? '');
        $site_contact_email       = trim($_POST['site_contact_email'] ?? '');
        $site_contact_phone       = trim($_POST['site_contact_phone'] ?? '');

        // Appointment window (in minutes)
        $appointment_duration     = isset($_POST['appointment_duration']) && $_POST['appointment_duration'] !== '' ? (int)$_POST['appointment_duration'] : 30;

        // Validate required fields
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

        // Get existing image URL to see if we need to replace it
        $stmtOld = $conn->prepare("SELECT image_url, driver_handout_url FROM projects WHERE id = ?");
        $stmtOld->bind_param("i", $project_id);
        $stmtOld->execute();
        $stmtOld->bind_result($existing_image_url, $existing_handout_url);
        $stmtOld->fetch();
        $stmtOld->close();

        $image_url = $existing_image_url;
        $driver_handout_url = $existing_handout_url;

        // Handle site documents upload
        if (isset($_FILES['site_documents'])) {
            $site_doc_type = trim($_POST['site_doc_type'] ?? 'other');
            $site_doc_sub_type = trim($_POST['site_doc_sub_type'] ?? 'General');

            $names = (array)$_FILES['site_documents']['name'];
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

        // Update the project
        $stmtUpdate = $conn->prepare("
            UPDATE projects
            SET project_name = ?,
                project_size = ?,
                street_address = ?,
                city = ?,
                state = ?,
                zip_code = ?,
                estimated_completion_date = ?,
                solterra_fee = ?,
                phone1 = ?,
                phone2 = ?,
                timezone = ?,
                instructions = ?,
                additional_notes = ?,
                site_contact_name = ?,
                site_contact_email = ?,
                site_contact_phone = ?,
                appointment_duration = ?
            WHERE id = ?
        ");
        $stmtUpdate->bind_param(
            "sdsssssdssssssssii",
            $project_name,
            $project_size,
            $street_address,
            $city,
            $state,
            $zip_code,
            $estimated_completion_date,
            $solterra_fee,
            $phone1,
            $phone2,
            $timezone,
            $instructions,
            $additional_notes,
            $site_contact_name,
            $site_contact_email,
            $site_contact_phone,
            $appointment_duration,
            $project_id
        );

        if (!$stmtUpdate->execute()) {
            throw new Exception("Error updating project: " . $stmtUpdate->error);
        }
        $stmtUpdate->close();

        // Update site operating hours (delete existing and re-insert per day)
        $stmtDelHours = $conn->prepare("DELETE FROM site_operating_hours WHERE project_id = ?");
        $stmtDelHours->bind_param("i", $project_id);
        $stmtDelHours->execute();
        $stmtDelHours->close();

        $stmtHours = $conn->prepare("
            INSERT INTO site_operating_hours (project_id, day_of_week, start_time, end_time)
            VALUES (?, ?, ?, ?)
        ");
        if ($stmtHours) {
            // Insert for each day that has hours_start and hours_end set
            for ($day = 0; $day <= 6; $day++) {
                $start_key = "hours_start_$day";
                $end_key = "hours_end_$day";
                if (!empty($_POST[$start_key]) && !empty($_POST[$end_key])) {
                    $day_start = trim($_POST[$start_key]);
                    $day_end = trim($_POST[$end_key]);
                    $stmtHours->bind_param("iiss", $project_id, $day, $day_start, $day_end);
                    $stmtHours->execute();
                }
            }
            $stmtHours->close();
        }

        $successMessage = "Project updated successfully! <a href='project_overview?project_id=" . $project_id . "' style='color: #488C9A; text-decoration: underline;'>View Project</a>.";

    } catch (Exception $ex) {
        $errorMessage = $ex->getMessage();
    }
}

// GET request or after POST: fetch project data for display in the form
$stmt = $conn->prepare("SELECT * FROM projects WHERE id = ?");
$stmt->bind_param("i", $project_id);
$stmt->execute();
$result = $stmt->get_result();
$project = $result->fetch_assoc();
$stmt->close();

if (!$project) {
    die("Project not found.");
}

// Get site operating hours by day
$site_operating_hours = [];
$stmtHours = $conn->prepare("SELECT day_of_week, start_time, end_time FROM site_operating_hours WHERE project_id = ?");
if ($stmtHours) {
    $stmtHours->bind_param("i", $project_id);
    $stmtHours->execute();
    $result = $stmtHours->get_result();
    while ($row = $result->fetch_assoc()) {
        $site_operating_hours[$row['day_of_week']] = [
            'start' => $row['start_time'],
            'end' => $row['end_time']
        ];
    }
    $stmtHours->close();
}

// Get actual module quantities by wattage (from assigned modules)
$actual_modules = [];
$stmtActual = $conn->prepare("
    SELECT u.wattage, SUM(u.quantity) as total_quantity
    FROM unassigned_module_items u
    JOIN modules m ON u.unassigned_module_id = m.id
    WHERE m.project_id = ?
    GROUP BY u.wattage
    ORDER BY u.wattage ASC
");
$stmtActual->bind_param("i", $project_id);
$stmtActual->execute();
$resultActual = $stmtActual->get_result();
while ($row = $resultActual->fetch_assoc()) {
    $actual_modules[] = $row;
}
$stmtActual->close();

// Get module batches assigned to this project
$assigned_batches = [];
$stmtBatches = $conn->prepare("
    SELECT m.id as batch_id, m.vendor_name, u.wattage, SUM(u.quantity) as quantity
    FROM modules m
    JOIN unassigned_module_items u ON m.id = u.unassigned_module_id
    WHERE m.project_id = ?
    GROUP BY m.id, u.wattage
    ORDER BY m.id ASC, u.wattage ASC
");
$stmtBatches->bind_param("i", $project_id);
$stmtBatches->execute();
$resultBatches = $stmtBatches->get_result();
while ($row = $resultBatches->fetch_assoc()) {
    $assigned_batches[] = $row;
}
$stmtBatches->close();

// Get module batches for edit links
$module_batches = [];
$stmtModuleBatches = $conn->prepare("
    SELECT m.id, m.vendor_name,
           (SELECT SUM(u.quantity) FROM unassigned_module_items u WHERE u.unassigned_module_id = m.id) as total_modules,
           (SELECT GROUP_CONCAT(DISTINCT u.wattage ORDER BY u.wattage SEPARATOR ', ') FROM unassigned_module_items u WHERE u.unassigned_module_id = m.id) as wattages
    FROM modules m
    WHERE m.project_id = ?
    ORDER BY m.id ASC
");
$stmtModuleBatches->bind_param("i", $project_id);
$stmtModuleBatches->execute();
$resultModuleBatches = $stmtModuleBatches->get_result();
while ($row = $resultModuleBatches->fetch_assoc()) {
    $module_batches[] = $row;
}
$stmtModuleBatches->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Project - <?php echo htmlspecialchars($project['project_name']); ?></title>
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
            box-sizing: border-box;
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

        /* Current Photo Display */
        .current-photo {
            display: flex;
            align-items: center;
            gap: 16px;
            padding: 16px;
            background: #f8f9fa;
            border-radius: 12px;
            margin-bottom: 16px;
        }
        .current-photo img {
            width: 120px;
            height: 80px;
            object-fit: cover;
            border-radius: 8px;
            border: 1px solid rgba(72,140,154,0.15);
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

        /* Module Summary Section */
        .module-summary {
            background: #f8f9fa;
            border-radius: 12px;
            padding: 24px;
            margin-top: 20px;
        }
        .module-summary h3 {
            color: #293E4C;
            margin-bottom: 16px;
            font-size: 1.1rem;
        }
        .module-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 16px;
            margin-bottom: 20px;
        }
        .module-stat {
            background: white;
            padding: 16px;
            border-radius: 10px;
            border-left: 4px solid #488C9A;
            box-shadow: 0 2px 8px rgba(0,0,0,0.04);
        }
        .module-stat .wattage {
            font-size: 1.2em;
            font-weight: bold;
            color: #293E4C;
        }
        .module-stat .quantity {
            font-size: 1em;
            color: #488C9A;
            font-weight: 600;
        }
        .total-summary {
            background: linear-gradient(135deg, #e8f4f8 0%, #d1ecf1 100%);
            padding: 12px 16px;
            border-radius: 8px;
            text-align: center;
            font-weight: 600;
            color: #293E4C;
        }
        .info-note {
            background: #fff3cd;
            border: 1px solid #ffeaa7;
            border-radius: 8px;
            padding: 12px 16px;
            margin-top: 16px;
            font-size: 0.9em;
            color: #856404;
        }

        @media (max-width: 768px) {
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
            }
        }

        /* Site Documents Modal */
        .site-docs-modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 1000;
            justify-content: center;
            align-items: center;
        }
        .site-docs-modal.open {
            display: flex;
        }
        .site-docs-modal-content {
            background: #fff;
            border-radius: 16px;
            width: 90%;
            max-width: 500px;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
        }
        .site-docs-modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 20px 24px;
            border-bottom: 1px solid #e9ecef;
        }
        .site-docs-modal-header h3 {
            margin: 0;
            color: #293E4C;
        }
        .modal-close-btn {
            background: none;
            border: none;
            font-size: 24px;
            color: #6c757d;
            cursor: pointer;
        }
        .site-docs-modal-body {
            padding: 24px;
        }
        .site-docs-modal-footer {
            padding: 16px 24px;
            border-top: 1px solid #e9ecef;
            display: flex;
            justify-content: flex-end;
            gap: 12px;
        }
        .btn-modal-cancel {
            padding: 10px 20px;
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 8px;
            cursor: pointer;
        }
        .btn-modal-confirm {
            padding: 10px 20px;
            background: #488C9A;
            color: #fff;
            border: none;
            border-radius: 8px;
            cursor: pointer;
        }
        .file-drop-zone {
            border: 2px dashed #dee2e6;
            border-radius: 12px;
            padding: 30px;
            text-align: center;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        .file-drop-zone:hover {
            border-color: #488C9A;
            background: #f8f9fa;
        }
        .file-drop-zone .drop-icon {
            font-size: 2rem;
            display: block;
            margin-bottom: 8px;
        }
        .file-drop-zone .drop-text {
            color: #495057;
            display: block;
        }
        .file-drop-zone .drop-hint {
            color: #6c757d;
            font-size: 0.85rem;
            display: block;
            margin-top: 4px;
        }
        .selected-files-list {
            margin-top: 12px;
        }
        .selected-file-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 8px 12px;
            background: #f8f9fa;
            border-radius: 6px;
            margin-bottom: 6px;
        }
        .selected-file-item .file-name {
            font-size: 0.9rem;
            color: #293E4C;
        }
        .selected-file-item .file-size {
            font-size: 0.8rem;
            color: #6c757d;
            margin-left: 12px;
        }
        .selected-file-item .remove-file {
            color: #dc3545;
            cursor: pointer;
            font-size: 1.2rem;
            margin-left: 12px;
        }
        .site-docs-upload-area {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }
        .site-docs-upload-btn {
            padding: 14px 24px;
            background: #f8f9fa;
            border: 2px dashed #dee2e6;
            border-radius: 10px;
            color: #495057;
            font-size: 0.95rem;
            cursor: pointer;
            transition: all 0.2s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }
        .site-docs-upload-btn:hover {
            border-color: #488C9A;
            color: #488C9A;
        }
        #site-docs-list {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }
        .uploaded-doc-item {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 8px 12px;
            background: #e8f4f6;
            border-radius: 6px;
            font-size: 0.85rem;
            color: #293E4C;
        }
        .uploaded-doc-item .remove-doc {
            color: #dc3545;
            cursor: pointer;
            font-weight: bold;
        }

        /* Module batch links */
        .module-batch-links {
            display: flex;
            flex-direction: column;
            gap: 12px;
            margin-top: 20px;
        }
        .module-batch-link {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 16px 20px;
            background: #f8f9fa;
            border-radius: 10px;
            border: 1px solid #e9ecef;
            transition: all 0.2s ease;
        }
        .module-batch-link:hover {
            border-color: #488C9A;
            background: #fff;
        }
        .module-batch-link .batch-info {
            display: flex;
            flex-direction: column;
        }
        .module-batch-link .batch-name {
            font-weight: 600;
            color: #293E4C;
        }
        .module-batch-link .batch-details {
            font-size: 0.85rem;
            color: #6c757d;
        }
        .module-batch-link .batch-action {
            padding: 8px 16px;
            background: #488C9A;
            color: #fff;
            border-radius: 6px;
            text-decoration: none;
            font-size: 0.9rem;
            transition: background 0.2s ease;
        }
        .module-batch-link .batch-action:hover {
            background: #3a7a87;
        }
        .add-batch-btn {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 16px;
            background: #fff;
            border: 2px dashed #488C9A;
            border-radius: 10px;
            color: #488C9A;
            font-weight: 500;
            cursor: pointer;
            text-decoration: none;
            transition: all 0.2s ease;
        }
        .add-batch-btn:hover {
            background: #488C9A;
            color: #fff;
        }
    </style>
</head>
<body>
<?php include 'header.php'; ?>
<main>
    <?php require_once 'components/breadcrumbs.php'; echo slp_render_breadcrumbs(['current_label' => 'Edit Project', 'extra' => [['label' => 'Manage Projects', 'url' => 'manage_projects.php']]]); ?>

    <!-- Modern Page Header -->
    <div class="add-project-header">
        <div class="add-project-header-content">
            <div>
                <h1>Edit Project</h1>
                <p class="subtitle">Update <?php echo htmlspecialchars($project['project_name']); ?></p>
            </div>
            <div class="header-actions">
                <a href="project_overview?project_id=<?php echo $project_id; ?>" class="btn-back">&larr; Back to Project</a>
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
            <span class="step-tag optional">View Only</span>
        </div>
    </div>

    <form method="POST" action="" enctype="multipart/form-data" id="projectForm">
        <input type="hidden" name="project_id" value="<?php echo $project_id; ?>">

        <!-- Step 1: Project Details (Required) -->
        <div class="accordion-section" data-section="1">
            <div class="accordion-header active" onclick="toggleAccordion(1)">
                <h2><span class="step-badge" id="badge-1">1</span> Project Details <span class="step-tag required">Required</span></h2>
                <span class="accordion-toggle">&#9660;</span>
            </div>
            <div class="accordion-content open">
                <div class="section-description">
                    Update the essential information for your project. All fields in this section are required.
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Project Name<span class="required-star">*</span></label>
                        <input type="text" name="project_name" id="project_name" class="required-field" required value="<?php echo htmlspecialchars($project['project_name']); ?>">
                    </div>
                    <div class="form-group">
                        <label>Project Size (MW)<span class="optional-tag">(optional)</span></label>
                        <input type="number" name="project_size" id="project_size" step="0.001" min="0" value="<?php echo htmlspecialchars($project['project_size'] ?? ''); ?>">
                        <span class="help-text">Target size in megawatts</span>
                    </div>
                    <div class="form-group">
                        <label>Estimated Completion Date<span class="required-star">*</span></label>
                        <input type="date" name="estimated_completion_date" id="estimated_completion_date" class="required-field" required value="<?php echo htmlspecialchars($project['estimated_completion_date']); ?>">
                    </div>
                </div>

                <div class="form-group" style="margin-bottom: 20px;">
                    <label>Project Address<span class="required-star">*</span></label>
                    <div class="address-grid">
                        <div class="form-group">
                            <input type="text" name="street_address" id="street_address" class="required-field" required placeholder="Street Address" value="<?php echo htmlspecialchars($project['street_address'] ?? ''); ?>">
                        </div>
                        <div class="form-group">
                            <input type="text" name="city" id="city" class="required-field" required placeholder="City" value="<?php echo htmlspecialchars($project['city'] ?? ''); ?>">
                        </div>
                        <div class="form-group">
                            <input type="text" name="state" id="state" class="required-field" required placeholder="State" value="<?php echo htmlspecialchars($project['state'] ?? ''); ?>">
                        </div>
                        <div class="form-group">
                            <input type="text" name="zip_code" id="zip_code" class="required-field" required placeholder="Zip" value="<?php echo htmlspecialchars($project['zip_code'] ?? ''); ?>">
                        </div>
                    </div>
                </div>

                <div class="form-row single">
                    <div class="form-group">
                        <label>Project Photos<span class="optional-tag">(optional)</span></label>
                        <div class="current-photo">
                            <img src="<?php echo htmlspecialchars($project['image_url'] ?: 'pictures/project_default.png'); ?>" alt="Project Cover">
                            <a href="project_photos.php?project_id=<?php echo $project_id; ?>" style="display:inline-flex; gap:8px; align-items:center; padding:10px 14px; background: linear-gradient(135deg, #488C9A 0%, #3A6E7F 100%); color:#fff; text-decoration:none; border-radius:10px; font-weight: 500;">
                                Manage Project Photos
                            </a>
                        </div>
                        <span class="help-text">Arrange photos and choose the cover by dragging the first photo.</span>
                    </div>
                </div>

                <?php if ($role === 'global_admin'): ?>
                <div class="form-row single">
                    <div class="form-group">
                        <label>Solterra Fee<span class="optional-tag">(optional)</span></label>
                        <input type="number" name="solterra_fee" step="0.0001" value="<?php echo isset($project['solterra_fee']) ? htmlspecialchars($project['solterra_fee']) : '0.0000'; ?>">
                        <span class="help-text">Per-module fee for Solterra services</span>
                    </div>
                </div>
                <?php else: ?>
                <input type="hidden" name="solterra_fee" value="<?php echo isset($project['solterra_fee']) ? htmlspecialchars($project['solterra_fee']) : '0.0000'; ?>">
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
                    Update contact details and receiving hours for the project site.
                </div>

                <h4 class="form-subsection-title">Site Contact</h4>
                <div class="form-row">
                    <div class="form-group">
                        <label>Contact Name<span class="optional-tag">(optional)</span></label>
                        <input type="text" name="site_contact_name" value="<?php echo htmlspecialchars($project['site_contact_name'] ?? ''); ?>" placeholder="e.g. John Smith">
                    </div>
                    <div class="form-group">
                        <label>Contact Email<span class="optional-tag">(optional)</span></label>
                        <input type="email" name="site_contact_email" value="<?php echo htmlspecialchars($project['site_contact_email'] ?? ''); ?>" placeholder="e.g. john@example.com">
                    </div>
                    <div class="form-group">
                        <label>Contact Phone<span class="optional-tag">(optional)</span></label>
                        <input type="tel" name="site_contact_phone" value="<?php echo htmlspecialchars($project['site_contact_phone'] ?? ''); ?>" placeholder="e.g. 555-555-5555">
                    </div>
                </div>

                <h4 class="form-subsection-title">Receiving Schedule</h4>
                <div class="form-row">
                    <div class="form-group">
                        <label>Timezone</label>
                        <select name="timezone" id="timezone">
                            <?php
                            $timezones = [
                                'America/New_York' => 'Eastern',
                                'America/Chicago' => 'Central',
                                'America/Denver' => 'Mountain',
                                'America/Los_Angeles' => 'Pacific',
                                'UTC' => 'UTC'
                            ];
                            $current_timezone = $project['timezone'] ?? 'America/New_York';
                            foreach ($timezones as $tz => $label) {
                                $selected = ($tz === $current_timezone) ? 'selected' : '';
                                echo "<option value=\"$tz\" $selected>$label</option>";
                            }
                            ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Appointment Window<span class="optional-tag">(optional)</span></label>
                        <?php $current_appt = $project['appointment_duration'] ?? 30; ?>
                        <select name="appointment_duration">
                            <option value="15" <?php echo $current_appt == 15 ? 'selected' : ''; ?>>15 minutes</option>
                            <option value="30" <?php echo $current_appt == 30 ? 'selected' : ''; ?>>30 minutes</option>
                            <option value="45" <?php echo $current_appt == 45 ? 'selected' : ''; ?>>45 minutes</option>
                            <option value="60" <?php echo $current_appt == 60 ? 'selected' : ''; ?>>1 hour</option>
                            <option value="90" <?php echo $current_appt == 90 ? 'selected' : ''; ?>>1.5 hours</option>
                            <option value="120" <?php echo $current_appt == 120 ? 'selected' : ''; ?>>2 hours</option>
                        </select>
                        <span class="help-text">Duration of each delivery appointment slot</span>
                    </div>
                </div>

                <div class="form-row single">
                    <div class="form-group">
                        <label>Site Receiving Hours</label>
                        <div class="receiving-schedule-container">
                            <div class="schedule-quick-set">
                                <button type="button" class="quick-set-btn" onclick="setQuickSchedule('business')">Mon-Fri 8am-5pm</button>
                                <button type="button" class="quick-set-btn" onclick="setQuickSchedule('extended')">Mon-Sat 7am-6pm</button>
                                <button type="button" class="quick-set-btn" onclick="setQuickSchedule('24-7')">24/7</button>
                                <button type="button" class="quick-set-btn" onclick="setQuickSchedule('custom')">Custom</button>
                            </div>
                            <div class="daily-hours-grid" id="dailyHoursGrid">
                                <?php
                                $day_names = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
                                $day_abbr = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
                                for ($d = 0; $d <= 6; $d++):
                                    $has_hours = isset($site_operating_hours[$d]);
                                    $start_val = $has_hours ? $site_operating_hours[$d]['start'] : '08:00';
                                    $end_val = $has_hours ? $site_operating_hours[$d]['end'] : '17:00';
                                    $is_active = $has_hours;
                                    $disabled_attr = $is_active ? '' : 'disabled';
                                    $row_class = $is_active ? '' : 'disabled';
                                    $toggle_class = $is_active ? 'active' : '';
                                ?>
                                <div class="day-row <?php echo $row_class; ?>" data-day="<?php echo $d; ?>">
                                    <div class="day-name"><span><?php echo $day_names[$d]; ?></span><abbr><?php echo $day_abbr[$d]; ?></abbr></div>
                                    <div class="hours-inputs">
                                        <input type="time" name="hours_start_<?php echo $d; ?>" value="<?php echo htmlspecialchars($start_val); ?>" <?php echo $disabled_attr; ?>>
                                        <span>to</span>
                                        <input type="time" name="hours_end_<?php echo $d; ?>" value="<?php echo htmlspecialchars($end_val); ?>" <?php echo $disabled_attr; ?>>
                                    </div>
                                    <div class="day-toggle <?php echo $toggle_class; ?>" onclick="toggleDay(this, <?php echo $d; ?>)"></div>
                                </div>
                                <?php endfor; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <h4 class="form-subsection-title">Instructions</h4>
                <div class="form-row single">
                    <div class="form-group">
                        <label>Special Instructions<span class="optional-tag">(optional)</span></label>
                        <textarea name="instructions" placeholder="e.g. Gate code required, call ahead for access, etc."><?php echo htmlspecialchars($project['instructions'] ?? ''); ?></textarea>
                    </div>
                </div>

                <div class="form-row single">
                    <div class="form-group">
                        <label>Additional Notes<span class="optional-tag">(optional)</span></label>
                        <textarea name="additional_notes" placeholder="Any other relevant information..."><?php echo htmlspecialchars($project['additional_notes'] ?? ''); ?></textarea>
                    </div>
                </div>

                <h4 class="form-subsection-title">Site Documents</h4>
                <input type="hidden" name="site_doc_type" id="site_doc_type_hidden" value="site">
                <input type="hidden" name="site_doc_sub_type" id="site_doc_sub_type_hidden" value="">
                <div class="site-docs-upload-area">
                    <button type="button" class="site-docs-upload-btn" onclick="openSiteDocsModal()">
                        <i class="fas fa-cloud-upload-alt"></i> Upload Site Documents
                    </button>
                    <span class="help-text">Upload driver handouts, site maps, SOPs, and other site documents</span>
                    <div id="site-docs-list"></div>
                    <input type="file" name="site_documents[]" id="site_documents" multiple accept=".pdf,.doc,.docx,.jpg,.jpeg,.png,.xls,.xlsx" style="display:none">
                </div>

                <div class="section-actions">
                    <button type="button" class="btn-back-step" onclick="goToStep(1)">
                        <span>&larr;</span> Back
                    </button>
                    <div style="display: flex; gap: 12px; align-items: center;">
                        <button type="button" class="btn-continue" onclick="goToStep(3)">
                            Continue to Modules <span>&rarr;</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Step 3: Module Summary -->
        <div class="accordion-section" data-section="3">
            <div class="accordion-header" onclick="toggleAccordion(3)">
                <h2><span class="step-badge" id="badge-3">3</span> Module Summary</h2>
                <span class="accordion-toggle">&#9660;</span>
            </div>
            <div class="accordion-content">
                <div class="section-description">
                    Manage module batches assigned to this project. Click on a batch to edit it.
                </div>

                <div class="module-summary">
                    <?php if (!empty($actual_modules)): ?>
                        <h4 class="form-subsection-title">Module Quantities by Wattage</h4>
                        <div class="module-stats">
                            <?php foreach ($actual_modules as $module_row): ?>
                                <div class="module-stat">
                                    <div class="wattage"><?php echo number_format($module_row['wattage']); ?>W</div>
                                    <div class="quantity"><?php echo number_format($module_row['total_quantity']); ?> modules</div>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <div class="total-summary">
                            <strong>Total Project Modules:
                                <?php
                                $total_all = array_sum(array_column($actual_modules, 'total_quantity'));
                                echo number_format($total_all);
                                ?>
                            </strong>
                        </div>
                    <?php endif; ?>

                    <h4 class="form-subsection-title" style="margin-top: 24px;">Module Batches</h4>
                    <div class="module-batch-links">
                        <?php if (!empty($module_batches)): ?>
                            <?php foreach ($module_batches as $batch): ?>
                                <div class="module-batch-link">
                                    <div class="batch-info">
                                        <span class="batch-name"><?php echo htmlspecialchars($batch['vendor_name'] ?? 'Module Batch #' . $batch['id']); ?></span>
                                        <span class="batch-details">
                                            <?php echo number_format($batch['total_modules'] ?? 0); ?> modules
                                            <?php if (!empty($batch['wattages'])): ?>
                                                &bull; <?php echo htmlspecialchars($batch['wattages']); ?>W
                                            <?php endif; ?>
                                        </span>
                                    </div>
                                    <a href="edit_module_batch.php?batch_id=<?php echo $batch['id']; ?>" class="batch-action">
                                        <i class="fas fa-edit"></i> Edit
                                    </a>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div style="text-align: center; padding: 20px; color: #666; background: #f8f9fa; border-radius: 10px;">
                                <p style="margin: 0;">No module batches assigned to this project yet.</p>
                            </div>
                        <?php endif; ?>

                        <a href="add_module_batch.php?project_id=<?php echo $project_id; ?>" class="add-batch-btn">
                            <i class="fas fa-plus"></i> Add New Module Batch
                        </a>
                    </div>
                </div>

                <div class="section-actions">
                    <button type="button" class="btn-back-step" onclick="goToStep(2)">
                        <span>&larr;</span> Back
                    </button>
                    <div style="display: flex; gap: 12px; align-items: center;">
                        <button type="submit" class="btn-submit">
                            Update Project <span>&#10003;</span>
                        </button>
                    </div>
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
                        <div class="file-drop-zone" onclick="document.getElementById('modal_site_documents').click()">
                            <span class="drop-icon"><i class="fas fa-cloud-upload-alt"></i></span>
                            <span class="drop-text">Click to select files or drag & drop</span>
                            <span class="drop-hint">PDF, DOC, JPG, PNG, XLS (max 10MB each)</span>
                        </div>
                        <input type="file" id="modal_site_documents" multiple accept=".pdf,.doc,.docx,.jpg,.jpeg,.png,.xls,.xlsx" style="display:none" onchange="handleSiteDocsSelection(event)">
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
let selectedSiteFiles = [];

// Site Documents Modal Functions
function openSiteDocsModal() {
    document.getElementById('siteDocsModal').classList.add('open');
    document.getElementById('site_doc_sub_type').value = '';
    document.getElementById('selected-files-list').innerHTML = '';
    selectedSiteFiles = [];
}

function closeSiteDocsModal() {
    document.getElementById('siteDocsModal').classList.remove('open');
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
    const dt = new DataTransfer();
    selectedSiteFiles.forEach(f => dt.items.add(f));
    document.getElementById('modal_site_documents').files = dt.files;
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
}

// Receiving Schedule Functions
function toggleDay(toggleEl, dayNum) {
    const row = document.querySelector(`.day-row[data-day="${dayNum}"]`);
    const inputs = row.querySelectorAll('input[type="time"]');
    const isActive = toggleEl.classList.toggle('active');

    inputs.forEach(input => {
        input.disabled = !isActive;
        if (!isActive) {
            input.dataset.originalName = input.name;
            input.name = '';
        } else {
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
        document.querySelectorAll('.quick-set-btn').forEach(btn => btn.classList.remove('active'));
        document.querySelector('.quick-set-btn:last-child').classList.add('active');
        return;
    }

    const schedule = schedules[preset];
    if (!schedule) return;

    // Update all days
    for (let d = 0; d <= 6; d++) {
        const row = document.querySelector(`.day-row[data-day="${d}"]`);
        const toggle = row.querySelector('.day-toggle');
        const inputs = row.querySelectorAll('input[type="time"]');
        const shouldBeActive = schedule.days.includes(d);

        // Set toggle state
        if (shouldBeActive && !toggle.classList.contains('active')) {
            toggle.classList.add('active');
            row.classList.remove('disabled');
        } else if (!shouldBeActive && toggle.classList.contains('active')) {
            toggle.classList.remove('active');
            row.classList.add('disabled');
        }

        // Update inputs
        inputs.forEach((input, idx) => {
            input.disabled = !shouldBeActive;
            if (shouldBeActive) {
                input.value = idx === 0 ? schedule.start : schedule.end;
                if (!input.name && input.dataset.originalName) {
                    input.name = input.dataset.originalName;
                }
            } else {
                if (input.name) {
                    input.dataset.originalName = input.name;
                    input.name = '';
                }
            }
        });
    }

    document.querySelectorAll('.quick-set-btn').forEach(btn => btn.classList.remove('active'));
    event.target.classList.add('active');
}

function updateQuickSetButtons() {
    const enabledDays = [];
    let startTime = null;
    let endTime = null;
    let allSameTime = true;

    document.querySelectorAll('.day-row').forEach(row => {
        const day = parseInt(row.dataset.day);
        const toggle = row.querySelector('.day-toggle');
        if (toggle.classList.contains('active')) {
            enabledDays.push(day);
            const inputs = row.querySelectorAll('input[type="time"]');
            if (startTime === null) {
                startTime = inputs[0].value;
                endTime = inputs[1].value;
            } else {
                if (inputs[0].value !== startTime || inputs[1].value !== endTime) {
                    allSameTime = false;
                }
            }
        }
    });

    if (!allSameTime) {
        document.querySelectorAll('.quick-set-btn').forEach(btn => btn.classList.remove('active'));
        document.querySelector('.quick-set-btn:last-child').classList.add('active');
        return;
    }

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

// Initialize schedule form
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
    updateQuickSetButtons();
});

function goToStep(step) {
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
        s.classList.remove('active');
        if (stepNum === step) {
            s.classList.add('active');
        }
    });

    currentStep = step;

    // Scroll to top of section
    targetSection?.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

function toggleAccordion(section) {
    goToStep(section);
}

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
