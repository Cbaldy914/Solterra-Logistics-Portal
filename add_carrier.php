<?php
session_name("logistics_session");
session_start();

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'global_admin', 'customer_admin'])) {
    header("Location: unauthorized.php");
    exit();
}

require_once '../config.php';
require_once __DIR__ . '/notification_helpers.php';
$conn = getDBConnection();
if (!$conn) {
    die("Database connection failed.");
}

$google_maps_api_key = getGoogleMapsApiKey();
$dashboard_link = 'dashboard.php';

$role = $_SESSION['role'] ?? '';
$user_id = $_SESSION['user_id'] ?? 0;
$account_id = null;

if ($role !== 'global_admin') {
    $stmtAccount = $conn->prepare("SELECT account_id FROM customer_account_users WHERE user_id = ? AND role IN ('admin', 'customer_admin') LIMIT 1");
    if ($stmtAccount) {
        $stmtAccount->bind_param("i", $user_id);
        $stmtAccount->execute();
        $stmtAccount->bind_result($account_id);
        $stmtAccount->fetch();
        $stmtAccount->close();
    }
    if (!$account_id) {
        die("No valid account found for this user.");
    }
}

$request_id = isset($_GET['request_id']) ? (int)$_GET['request_id'] : (int)($_POST['request_id'] ?? 0);
$review_request = false;
$request_data = null;
$request_account_name = '';
$requester_label = '';
$duplicate_carriers = [];

$successMessage = "";
$errorMessage   = "";

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

function slp_form_value(string $key, ?array $request_data = null, string $default = ''): string {
    if (isset($_POST[$key])) {
        return (string)$_POST[$key];
    }
    if ($request_data && array_key_exists($key, $request_data) && $request_data[$key] !== null) {
        return (string)$request_data[$key];
    }
    return $default;
}

function slp_get_account_admin_ids(mysqli $conn, int $account_id): array {
    $ids = [];
    $stmt = $conn->prepare("SELECT u.id FROM users u JOIN customer_account_users cau ON cau.user_id = u.id WHERE cau.account_id = ? AND cau.role = 'admin'");
    if ($stmt) {
        $stmt->bind_param("i", $account_id);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $ids[] = (int)$row['id'];
        }
        $stmt->close();
    }
    return $ids;
}

function slp_get_global_admin_ids(mysqli $conn): array {
    $ids = [];
    $result = $conn->query("SELECT id FROM users WHERE role = 'global_admin'");
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $ids[] = (int)$row['id'];
        }
    }
    return $ids;
}

// Load request for review (admin/global_admin reviewing a customer_admin request)
if ($request_id > 0 && in_array($role, ['admin', 'global_admin'], true)) {
    $stmtRequest = $conn->prepare("
        SELECT cr.*, ca.name AS account_name,
               u.username, u.first_name, u.last_name
        FROM carrier_requests cr
        JOIN customer_accounts ca ON ca.id = cr.account_id
        JOIN users u ON u.id = cr.requested_by
        WHERE cr.id = ?
    ");
    if ($stmtRequest) {
        $stmtRequest->bind_param("i", $request_id);
        $stmtRequest->execute();
        $resultRequest = $stmtRequest->get_result();
        $request_row = $resultRequest->fetch_assoc();
        $stmtRequest->close();
        if (!$request_row) {
            $errorMessage = 'Carrier request not found.';
        } elseif ($role === 'admin' && $account_id && (int)$request_row['account_id'] !== (int)$account_id) {
            $errorMessage = 'You do not have access to this request.';
        } elseif ($request_row['status'] !== 'pending') {
            $errorMessage = 'This request has already been processed.';
        } else {
            $review_request = true;
            $request_data = $request_row;
            $request_account_name = $request_row['account_name'] ?? '';
            $requester_label = trim(($request_row['first_name'] ?? '') . ' ' . ($request_row['last_name'] ?? ''));
            if ($requester_label === '') {
                $requester_label = $request_row['username'] ?? '';
            }
        }
    }
}

// Check for duplicate carrier names when reviewing
if ($review_request && !empty($request_data['name'])) {
    $stmtDupes = $conn->prepare("SELECT id, name FROM carriers WHERE LOWER(name) = LOWER(?)");
    if ($stmtDupes) {
        $stmtDupes->bind_param("s", $request_data['name']);
        $stmtDupes->execute();
        $resultDupes = $stmtDupes->get_result();
        while ($row = $resultDupes->fetch_assoc()) {
            $duplicate_carriers[] = $row;
        }
        $stmtDupes->close();
    }
}

// Process form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if ($request_id > 0 && !$review_request && in_array($role, ['admin', 'global_admin'], true)) {
            throw new Exception('Unable to load the carrier request.');
        }
        if ($role === 'customer_admin' && $review_request) {
            throw new Exception('You do not have access to review requests.');
        }

        $request_action = $review_request ? ($_POST['request_action'] ?? 'approve') : 'create';
        if ($review_request && $request_action === 'reject') {
            $rejection_reason = trim($_POST['rejection_reason'] ?? '');
            if ($rejection_reason === '') {
                throw new Exception('Please provide a rejection reason.');
            }

            $stmtReject = $conn->prepare("UPDATE carrier_requests SET status = 'rejected', reviewed_by = ?, reviewed_at = NOW(), rejection_reason = ? WHERE id = ?");
            if (!$stmtReject) {
                throw new Exception("Error preparing rejection update: " . $conn->error);
            }
            $stmtReject->bind_param("isi", $user_id, $rejection_reason, $request_id);
            if (!$stmtReject->execute()) {
                throw new Exception("Error rejecting request: " . $stmtReject->error);
            }
            $stmtReject->close();

            if (!empty($request_data['requested_by'])) {
                $title = 'Carrier request rejected';
                $message = "Your request for '{$request_data['name']}' was rejected.";
                $message .= "\nReason: {$rejection_reason}";
                notify_user((int)$request_data['requested_by'], 'carrier_request', $title, $message, 'manage_carriers.php');
            }

            $successMessage = 'Carrier request rejected.';
        } else {
            // Gather form fields
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

            // Validation
            if ($name === '') {
                throw new Exception("Carrier Name is required.");
            }
            $valid_types = ['ftl','ltl','drayage','intermodal','ocean','other'];
            if (!in_array($carrier_type, $valid_types, true)) {
                $carrier_type = 'ftl';
            }

            // Handle logo upload
            $logo_url = $review_request ? ($request_data['logo_url'] ?? null) : null;
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
                    $logo_url = $target_file;
                } else {
                    throw new Exception("File upload error code: " . $_FILES['logo_file']['error']);
                }
            }

            {
                // Direct insert for admin/global_admin/customer_admin
                $carrier_account_id = ($role === 'global_admin') ? null : $account_id;
                $stmt = $conn->prepare("
                    INSERT INTO carriers (
                        account_id, name, short_name, mc_number, dot_number, carrier_type,
                        is_solterra_managed, contact_person, phone, email, website,
                        street_address, city, state, zip_code, country,
                        logo_url, is_active, notes
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                if (!$stmt) {
                    throw new Exception("Error preparing carrier insert: " . $conn->error);
                }
                $stmt->bind_param(
                    "isssssissssssssssis",
                    $carrier_account_id,
                    $name, $short_name, $mc_number, $dot_number, $carrier_type,
                    $is_solterra_managed,
                    $contact_person, $phone, $email, $website,
                    $street_address, $city, $state, $zip_code, $country,
                    $logo_url, $is_active, $notes
                );
                if (!$stmt->execute()) {
                    throw new Exception("Error inserting carrier: " . $stmt->error);
                }
                $carrier_id = $conn->insert_id;
                $stmt->close();

                // If reviewing a request, mark it approved
                if ($review_request) {
                    $stmtApprove = $conn->prepare("UPDATE carrier_requests SET status = 'approved', reviewed_by = ?, reviewed_at = NOW(), approved_carrier_id = ? WHERE id = ?");
                    if (!$stmtApprove) {
                        throw new Exception("Error preparing approval update: " . $conn->error);
                    }
                    $stmtApprove->bind_param("iii", $user_id, $carrier_id, $request_id);
                    if (!$stmtApprove->execute()) {
                        throw new Exception("Error approving request: " . $stmtApprove->error);
                    }
                    $stmtApprove->close();

                    if (!empty($request_data['requested_by'])) {
                        $title = 'Carrier request approved';
                        $message = "Your request for '{$name}' has been approved and added.";
                        notify_user((int)$request_data['requested_by'], 'carrier_request', $title, $message, 'manage_carriers.php');
                    }

                    $successMessage = "Carrier request approved and added successfully!";
                } else {
                    $successMessage = "Carrier added successfully!";
                }
            }
        }
    } catch (Exception $ex) {
        $errorMessage = $ex->getMessage();
    }
}

$page_title = 'Add Carrier';
$page_heading = 'Add Carrier';
$submit_label = 'Add Carrier';
if ($review_request) {
    $page_title = 'Review Carrier Request';
    $page_heading = 'Review Carrier Request';
    $submit_label = 'Approve Request';
}

$is_active_checked = isset($_POST['is_active']) ? true : ($review_request ? !empty($request_data['is_active']) : true);

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($page_title); ?></title>
    <link rel="stylesheet" href="portal.css">
    <link rel="icon" href="pictures/favicon.png" type="image/x-icon">
    <link href="https://fonts.googleapis.com/css?family=Poppins:300,400,500,600,700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        main { max-width: 900px; }

        /* Page Header Card */
        .page-header-card {
            background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%);
            border-radius: 24px;
            padding: 32px;
            margin-bottom: 32px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.06);
            border: 1px solid rgba(72, 140, 154, 0.08);
            position: relative;
            overflow: hidden;
        }
        .page-header-card::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0;
            height: 4px;
            background: linear-gradient(90deg, #488C9A 0%, #293E4C 100%);
        }
        .page-header-card h1 {
            font-size: 2.2em;
            font-weight: 700;
            background: linear-gradient(135deg, #293E4C 0%, #488C9A 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            margin: 0 0 8px 0;
            line-height: 1.2;
        }
        .page-header-card .subtitle {
            color: #6c757d;
            font-size: 1.05em;
            font-weight: 500;
            margin: 0;
        }

        /* Form Section Cards */
        .form-section {
            background: #fff;
            border-radius: 16px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.06);
            margin-bottom: 24px;
            overflow: hidden;
            border: 1px solid #e9ecef;
        }
        .form-section-header {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 20px 24px;
            background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%);
            border-bottom: 1px solid #e9ecef;
        }
        .form-section-header .icon-badge {
            background: linear-gradient(135deg, #488C9A 0%, #3a7a87 100%);
            color: #fff;
            width: 36px; height: 36px;
            border-radius: 10px;
            display: flex; align-items: center; justify-content: center;
            font-size: 1rem;
        }
        .form-section-header h2 {
            margin: 0;
            font-size: 1.15rem;
            font-weight: 600;
            color: #293E4C;
        }
        .form-section-body {
            padding: 24px;
        }

        /* Form Grid & Inputs */
        .form-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }
        .form-row.single { grid-template-columns: 1fr; }
        .form-row.triple { grid-template-columns: repeat(3, 1fr); }
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
        .form-group label .required-star { color: #dc3545; margin-left: 4px; }
        .form-group label .optional-tag { color: #6c757d; font-weight: 400; font-size: 0.85rem; margin-left: 8px; }
        .form-group input,
        .form-group select,
        .form-group textarea {
            padding: 12px 16px;
            border: 2px solid #e9ecef;
            border-radius: 10px;
            font-size: 1rem;
            transition: all 0.2s ease;
            background: #fafafa;
            font-family: inherit;
            box-sizing: border-box;
            width: 100%;
        }
        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: #488C9A;
            background: #fff;
            box-shadow: 0 0 0 3px rgba(72, 140, 154, 0.1);
        }
        .form-group textarea { min-height: 80px; resize: vertical; }
        .form-group .help-text {
            font-size: 0.85rem;
            color: #6c757d;
            margin-top: 6px;
        }

        /* Checkbox */
        .checkbox-container {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 12px 16px;
            background: #f8f9fa;
            border-radius: 10px;
            border: 2px solid #e9ecef;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        .checkbox-container:hover { border-color: #488C9A; }
        .checkbox-container input[type="checkbox"] { width: auto; margin: 0; accent-color: #488C9A; }
        .checkbox-container label { margin: 0; cursor: pointer; font-weight: 500; color: #333; }

        /* Buttons */
        .btn-submit-container {
            display: flex;
            gap: 12px;
            margin-top: 8px;
        }
        .btn-submit {
            background: linear-gradient(135deg, #488C9A 0%, #3a7a87 100%);
            color: #fff;
            border: none;
            padding: 14px 28px;
            cursor: pointer;
            border-radius: 12px;
            font-size: 1rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s ease;
            box-shadow: 0 4px 12px rgba(72, 140, 154, 0.3);
        }
        .btn-submit:hover {
            transform: translateY(-1px);
            box-shadow: 0 6px 20px rgba(72, 140, 154, 0.4);
        }
        .btn-submit.btn-reject {
            background: linear-gradient(135deg, #dc3545 0%, #b91c1c 100%);
            box-shadow: 0 4px 12px rgba(220, 53, 69, 0.3);
        }
        .btn-submit.btn-reject:hover {
            box-shadow: 0 6px 20px rgba(220, 53, 69, 0.4);
        }

        /* Alert Messages */
        .success-message, .error-message, .warning-message, .review-banner {
            padding: 16px 20px;
            border-radius: 12px;
            margin-bottom: 20px;
            font-weight: 500;
        }
        .success-message {
            color: #155724;
            background: linear-gradient(135deg, #d4edda, #c3e6cb);
            border: 1px solid #b1dfbb;
        }
        .error-message {
            color: #721c24;
            background: linear-gradient(135deg, #f8d7da, #f5c6cb);
            border: 1px solid #f1b0b7;
        }
        .warning-message {
            color: #856404;
            background: linear-gradient(135deg, #fff3cd, #ffeeba);
            border: 1px solid #ffdf7e;
        }
        .review-banner {
            color: #1e40af;
            background: linear-gradient(135deg, #eef2ff, #e0e7ff);
            border: 1px solid #c7d2fe;
        }

        @media (max-width: 768px) {
            .form-row, .form-row.triple { grid-template-columns: 1fr; }
            .page-header-card { padding: 24px; }
            .page-header-card h1 { font-size: 1.6em; }
        }
    </style>
</head>
<body>
<?php include 'header.php'; ?>
<main>
    <?php
        require_once 'components/breadcrumbs.php';
        echo slp_render_breadcrumbs([
            'current_label' => $page_heading,
            'extra' => [ ['label' => 'Manage Carriers', 'url' => 'manage_carriers.php'] ]
        ]);
    ?>

    <div class="page-header-card">
        <h1><?php echo htmlspecialchars($page_heading); ?></h1>
        <p class="subtitle">Enter carrier details below to add them to the system.</p>
    </div>

    <?php if ($review_request): ?>
        <div class="review-banner">
            <strong><i class="fas fa-clipboard-check"></i> Reviewing request</strong><br>
            Account: <?php echo htmlspecialchars($request_account_name); ?> &middot;
            Requested by: <?php echo htmlspecialchars($requester_label ?: 'Unknown'); ?>
        </div>
    <?php endif; ?>

    <?php if (!empty($duplicate_carriers)): ?>
        <div class="warning-message">
            <strong><i class="fas fa-exclamation-triangle"></i> Possible duplicate:</strong> A carrier with the same name already exists.
        </div>
    <?php endif; ?>

    <?php if (!empty($successMessage)): ?>
        <div class="success-message"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($successMessage); ?></div>
    <?php endif; ?>

    <?php if (!empty($errorMessage)): ?>
        <div class="error-message"><i class="fas fa-times-circle"></i> <?php echo htmlspecialchars($errorMessage); ?></div>
    <?php endif; ?>

    <form action="" method="POST" enctype="multipart/form-data">
        <?php if ($review_request): ?>
            <input type="hidden" name="request_id" value="<?php echo (int)$request_id; ?>">
        <?php endif; ?>

        <!-- Basic Information -->
        <div class="form-section">
            <div class="form-section-header">
                <div class="icon-badge"><i class="fas fa-truck"></i></div>
                <h2>Basic Information</h2>
            </div>
            <div class="form-section-body">
                <div class="form-row">
                    <div class="form-group">
                        <label for="name">Carrier Name <span class="required-star">*</span></label>
                        <input type="text" id="name" name="name" required placeholder="e.g., XPO Logistics" value="<?php echo htmlspecialchars(slp_form_value('name', $request_data)); ?>">
                    </div>
                    <div class="form-group">
                        <label for="short_name">Short Name <span class="optional-tag">(optional)</span></label>
                        <input type="text" id="short_name" name="short_name" placeholder="e.g., XPO" value="<?php echo htmlspecialchars(slp_form_value('short_name', $request_data)); ?>">
                        <span class="help-text">Common abbreviation</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Carrier Identification -->
        <div class="form-section">
            <div class="form-section-header">
                <div class="icon-badge"><i class="fas fa-id-card"></i></div>
                <h2>Carrier Identification</h2>
            </div>
            <div class="form-section-body">
                <div class="form-row triple">
                    <div class="form-group">
                        <label for="mc_number">MC Number</label>
                        <input type="text" id="mc_number" name="mc_number" placeholder="e.g., MC-123456" value="<?php echo htmlspecialchars(slp_form_value('mc_number', $request_data)); ?>">
                    </div>
                    <div class="form-group">
                        <label for="dot_number">DOT Number</label>
                        <input type="text" id="dot_number" name="dot_number" placeholder="e.g., 1234567" value="<?php echo htmlspecialchars(slp_form_value('dot_number', $request_data)); ?>">
                    </div>
                    <div class="form-group">
                        <label for="carrier_type">Carrier Type</label>
                        <?php $ct = slp_form_value('carrier_type', $request_data, 'ftl'); ?>
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
                <span class="help-text">FMCSA Motor Carrier (MC) number and USDOT number are optional but recommended</span>
            </div>
        </div>

        <!-- Contact Information -->
        <div class="form-section">
            <div class="form-section-header">
                <div class="icon-badge"><i class="fas fa-address-book"></i></div>
                <h2>Contact Information</h2>
            </div>
            <div class="form-section-body">
                <div class="form-row">
                    <div class="form-group">
                        <label for="contact_person">Contact Person</label>
                        <input type="text" id="contact_person" name="contact_person" placeholder="e.g., John Smith" value="<?php echo htmlspecialchars(slp_form_value('contact_person', $request_data)); ?>">
                    </div>
                    <div class="form-group">
                        <label for="website">Website</label>
                        <input type="url" id="website" name="website" placeholder="https://www.carrier.com" value="<?php echo htmlspecialchars(slp_form_value('website', $request_data)); ?>">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label for="phone">Phone</label>
                        <input type="tel" id="phone" name="phone" placeholder="(555) 123-4567" value="<?php echo htmlspecialchars(slp_form_value('phone', $request_data)); ?>">
                    </div>
                    <div class="form-group">
                        <label for="email">Email</label>
                        <input type="email" id="email" name="email" placeholder="dispatch@carrier.com" value="<?php echo htmlspecialchars(slp_form_value('email', $request_data)); ?>">
                    </div>
                </div>
            </div>
        </div>

        <!-- Address -->
        <div class="form-section">
            <div class="form-section-header">
                <div class="icon-badge"><i class="fas fa-map-marker-alt"></i></div>
                <h2>Address</h2>
            </div>
            <div class="form-section-body">
                <div class="form-row single">
                    <div class="form-group">
                        <label for="street_address">Street Address</label>
                        <input type="text" id="street_address" name="street_address" placeholder="123 Logistics Blvd" value="<?php echo htmlspecialchars(slp_form_value('street_address', $request_data)); ?>">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label for="city">City</label>
                        <input type="text" id="city" name="city" placeholder="Phoenix" value="<?php echo htmlspecialchars(slp_form_value('city', $request_data)); ?>">
                    </div>
                    <div class="form-group">
                        <label for="state">State/Province</label>
                        <input type="text" id="state" name="state" placeholder="AZ" value="<?php echo htmlspecialchars(slp_form_value('state', $request_data)); ?>">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label for="zip_code">Zip/Postal Code</label>
                        <input type="text" id="zip_code" name="zip_code" placeholder="85281" value="<?php echo htmlspecialchars(slp_form_value('zip_code', $request_data)); ?>">
                    </div>
                    <div class="form-group">
                        <label for="country">Country</label>
                        <input type="text" id="country" name="country" value="<?php echo htmlspecialchars(slp_form_value('country', $request_data, 'USA')); ?>" placeholder="USA">
                    </div>
                </div>
            </div>
        </div>

        <!-- Additional Information -->
        <div class="form-section">
            <div class="form-section-header">
                <div class="icon-badge"><i class="fas fa-cog"></i></div>
                <h2>Additional Information</h2>
            </div>
            <div class="form-section-body">
                <div class="form-row single">
                    <div class="form-group">
                        <label for="logo_file">Company Logo <span class="optional-tag">(optional)</span></label>
                        <input type="file" id="logo_file" name="logo_file" accept="image/*">
                        <span class="help-text">JPG, PNG, GIF, SVG - Max 5MB</span>
                    </div>
                </div>

                <?php if ($role === 'global_admin'): ?>
                <div class="form-row single" style="margin-bottom: 16px;">
                    <div class="checkbox-container">
                        <input type="checkbox" id="is_solterra_managed" name="is_solterra_managed" <?php echo slp_form_value('is_solterra_managed', $request_data) ? 'checked' : ''; ?>>
                        <label for="is_solterra_managed">Solterra-Managed Carrier</label>
                    </div>
                </div>
                <?php endif; ?>

                <div class="form-row single" style="margin-bottom: 16px;">
                    <div class="checkbox-container">
                        <input type="checkbox" id="is_active" name="is_active" <?php echo $is_active_checked ? 'checked' : ''; ?>>
                        <label for="is_active">Active Carrier</label>
                    </div>
                </div>

                <div class="form-row single">
                    <div class="form-group">
                        <label for="notes">Notes <span class="optional-tag">(optional)</span></label>
                        <textarea id="notes" name="notes" placeholder="Additional notes about this carrier..."><?php echo htmlspecialchars(slp_form_value('notes', $request_data)); ?></textarea>
                    </div>
                </div>
            </div>
        </div>

        <?php if ($review_request): ?>
        <!-- Request Review -->
        <div class="form-section">
            <div class="form-section-header">
                <div class="icon-badge" style="background: linear-gradient(135deg, #dc3545, #b91c1c);"><i class="fas fa-gavel"></i></div>
                <h2>Request Review</h2>
            </div>
            <div class="form-section-body">
                <div class="form-row single">
                    <div class="form-group">
                        <label for="rejection_reason">Rejection Reason <span class="optional-tag">(required to reject)</span></label>
                        <textarea id="rejection_reason" name="rejection_reason" placeholder="Provide a reason if rejecting this request..."><?php echo htmlspecialchars($_POST['rejection_reason'] ?? ''); ?></textarea>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <div class="btn-submit-container">
            <?php if ($review_request): ?>
                <button type="submit" name="request_action" value="approve" class="btn-submit"><i class="fas fa-check"></i> <?php echo htmlspecialchars($submit_label); ?></button>
                <button type="submit" name="request_action" value="reject" class="btn-submit btn-reject"><i class="fas fa-times"></i> Reject Request</button>
            <?php else: ?>
                <button type="submit" class="btn-submit"><i class="fas fa-plus-circle"></i> <?php echo htmlspecialchars($submit_label); ?></button>
            <?php endif; ?>
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
