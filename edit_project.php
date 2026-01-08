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
        $receiving_hours_start    = trim($_POST['receiving_hours_start'] ?? '08:00');
        $receiving_hours_end      = trim($_POST['receiving_hours_end'] ?? '17:00');
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
            "sdsssssdsssssssssii",
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

        // Update site operating hours (delete existing and re-insert for Mon-Fri)
        $stmtDelHours = $conn->prepare("DELETE FROM site_operating_hours WHERE project_id = ?");
        $stmtDelHours->bind_param("i", $project_id);
        $stmtDelHours->execute();
        $stmtDelHours->close();

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

// Get site operating hours (use first weekday found)
$receiving_hours_start = '08:00';
$receiving_hours_end = '17:00';
$stmtHours = $conn->prepare("SELECT start_time, end_time FROM site_operating_hours WHERE project_id = ? AND day_of_week BETWEEN 1 AND 5 LIMIT 1");
if ($stmtHours) {
    $stmtHours->bind_param("i", $project_id);
    $stmtHours->execute();
    $stmtHours->bind_result($start_time, $end_time);
    if ($stmtHours->fetch()) {
        $receiving_hours_start = $start_time;
        $receiving_hours_end = $end_time;
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
                        <select name="timezone">
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
                        <label>Site Receiving Hours</label>
                        <div class="hours-grid">
                            <div class="form-group">
                                <label style="font-size: 0.85rem; color: #6c757d;">Opens</label>
                                <input type="time" name="receiving_hours_start" value="<?php echo htmlspecialchars($receiving_hours_start); ?>">
                            </div>
                            <div class="form-group">
                                <label style="font-size: 0.85rem; color: #6c757d;">Closes</label>
                                <input type="time" name="receiving_hours_end" value="<?php echo htmlspecialchars($receiving_hours_end); ?>">
                            </div>
                        </div>
                        <span class="help-text">Hours will be set for Monday-Friday</span>
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
                <div class="form-row">
                    <div class="form-group">
                        <label>Document Type<span class="optional-tag">(optional)</span></label>
                        <select name="site_doc_type" id="site_doc_type" onchange="updateSiteDocSubTypes()">
                            <option value="">Select document type...</option>
                            <option value="shipments">Shipments</option>
                            <option value="warehousing">Warehousing</option>
                            <option value="other">Other</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Sub-Type<span class="optional-tag">(optional)</span></label>
                        <select name="site_doc_sub_type" id="site_doc_sub_type" disabled>
                            <option value="">Select type first...</option>
                        </select>
                    </div>
                </div>
                <div class="form-row single">
                    <div class="form-group">
                        <label>Upload Documents<span class="optional-tag">(optional)</span></label>
                        <input type="file" name="site_documents[]" id="site_documents" multiple accept=".pdf,.doc,.docx,.jpg,.jpeg,.png,.xls,.xlsx">
                        <span class="help-text">Upload site documents like driver handouts, site maps, SOPs, etc. (PDF, DOC, JPG, PNG, XLS - max 10MB each)</span>
                    </div>
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

        <!-- Step 3: Module Summary (View Only) -->
        <div class="accordion-section" data-section="3">
            <div class="accordion-header" onclick="toggleAccordion(3)">
                <h2><span class="step-badge" id="badge-3">3</span> Module Summary <span class="step-tag optional">View Only</span></h2>
                <span class="accordion-toggle">&#9660;</span>
            </div>
            <div class="accordion-content">
                <div class="section-description">
                    View the modules assigned to this project. To add or edit modules, use the Module Batches management from the Project Overview page.
                </div>

                <div class="module-summary">
                    <?php if (!empty($actual_modules)): ?>
                        <h3>Module Quantities by Wattage</h3>
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
                    <?php else: ?>
                        <div style="text-align: center; padding: 40px; color: #666;">
                            <h3 style="color: #293E4C; margin-bottom: 12px;">No Module Batches Assigned</h3>
                            <p>This project doesn't have any module batches assigned to it yet.</p>
                            <a href="add_module_batch.php?project_id=<?php echo $project_id; ?>" style="color: #488C9A; text-decoration: none; font-weight: 600;">
                                &rarr; Add Module Batch
                            </a>
                        </div>
                    <?php endif; ?>

                    <div class="info-note">
                        <strong>Note:</strong> Module quantities are automatically calculated from batches assigned to this project.
                        To modify module quantities, edit the individual module batches from the
                        <a href="project_overview?project_id=<?php echo $project_id; ?>" style="color: #856404; font-weight: 600;">Project Overview</a>.
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
    </form>
</main>

<script>
let currentStep = 1;

// Site document type sub-types mapping
const siteDocSubTypes = {
    'shipments': [
        { value: 'Delivery SOP', label: 'Delivery SOP / Driver Handout' },
        { value: 'Arrival Notice', label: 'Arrival Notice' },
        { value: 'Site Map', label: 'Site Map' }
    ],
    'warehousing': [
        { value: 'Inventory Report', label: 'Inventory Report' },
        { value: 'Quote', label: 'Quote' }
    ],
    'other': [
        { value: 'General', label: 'General' },
        { value: 'Safety Document', label: 'Safety Document' },
        { value: 'Permit', label: 'Permit' }
    ]
};

function updateSiteDocSubTypes() {
    const typeSelect = document.getElementById('site_doc_type');
    const subTypeSelect = document.getElementById('site_doc_sub_type');
    const selectedType = typeSelect.value;

    subTypeSelect.innerHTML = '<option value="">Select sub-type...</option>';

    if (selectedType && siteDocSubTypes[selectedType]) {
        subTypeSelect.disabled = false;
        siteDocSubTypes[selectedType].forEach(item => {
            const option = document.createElement('option');
            option.value = item.value;
            option.textContent = item.label;
            subTypeSelect.appendChild(option);
        });
    } else {
        subTypeSelect.disabled = true;
    }
}

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
