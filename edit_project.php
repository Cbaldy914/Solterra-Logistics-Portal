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
    
    // New site information fields (optional)
    $phone1                   = trim($_POST['phone1'] ?? '');
    $phone2                   = trim($_POST['phone2'] ?? '');
    $timezone                 = trim($_POST['timezone'] ?? 'America/New_York');
    $reference_numbers        = trim($_POST['reference_numbers'] ?? '');
    $instructions             = trim($_POST['instructions'] ?? '');
    $additional_notes         = trim($_POST['additional_notes'] ?? '');
    
    // Get existing driver handout URL first
    $stmt_handout = $conn->prepare("SELECT driver_handout_url FROM projects WHERE id = ?");
    $stmt_handout->bind_param("i", $project_id);
    $stmt_handout->execute();
    $stmt_handout->bind_result($existing_handout_url);
    $stmt_handout->fetch();
    $stmt_handout->close();
    
    $driver_handout_url = $existing_handout_url; // default to existing

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

    // Handle driver handout upload
    if (isset($_FILES['driver_handout']) && $_FILES['driver_handout']['error'] !== UPLOAD_ERR_NO_FILE) {
        if ($_FILES['driver_handout']['error'] === UPLOAD_ERR_OK) {
            $allowed_ext = ['pdf','doc','docx','jpg','jpeg','png'];
            $handout_name = $_FILES['driver_handout']['name'];
            $handout_tmp = $_FILES['driver_handout']['tmp_name'];
            $handout_size = $_FILES['driver_handout']['size'];
            $handout_ext = strtolower(pathinfo($handout_name, PATHINFO_EXTENSION));

            if (!in_array($handout_ext, $allowed_ext)) {
                die("Invalid driver handout file type. Only PDF, DOC, DOCX, JPG, JPEG, PNG allowed.");
            }
            if ($handout_size > 5*1024*1024) {
                die("Driver handout file exceeds 5MB limit.");
            }
            $handout_unique = uniqid('driver_handout_', true).'.'.$handout_ext;
            $handout_dir = 'uploads/driver_handouts/';
            if (!is_dir($handout_dir)) {
                mkdir($handout_dir, 0755, true);
            }
            if (!move_uploaded_file($handout_tmp, $handout_dir.$handout_unique)) {
                die("Error uploading the driver handout file.");
            }
            $driver_handout_url = $handout_dir.$handout_unique;
        } else {
            die("Driver handout upload error code: " . $_FILES['driver_handout']['error']);
        }
    }

    // Update the project
    $stmtUpdate = $conn->prepare("
        UPDATE projects 
        SET project_name = ?, street_address = ?, city = ?, state = ?, zip_code = ?, 
            estimated_completion_date = ?, image_url = ?, solterra_fee = ?,
            phone1 = ?, phone2 = ?, timezone = ?, reference_numbers = ?, 
            instructions = ?, additional_notes = ?, driver_handout_url = ?
        WHERE id = ?
    ");
    $stmtUpdate->bind_param(
        "sssssssdsssssssi",
        $project_name, $street_address, $city, $state, $zip_code,
        $estimated_completion_date, $image_url, $solterra_fee,
        $phone1, $phone2, $timezone, $reference_numbers,
        $instructions, $additional_notes, $driver_handout_url,
        $project_id
    );

    if ($stmtUpdate->execute()) {
        echo "<script>alert('Project updated successfully!'); window.location='project_overview?project_id={$project_id}';</script>";
        exit();
    } else {
        die("Error updating project: " . $stmtUpdate->error);
    }
} else {
    // GET request: fetch project data for display in the form
    $stmt = $conn->prepare("SELECT * FROM projects WHERE id = ?");
    $stmt->bind_param("i", $project_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $project = $result->fetch_assoc();
    $stmt->close();

    if (!$project) {
        die("Project not found.");
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
    <title>Edit Project</title>
    <link rel="stylesheet" href="portal.css">
    <link rel="icon" href="pictures/favicon.png" type="image/x-icon">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        .breadcrumb {
            display: flex;
            align-items: center;
            margin-bottom: 30px;
            margin-top: 10px;
            font-size: 0.95em;
            color: #6c757d;
        }
        .breadcrumb a {
            color: #488C9A;
            text-decoration: none;
            transition: color 0.3s ease;
            font-weight: 500;
        }
        .breadcrumb a:hover {
            color: #293E4C;
        }
        .breadcrumb .separator {
            margin: 0 12px;
            color: #d1d5db;
            font-weight: 300;
        }

        /* Header Section */
        .form-header {
            background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%);
            border-radius: 24px;
            padding: 32px;
            margin-bottom: 20px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.06);
            border: 1px solid rgba(72, 140, 154, 0.08);
            position: relative;
            overflow: hidden;
        }

        .form-header::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, #488C9A 0%, #293E4C 100%);
        }

        .header-content {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 24px;
        }

        .header-left {
            display: flex;
            align-items: center;
            gap: 24px;
        }

        .header-info h1 {
            font-size: 2.5em;
            font-weight: 700;
            background: linear-gradient(135deg, #293E4C 0%, #488C9A 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            margin: 0 0 8px 0;
            line-height: 1.2;
        }

        .header-subtitle {
            color: #6c757d;
            font-size: 1.1em;
            font-weight: 500;
            margin: 0;
        }
        
        .form-container {
            margin: 20px 0;
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.1);
            overflow: hidden;
        }
        
        .form-content {
            padding: 0;
        }
        
        .form-section {
            padding: 40px;
            border-right: 1px solid #f0f0f0;
        }

        .form-section:last-child {
            border-right: none;
        }
        
        .form-section h2 {
            color: #293E4C;
            margin-bottom: 20px;
            font-size: 1.3em;
            font-weight: 600;
        }
        
        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            min-height: 600px;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-group label {
            display: block;
            font-weight: 600;
            color: #293E4C;
            margin-bottom: 8px;
        }
        
        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 12px;
            border: 2px solid #e9ecef;
            border-radius: 8px;
            font-size: 1rem;
            transition: border-color 0.3s ease;
            box-sizing: border-box;
        }
        
        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: #488C9A;
        }

        /* Input styling */
        .input-group {
            margin-bottom: 24px;
        }

        .input-group label {
            display: block;
            font-weight: 600;
            color: #293E4C;
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

        /* Module summary section */
        .module-summary {
            grid-column: 1 / -1;
            border-top: 1px solid #f0f0f0;
            margin-top: 20px;
            padding: 40px;
            background: #f8f9fa;
        }

        .module-summary h2 {
            color: #293E4C;
            border-bottom: 2px solid #488C9A;
            padding-bottom: 12px;
            margin-bottom: 24px;
        }

        .module-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .module-stat {
            background: white;
            padding: 20px;
            border-radius: 12px;
            border-left: 4px solid #488C9A;
            box-shadow: 0 2px 8px rgba(0,0,0,0.04);
        }

        .module-stat .wattage {
            font-size: 1.3em;
            font-weight: bold;
            color: #293E4C;
            margin-bottom: 4px;
        }

        .module-stat .quantity {
            font-size: 1.1em;
            color: #488C9A;
            font-weight: 600;
        }

        .total-summary {
            background: linear-gradient(135deg, #e8f4f8 0%, #d1ecf1 100%);
            padding: 16px;
            border-radius: 8px;
            text-align: center;
            font-weight: 600;
            color: #293E4C;
            margin-bottom: 30px;
        }

        .batch-table {
            background: white;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0,0,0,0.04);
        }

        .batch-table table {
            width: 100%;
            border-collapse: collapse;
        }

        .batch-table th {
            background: #293E4C;
            color: white;
            padding: 12px 16px;
            text-align: left;
            font-weight: 600;
        }

        .batch-table td {
            padding: 12px 16px;
            border-bottom: 1px solid #f0f0f0;
        }

        .batch-table tr:last-child td {
            border-bottom: none;
        }

        .batch-table tr:hover {
            background: #f8f9fa;
        }

        .no-modules {
            text-align: center;
            padding: 60px 40px;
            background: white;
            border-radius: 12px;
            color: #666;
        }

        .no-modules h3 {
            color: #293E4C;
            margin-bottom: 16px;
        }

        .info-note {
            background: #fff3cd;
            border: 1px solid #ffeaa7;
            border-radius: 8px;
            padding: 16px;
            margin-top: 24px;
            font-size: 0.9em;
            color: #856404;
        }

        /* Current file displays */
        .current-file {
            background: #f8f9fa;
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 12px;
            border-left: 4px solid #488C9A;
        }

        .current-file a {
            color: #488C9A;
            text-decoration: none;
            font-weight: 500;
        }

        .current-file a:hover {
            text-decoration: underline;
        }

        .button-group {
            display: flex;
            justify-content: center;
            align-items: center;
            margin-top: 40px;
            gap: 20px;
        }
        
        .btn-submit {
            background: linear-gradient(135deg, #293E4C 0%, #488C9A 100%);
            color: white;
            border: none;
            padding: 16px 48px;
            border-radius: 50px;
            font-size: 1.1em;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            box-shadow: 0 4px 16px rgba(40, 62, 76, 0.2);
        }
        
        .btn-submit:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 24px rgba(40, 62, 76, 0.3);
        }

        /* Responsive design */
        @media (max-width: 768px) {
            .form-container {
                margin: 10px 0;
            }
            
            .form-content {
                padding: 20px;
            }
            
            .form-section {
                padding: 24px;
            }
            
            .form-grid {
                grid-template-columns: 1fr;
            }
            
            .button-group {
                flex-direction: column;
                align-items: center;
            }
            
            .form-header {
                padding: 20px;
                margin-bottom: 20px;
            }
            
            .header-content {
                gap: 16px;
            }
            
            .header-left {
                gap: 16px;
            }
            
            .header-info h1 {
                font-size: 2em;
            }
            
            .header-subtitle {
                font-size: 1em;
            }
        }

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

            .module-stats {
                grid-template-columns: 1fr 1fr;
            }
        }

        @media (max-width: 768px) {
            main {
                width: 95%;
                padding: 10px;
            }

            .form-section,
            .module-summary {
                padding: 24px;
            }

            .address-grid,
            .phone-grid,
            .module-stats {
                grid-template-columns: 1fr;
            }

            .page-header h1 {
                font-size: 2rem;
            }
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

        /* Navigation link */
        .nav-link {
            display: inline-block;
            margin-top: 20px;
            padding: 12px 24px;
            background: #6c757d;
            color: white;
            text-decoration: none;
            border-radius: 25px;
            transition: background 0.2s ease;
        }

        .nav-link:hover {
            background: #5a6268;
            color: white;
        }
    </style>
</head>
<body>
<?php include 'header.php'; ?>
<main>
    <?php
        require_once 'components/breadcrumbs.php';
        echo slp_render_breadcrumbs([
            'current_label' => 'Edit Project',
            'extra' => [ ['label' => 'Manage Projects', 'url' => 'manage_projects.php'] ]
        ]);
    ?>

    <!-- Beautiful Header Section -->
    <div class="form-header">
        <div class="header-content">
            <div class="header-left">
                <div class="header-info">
                    <h1>Edit Project</h1>
                    <p class="header-subtitle">Update project details and site information</p>
                </div>
            </div>
        </div>
    </div>

    <div class="form-container">
        <div class="form-content">
            <form id="edit-project-form" action="edit_project.php" method="POST" enctype="multipart/form-data">
                <input type="hidden" name="project_id" value="<?php echo $project_id; ?>">
            <div class="form-grid">
                <!-- Left Column: Basic Project Information -->
                <div class="form-section">
                    <h2>Project Details</h2>
                    
                    <div class="input-group required">
                        <label for="project_name">Project Name</label>
                        <input type="text" id="project_name" name="project_name" value="<?php echo htmlspecialchars($project['project_name']); ?>" required>
                    </div>

                    <div class="input-group required">
                        <label>Project Address</label>
                        <div class="address-grid">
                            <div class="input-group required">
                                <label for="street_address">Street Address</label>
                                <input type="text" id="street_address" name="street_address" value="<?php echo htmlspecialchars($project['street_address'] ?? ''); ?>" placeholder="1107 W Manresa Way" required>
                            </div>
                            <div class="input-group required">
                                <label for="city">City</label>
                                <input type="text" id="city" name="city" value="<?php echo htmlspecialchars($project['city'] ?? ''); ?>" placeholder="Huachuca City" required>
                            </div>
                            <div class="input-group required">
                                <label for="state">State</label>
                                <input type="text" id="state" name="state" value="<?php echo htmlspecialchars($project['state'] ?? ''); ?>" placeholder="AZ" required>
                            </div>
                            <div class="input-group required">
                                <label for="zip_code">Zip Code</label>
                                <input type="text" id="zip_code" name="zip_code" value="<?php echo htmlspecialchars($project['zip_code'] ?? ''); ?>" placeholder="85616" required>
                            </div>
                        </div>
                    </div>

                    <div class="input-group">
                        <label for="estimated_completion_date">Estimated Completion Date</label>
                        <input type="date" id="estimated_completion_date" name="estimated_completion_date" value="<?php echo htmlspecialchars($project['estimated_completion_date']); ?>">
                    </div>

                    <div class="input-group">
                        <label>Project Photos</label>
                        <div style="display:flex; align-items:center; gap:12px;">
                            <img src="<?php echo htmlspecialchars($project['image_url'] ?: 'pictures/test.png'); ?>" alt="Project Cover" style="width:120px; height:80px; object-fit:cover; border-radius:8px; border:1px solid rgba(72,140,154,0.15);">
                            <a href="project_photos.php?project_id=<?php echo $project_id; ?>" class="document-button" style="display:inline-flex; gap:8px; align-items:center; width:auto; padding:10px 14px; background: linear-gradient(135deg, #488C9A 0%, #3A6E7F 100%); color:#fff; text-decoration:none; border-radius:10px; box-shadow: 0 4px 15px rgba(72, 140, 154, 0.3);">
                                <i class="fas fa-camera"></i> Manage Project Photos
                            </a>
                        </div>
                        <small style="color:#6c757d;display:block;margin-top:6px;">Arrange photos and choose the cover by dragging the first photo.</small>
                    </div>

                    <div class="input-group required">
                        <label for="solterra_fee">Solterra Fee (per watt)</label>
                        <input type="number" id="solterra_fee" step="0.0001" name="solterra_fee" value="<?php echo isset($project['solterra_fee']) ? htmlspecialchars($project['solterra_fee']) : '0.0000'; ?>" required>
                    </div>
                </div>

                <!-- Right Column: Site Information -->
                <div class="form-section">
                    <h2>Site Information <span style="color: #999; font-weight: 400; font-size: 0.85rem;">(optional)</span></h2>
                    
                    <div class="input-group">
                        <label>Contact Information</label>
                        <div class="phone-grid">
                            <div class="input-group">
                                <label for="phone1">Primary Phone</label>
                                <input type="tel" id="phone1" name="phone1" value="<?php echo htmlspecialchars($project['phone1'] ?? ''); ?>" placeholder="555-555-5555">
                            </div>
                            <div class="input-group">
                                <label for="phone2">Secondary Phone</label>
                                <input type="tel" id="phone2" name="phone2" value="<?php echo htmlspecialchars($project['phone2'] ?? ''); ?>" placeholder="555-555-5555">
                            </div>
                            <div class="input-group">
                                <label for="timezone">Timezone</label>
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
                        </div>
                    </div>

                    <div class="input-group">
                        <label for="reference_numbers">Reference Numbers</label>
                        <input type="text" id="reference_numbers" name="reference_numbers" value="<?php echo htmlspecialchars($project['reference_numbers'] ?? ''); ?>" placeholder="PO #, Job #, etc.">
                    </div>

                    <div class="input-group">
                        <label for="instructions">Special Instructions</label>
                        <textarea id="instructions" name="instructions" placeholder="Delivery instructions, site access requirements, etc."><?php echo htmlspecialchars($project['instructions'] ?? ''); ?></textarea>
                    </div>

                    <div class="input-group">
                        <label for="additional_notes">Additional Notes</label>
                        <textarea id="additional_notes" name="additional_notes" placeholder="Any other relevant information for this project..."><?php echo htmlspecialchars($project['additional_notes'] ?? ''); ?></textarea>
                    </div>

                    <div class="input-group">
                        <label for="driver_handout">Driver Handout</label>
                        <?php if (!empty($project['driver_handout_url'])): ?>
                            <div class="current-file">
                                <span style="color: #666;">Current: </span>
                                <a href="<?php echo htmlspecialchars($project['driver_handout_url']); ?>" target="_blank">
                                    <?php echo htmlspecialchars(basename($project['driver_handout_url'])); ?>
                                </a>
                            </div>
                        <?php endif; ?>
                        <input type="file" id="driver_handout" name="driver_handout" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png">
                        <small style="color: #666; font-size: 0.85em;">Upload driver instructions, site maps, or other handout materials (PDF, DOC, JPG, PNG - max 5MB)</small>
                    </div>
                </div>

                <!-- Module Summary Section (Full Width) -->
                <div class="module-summary">
                    <h2>Project Module Summary</h2>
                    
                    <?php if (!empty($actual_modules)): ?>
                        <div class="module-stats">
                            <?php foreach ($actual_modules as $module): ?>
                                <div class="module-stat">
                                    <div class="wattage"><?php echo number_format($module['wattage']); ?>W</div>
                                    <div class="quantity"><?php echo number_format($module['total_quantity']); ?> modules</div>
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

                        <?php if (!empty($assigned_batches)): ?>
                            <div class="batch-table">
                                <table>
                                    <thead>
                                        <tr>
                                            <th>Batch ID</th>
                                            <th>Manufacturer</th>
                                            <th>Wattage</th>
                                            <th>Quantity</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($assigned_batches as $batch): ?>
                                            <tr>
                                                <td>#<?php echo $batch['batch_id']; ?></td>
                                                <td>
                                                    <?php 
                                                    $manufacturer_name = explode(' - ', $batch['vendor_name'])[0];
                                                    echo htmlspecialchars($manufacturer_name); 
                                                    ?>
                                                </td>
                                                <td><?php echo number_format($batch['wattage']); ?>W</td>
                                                <td><?php echo number_format($batch['quantity']); ?></td>
                                                <td>
                                                    <a href="module_overview.php?batch_id=<?php echo $batch['batch_id']; ?>" style="color: #488C9A; text-decoration: none; font-weight: 500;">View Batch</a>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    <?php else: ?>
                        <div class="no-modules">
                            <h3>No Module Batches Assigned</h3>
                            <p>This project doesn't have any module batches assigned to it yet.</p>
                            <p>
                                <a href="modules.php" style="color: #488C9A; text-decoration: none; font-weight: 600;">
                                    → Go to Manage Modules to assign batches to this project
                                </a>
                            </p>
                        </div>
                    <?php endif; ?>
                    
                    <div class="info-note">
                        <strong>Note:</strong> Module quantities are automatically calculated from batches assigned to this project. 
                        To modify module quantities, edit the individual module batches in 
                        <a href="modules.php" style="color: #856404; font-weight: 600;">Manage Modules</a>.
                    </div>
                </div>
            </div>

            <div class="button-group">
                <button type="submit" class="btn-submit">Update Project</button>
            </div>
            </form>
        </div>
    </div>
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
