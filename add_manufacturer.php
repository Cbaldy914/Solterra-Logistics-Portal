<?php
session_name("logistics_session");
session_start();

// Ensure user has role global_admin, admin, or customer_admin
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'global_admin', 'customer_admin'])) {
    header("Location: unauthorized.php");
    exit();
}

// Database connection
require_once '../config.php';
require_once __DIR__ . '/notification_helpers.php';
$conn = getDBConnection();
if (!$conn) {
    die("Database connection failed.");
}

// Get Google Maps API key from config
$google_maps_api_key = getGoogleMapsApiKey();

// Unified dashboard link
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
$duplicate_manufacturers = [];

// Prepare variables to hold user messages:
$successMessage = "";
$errorMessage   = "";

// Normalize country input so US variants persist as 'USA'
function slp_normalize_country($country) {
    $trimmed = trim((string)$country);
    $upper = strtoupper($trimmed);
    $mapToUsa = [
        'US',
        'U.S.',
        'U.S.A.',
        'UNITED STATES',
        'UNITED STATES OF AMERICA',
        'AMERICA'
    ];
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

if ($request_id > 0 && in_array($role, ['admin', 'global_admin'], true)) {
    $stmtRequest = $conn->prepare("
        SELECT mr.*, ca.name AS account_name,
               u.username, u.first_name, u.last_name
        FROM manufacturer_requests mr
        JOIN customer_accounts ca ON ca.id = mr.account_id
        JOIN users u ON u.id = mr.requested_by
        WHERE mr.id = ?
    ");
    if ($stmtRequest) {
        $stmtRequest->bind_param("i", $request_id);
        $stmtRequest->execute();
        $resultRequest = $stmtRequest->get_result();
        $request_row = $resultRequest->fetch_assoc();
        $stmtRequest->close();
        if (!$request_row) {
            $errorMessage = 'Manufacturer request not found.';
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

if ($review_request && !empty($request_data['name'])) {
    $stmtDupes = $conn->prepare("SELECT id, name FROM manufacturers WHERE LOWER(name) = LOWER(?)");
    if ($stmtDupes) {
        $stmtDupes->bind_param("s", $request_data['name']);
        $stmtDupes->execute();
        $resultDupes = $stmtDupes->get_result();
        while ($row = $resultDupes->fetch_assoc()) {
            $duplicate_manufacturers[] = $row;
        }
        $stmtDupes->close();
    }
}

// Process form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if ($request_id > 0 && !$review_request && in_array($role, ['admin', 'global_admin'], true)) {
            throw new Exception('Unable to load the manufacturer request.');
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

            $stmtReject = $conn->prepare("UPDATE manufacturer_requests SET status = 'rejected', reviewed_by = ?, reviewed_at = NOW(), rejection_reason = ? WHERE id = ?");
            if (!$stmtReject) {
                throw new Exception("Error preparing rejection update: " . $conn->error);
            }
            $stmtReject->bind_param("isi", $user_id, $rejection_reason, $request_id);
            if (!$stmtReject->execute()) {
                throw new Exception("Error rejecting request: " . $stmtReject->error);
            }
            $stmtReject->close();

            if (!empty($request_data['requested_by'])) {
                $title = 'Manufacturer request rejected';
                $message = "Your request for '{$request_data['name']}' was rejected.";
                $message .= "\nReason: {$rejection_reason}";
                notify_user((int)$request_data['requested_by'], 'manufacturer_request', $title, $message, 'manufacturers.php');
            }

            $successMessage = 'Manufacturer request rejected.';
        } else {
            // Gather form fields
            $name = trim($_POST['name'] ?? '');
            $short_name = trim($_POST['short_name'] ?? '');
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
                throw new Exception("Manufacturer Name is required.");
            }
            if ($street_address === '' || $city === '') {
                throw new Exception("Street address and city are required.");
            }
            if ($country === '') {
                throw new Exception("Country is required.");
            }
            if (strtoupper($country) === 'USA') {
                if ($state === '' || $zip_code === '') {
                    throw new Exception("State and ZIP code are required for USA addresses.");
                }
            }

            // Handle logo upload if provided
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

                    $target_dir = "uploads/manufacturer_logos/";
                    if (!is_dir($target_dir)) {
                        if (!mkdir($target_dir, 0755, true)) {
                            throw new Exception("Failed to create upload directory.");
                        }
                    }

                    $unique_name = uniqid('mfg_', true).'.'.$file_ext;
                    $target_file = $target_dir . $unique_name;

                    if (!move_uploaded_file($file_tmp, $target_file)) {
                        throw new Exception("Error uploading logo file.");
                    }
                    $logo_url = $target_file;
                } else {
                    throw new Exception("File upload error code: " . $_FILES['logo_file']['error']);
                }
            }

            if ($role === 'customer_admin') {
                $stmt = $conn->prepare("
                    INSERT INTO manufacturer_requests (
                        account_id,
                        requested_by,
                        status,
                        name,
                        short_name,
                        contact_person,
                        phone,
                        email,
                        website,
                        street_address,
                        city,
                        state,
                        zip_code,
                        country,
                        logo_url,
                        is_active,
                        notes
                    ) VALUES (?, ?, 'pending', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                if (!$stmt) {
                    throw new Exception("Error preparing request insert: " . $conn->error);
                }
                $stmt->bind_param(
                    "iissssssssssssis",
                    $account_id,
                    $user_id,
                    $name,
                    $short_name,
                    $contact_person,
                    $phone,
                    $email,
                    $website,
                    $street_address,
                    $city,
                    $state,
                    $zip_code,
                    $country,
                    $logo_url,
                    $is_active,
                    $notes
                );
                if (!$stmt->execute()) {
                    throw new Exception("Error submitting request: " . $stmt->error);
                }
                $stmt->close();

                $accountName = '';
                $stmtAccountName = $conn->prepare("SELECT name FROM customer_accounts WHERE id = ?");
                if ($stmtAccountName) {
                    $stmtAccountName->bind_param("i", $account_id);
                    $stmtAccountName->execute();
                    $stmtAccountName->bind_result($accountName);
                    $stmtAccountName->fetch();
                    $stmtAccountName->close();
                }
                $requester = $_SESSION['username'] ?? 'Customer Admin';
                $title = 'New manufacturer request';
                $message = "{$requester} submitted a manufacturer request for '{$name}'.";
                if ($accountName) {
                    $message .= "\nAccount: {$accountName}";
                }
                $recipients = array_unique(array_merge(
                    slp_get_account_admin_ids($conn, $account_id),
                    slp_get_global_admin_ids($conn)
                ));
                foreach ($recipients as $recipient_id) {
                    notify_user((int)$recipient_id, 'manufacturer_request', $title, $message, 'manufacturers.php');
                }

                $successMessage = "Manufacturer request submitted successfully!";
            } else {
                $stmt = $conn->prepare("
                    INSERT INTO manufacturers (
                        name,
                        short_name,
                        contact_person,
                        phone,
                        email,
                        website,
                        street_address,
                        city,
                        state,
                        zip_code,
                        country,
                        logo_url,
                        is_active,
                        notes
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                if (!$stmt) {
                    throw new Exception("Error preparing manufacturer insert: " . $conn->error);
                }
                $stmt->bind_param(
                    "ssssssssssssis",
                    $name,
                    $short_name,
                    $contact_person,
                    $phone,
                    $email,
                    $website,
                    $street_address,
                    $city,
                    $state,
                    $zip_code,
                    $country,
                    $logo_url,
                    $is_active,
                    $notes
                );
                if (!$stmt->execute()) {
                    throw new Exception("Error inserting manufacturer: " . $stmt->error);
                }
                $manufacturer_id = $conn->insert_id;
                $stmt->close();

                $location_stmt = $conn->prepare("
                    INSERT INTO manufacturer_locations (
                        manufacturer_id,
                        location_name,
                        street_address,
                        city,
                        state,
                        zip_code,
                        country,
                        is_primary,
                        is_active
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                if (!$location_stmt) {
                    throw new Exception("Error preparing location insert: " . $conn->error);
                }
                $location_name = "Main Location";
                $is_primary = 1;
                $location_is_active = 1;
                $location_stmt->bind_param(
                    "issssssii",
                    $manufacturer_id,
                    $location_name,
                    $street_address,
                    $city,
                    $state,
                    $zip_code,
                    $country,
                    $is_primary,
                    $location_is_active
                );
                if (!$location_stmt->execute()) {
                    throw new Exception("Error inserting manufacturer location: " . $location_stmt->error);
                }
                $location_stmt->close();

                if ($review_request) {
                    $stmtApprove = $conn->prepare("UPDATE manufacturer_requests SET status = 'approved', reviewed_by = ?, reviewed_at = NOW(), approved_manufacturer_id = ? WHERE id = ?");
                    if (!$stmtApprove) {
                        throw new Exception("Error preparing approval update: " . $conn->error);
                    }
                    $stmtApprove->bind_param("iii", $user_id, $manufacturer_id, $request_id);
                    if (!$stmtApprove->execute()) {
                        throw new Exception("Error approving request: " . $stmtApprove->error);
                    }
                    $stmtApprove->close();

                    if (!empty($request_data['requested_by'])) {
                        $title = 'Manufacturer request approved';
                        $message = "Your request for '{$name}' has been approved and added.";
                        notify_user((int)$request_data['requested_by'], 'manufacturer_request', $title, $message, 'manufacturers.php');
                    }

                    $successMessage = "Manufacturer request approved and added successfully!";
                } else {
                    $successMessage = "Manufacturer and main location added successfully!";
                }
            }
        }
    } catch (Exception $ex) {
        $errorMessage = $ex->getMessage();
    }
}

$page_title = 'Add Manufacturer';
$page_heading = 'Add Manufacturer';
$submit_label = 'Add Manufacturer';
if ($role === 'customer_admin') {
    $page_title = 'Request Manufacturer';
    $page_heading = 'Request Manufacturer';
    $submit_label = 'Submit Request';
}
if ($review_request) {
    $page_title = 'Review Manufacturer Request';
    $page_heading = 'Review Manufacturer Request';
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
        /* Address grid layout */
        .address-grid {
            display: grid;
            grid-template-columns: 2fr 1fr 1fr;
            gap: 15px;
            margin-top: 5px;
        }
        .address-grid input {
            margin-top: 0;
        }
        /* Contact grid layout */
        .contact-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
            margin-top: 5px;
        }
        .contact-grid input {
            margin-top: 0;
        }
        /* Checkbox styling */
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
        .success-message {
            color: #155724;
            background-color: #d4edda;
            border: 1px solid #c3e6cb;
            padding: 15px;
            margin: 20px 0;
            text-align: center;
            border-radius: 4px;
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
        .warning-message {
            color: #856404;
            background-color: #fff3cd;
            border: 1px solid #ffeeba;
            padding: 15px;
            margin: 20px 0;
            border-radius: 4px;
        }
        .review-banner {
            color: #1f2937;
            background-color: #eef2ff;
            border: 1px solid #c7d2fe;
            padding: 15px;
            margin: 20px 0;
            border-radius: 4px;
        }
        .btn-submit.btn-reject {
            background: #b91c1c;
        }
        .btn-submit.btn-reject:hover {
            background: #991b1b;
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
            'current_label' => $page_heading,
            'extra' => [ ['label' => 'Manage Manufacturers', 'url' => 'manufacturers.php'] ]
        ]);
    ?>

    <h1><?php echo htmlspecialchars($page_heading); ?></h1>

    <?php if ($review_request): ?>
        <div class="review-banner">
            <strong>Reviewing request</strong><br>
            Account: <?php echo htmlspecialchars($request_account_name); ?><br>
            Requested by: <?php echo htmlspecialchars($requester_label ?: 'Unknown'); ?>
        </div>
    <?php endif; ?>

    <?php if (!empty($duplicate_manufacturers)): ?>
        <div class="warning-message">
            <strong>Possible duplicate:</strong> A manufacturer with the same name already exists.
        </div>
    <?php endif; ?>

    <!-- Display success or error messages if any -->
    <?php if (!empty($successMessage)): ?>
        <div class="success-message"><strong><?php echo htmlspecialchars($successMessage); ?></strong></div>
    <?php endif; ?>

    <?php if (!empty($errorMessage)): ?>
        <div class="error-message"><strong>Error:</strong> <?php echo htmlspecialchars($errorMessage); ?></div>
    <?php endif; ?>

    <!-- The manufacturer form -->
    <form action="" method="POST" enctype="multipart/form-data">
        <?php if ($review_request): ?>
            <input type="hidden" name="request_id" value="<?php echo (int)$request_id; ?>">
        <?php endif; ?>
        <div class="section-title">Basic Information</div>
        
        <label for="name">Manufacturer Name: <span style="color: red;">*</span></label>
        <input type="text" id="name" name="name" required placeholder="e.g., JinkoSolar Holding Co., Ltd." value="<?php echo htmlspecialchars(slp_form_value('name', $request_data)); ?>">

        <label for="short_name">Short Name/Abbreviation:</label>
        <input type="text" id="short_name" name="short_name" placeholder="e.g., JinkoSolar" value="<?php echo htmlspecialchars(slp_form_value('short_name', $request_data)); ?>">
        <div class="form-note">Optional short name or common abbreviation</div>

        <div class="section-title">Contact Information</div>
        
        <label for="contact_person">Contact Person:</label>
        <input type="text" id="contact_person" name="contact_person" placeholder="e.g., John Smith" value="<?php echo htmlspecialchars(slp_form_value('contact_person', $request_data)); ?>">

        <div class="contact-grid">
            <div>
                <label for="phone" style="margin-top: 0; font-size: 0.9em; color: #666;">Phone:</label>
                <input type="tel" id="phone" name="phone" placeholder="(555) 123-4567" value="<?php echo htmlspecialchars(slp_form_value('phone', $request_data)); ?>">
            </div>
            <div>
                <label for="email" style="margin-top: 0; font-size: 0.9em; color: #666;">Email:</label>
                <input type="email" id="email" name="email" placeholder="contact@manufacturer.com" value="<?php echo htmlspecialchars(slp_form_value('email', $request_data)); ?>">
            </div>
        </div>

        <label for="website">Website:</label>
        <input type="url" id="website" name="website" placeholder="https://www.manufacturer.com" value="<?php echo htmlspecialchars(slp_form_value('website', $request_data)); ?>">

        <div class="section-title">Address <span style="color: red;">*</span></div>
        <div class="form-note">At least one address field is required</div>
        
        <div class="address-grid">
            <div>
                <label for="street_address" style="margin-top: 0; font-size: 0.9em; color: #666;">Street Address:</label>
                <input type="text" id="street_address" name="street_address" placeholder="123 Manufacturing Blvd" value="<?php echo htmlspecialchars(slp_form_value('street_address', $request_data)); ?>">
            </div>
            <div>
                <label for="city" style="margin-top: 0; font-size: 0.9em; color: #666;">City:</label>
                <input type="text" id="city" name="city" placeholder="Phoenix" value="<?php echo htmlspecialchars(slp_form_value('city', $request_data)); ?>">
            </div>
            <div>
                <label for="state" style="margin-top: 0; font-size: 0.9em; color: #666;">State/Province:</label>
                <input type="text" id="state" name="state" placeholder="AZ" value="<?php echo htmlspecialchars(slp_form_value('state', $request_data)); ?>">
            </div>
            <div>
                <label for="zip_code" style="margin-top: 0; font-size: 0.9em; color: #666;">Zip/Postal Code:</label>
                <input type="text" id="zip_code" name="zip_code" placeholder="85281" value="<?php echo htmlspecialchars(slp_form_value('zip_code', $request_data)); ?>">
            </div>
        </div>

        <label for="country">Country:</label>
        <input type="text" id="country" name="country" value="<?php echo htmlspecialchars(slp_form_value('country', $request_data, 'USA')); ?>" placeholder="USA">

        <div class="section-title">Additional Information</div>
        
        <label for="logo_file">Company Logo:</label>
        <input type="file" id="logo_file" name="logo_file" accept="image/*">
        <div class="form-note">Optional company logo (JPG, PNG, GIF, SVG - Max 5MB)</div>

        <div class="checkbox-container">
            <input type="checkbox" id="is_active" name="is_active" <?php echo $is_active_checked ? 'checked' : ''; ?>>
            <label for="is_active" style="margin-top: 0;">Active Manufacturer</label>
        </div>
        <div class="form-note">Uncheck to mark as inactive</div>

        <label for="notes">Notes:</label>
        <textarea id="notes" name="notes" placeholder="Additional notes about this manufacturer..."><?php echo htmlspecialchars(slp_form_value('notes', $request_data)); ?></textarea>

        <?php if ($review_request): ?>
            <div class="section-title">Request Review</div>
            <label for="rejection_reason">Rejection Reason (required to reject)</label>
            <textarea id="rejection_reason" name="rejection_reason" placeholder="Provide a reason if rejecting this request..."><?php echo htmlspecialchars($_POST['rejection_reason'] ?? ''); ?></textarea>
        <?php endif; ?>

        <div class="btn-submit-container">
            <?php if ($review_request): ?>
                <button type="submit" name="request_action" value="approve" class="btn-submit"><?php echo htmlspecialchars($submit_label); ?></button>
                <button type="submit" name="request_action" value="reject" class="btn-submit btn-reject">Reject Request</button>
            <?php else: ?>
                <input type="submit" value="<?php echo htmlspecialchars($submit_label); ?>" class="btn-submit">
            <?php endif; ?>
        </div>
    </form>
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
        if (countryInput) countryInput.value = '';
        
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
                case 'sublocality_level_1': // For international addresses
                    if (!cityInput.value) cityInput.value = val;
                    break;
                case 'administrative_area_level_1':
                    // For US: use short name (CA), for international: use long name (Gujarat)
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
                        countryInput.value = (shortName === 'US') ? 'USA' : val; // Normalize US to 'USA'
                    }
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
