<?php
session_name("logistics_session");
session_start();

// Check if the user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login");
    exit();
}

require_once '../config.php';
require_once 'notification_helpers.php';

$conn = getDBConnection();
if (!$conn) {
    die("Connection failed");
}

$user_id = $_SESSION['user_id'];
$role = $_SESSION['role'] ?? 'user';

// Fetch the current user's record
$sqlSelect = "SELECT username, email, password, first_name, last_name FROM users WHERE id = ? LIMIT 1";
$stmt = $conn->prepare($sqlSelect);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$userData = $result->fetch_assoc();
$stmt->close();

if (!$userData) {
    header("Location: logout");
    exit();
}

// Get notification settings
ensure_notification_settings($user_id);
$notif_settings = notification_settings_for($user_id);

// Pre-fill from database
$existingUsername = $userData['username'] ?? '';
$existingEmail = $userData['email'] ?? '';
$existingFirstName = $userData['first_name'] ?? '';
$existingLastName = $userData['last_name'] ?? '';
$dbPass = $userData['password'] ?? '';

// Get user stats
$projectCount = 0;

// Count projects user has access to
if ($role === 'global_admin') {
    $stmt = $conn->prepare("SELECT COUNT(*) FROM projects");
    $stmt->execute();
    $stmt->bind_result($projectCount);
    $stmt->fetch();
    $stmt->close();
} else {
    $stmt = $conn->prepare("
        SELECT COUNT(DISTINCT p.id)
        FROM projects p
        JOIN customer_account_users cau ON p.account_id = cau.account_id
        WHERE cau.user_id = ?
    ");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $stmt->bind_result($projectCount);
    $stmt->fetch();
    $stmt->close();
}

// Document count removed - no longer displayed

$errors = [];
$successMessage = "";

// Check if the form has been submitted
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Check which form was submitted
    if (isset($_POST['form_type'])) {
        if ($_POST['form_type'] === 'profile') {
            // Profile Information Update
            $firstName = trim($_POST['first_name'] ?? '');
            $lastName = trim($_POST['last_name'] ?? '');
            $username = trim($_POST['username'] ?? '');
            $email = trim($_POST['email'] ?? '');

    // Validate username
    if (empty($username)) {
        $errors[] = "Username cannot be empty.";
    }

    // Handle email (optional)
    $finalEmail = null;
    if ($email !== "") {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = "Invalid email format.";
        } else {
                    $finalEmail = $email;
                }
            }
            
    if (empty($errors)) {
                $sqlUpdate = "UPDATE users SET username = ?, email = ?, first_name = ?, last_name = ? WHERE id = ?";
                $stmtUpdate = $conn->prepare($sqlUpdate);
                $stmtUpdate->bind_param("ssssi", $username, $finalEmail, $firstName, $lastName, $user_id);
                
                if ($stmtUpdate->execute()) {
                    $successMessage = "Profile updated successfully!";
                    $existingUsername = $username;
                    $existingEmail = $finalEmail;
                    $existingFirstName = $firstName;
                    $existingLastName = $lastName;
                    $_SESSION['username'] = $username; // Update session
                } else {
                    $errors[] = "Update failed: " . $stmtUpdate->error;
                }
                $stmtUpdate->close();
            }
        } elseif ($_POST['form_type'] === 'password') {
            // Password Change
            $currentPass = $_POST['current_password'] ?? '';
            $newPass = $_POST['new_password'] ?? '';
            $confirmNewPass = $_POST['confirm_new_password'] ?? '';
            
            if (empty($currentPass)) {
                $errors[] = "You must enter your current password.";
            } elseif (!password_verify($currentPass, $dbPass)) {
                $errors[] = "Current password is incorrect.";
            }
            
            if ($newPass !== $confirmNewPass) {
                $errors[] = "New password and confirmation do not match.";
            }
            
            if (strlen($newPass) < 8) {
                $errors[] = "New password must be at least 8 characters long.";
            }
            
        if (empty($errors)) {
                $hashedNewPass = password_hash($newPass, PASSWORD_DEFAULT);
                $sqlUpdate = "UPDATE users SET password = ? WHERE id = ?";
                $stmtUpdate = $conn->prepare($sqlUpdate);
                $stmtUpdate->bind_param("si", $hashedNewPass, $user_id);
                
                if ($stmtUpdate->execute()) {
                    $successMessage = "Password changed successfully!";
                    $dbPass = $hashedNewPass;
            } else {
                    $errors[] = "Password update failed: " . $stmtUpdate->error;
                }
                $stmtUpdate->close();
            }
        } elseif ($_POST['form_type'] === 'notifications') {
            // Notification Settings Update
            $in_app_document_upload = isset($_POST['in_app_document_upload']) ? 1 : 0;
            $in_app_project_update = isset($_POST['in_app_project_update']) ? 1 : 0;
            $in_app_delivery_status = isset($_POST['in_app_delivery_status']) ? 1 : 0;
            $in_app_warranty_claim = isset($_POST['in_app_warranty_claim']) ? 1 : 0;
            $in_app_manufacturer_request = isset($_POST['in_app_manufacturer_request']) ? 1 : 0;
            
            $email_enabled = isset($_POST['email_enabled']) ? 1 : 0;
            $email_document_upload = ($email_enabled && isset($_POST['email_document_upload'])) ? 1 : 0;
            $email_project_update = ($email_enabled && isset($_POST['email_project_update'])) ? 1 : 0;
            $email_delivery_status = ($email_enabled && isset($_POST['email_delivery_status'])) ? 1 : 0;
            $email_warranty_claim = ($email_enabled && isset($_POST['email_warranty_claim'])) ? 1 : 0;
            $email_manufacturer_request = ($email_enabled && isset($_POST['email_manufacturer_request'])) ? 1 : 0;
            
            $stmt = $conn->prepare("
                UPDATE notification_settings 
                SET in_app_document_upload = ?,
                    in_app_project_update = ?,
                    in_app_delivery_status = ?,
                    in_app_warranty_claim = ?,
                    in_app_manufacturer_request = ?,
                    email_enabled = ?,
                    email_document_upload = ?,
                    email_project_update = ?,
                    email_delivery_status = ?,
                    email_warranty_claim = ?,
                    email_manufacturer_request = ?
                WHERE user_id = ?
            ");
            
            $stmt->bind_param('iiiiiiiiiiii',
                $in_app_document_upload,
                $in_app_project_update,
                $in_app_delivery_status,
                $in_app_warranty_claim,
                $in_app_manufacturer_request,
                $email_enabled,
                $email_document_upload,
                $email_project_update,
                $email_delivery_status,
                $email_warranty_claim,
                $email_manufacturer_request,
                $user_id
            );
            
            if ($stmt->execute()) {
                $successMessage = "Notification settings saved successfully!";
                $notif_settings = notification_settings_for($user_id); // Refresh
            } else {
                $errors[] = "Failed to save notification settings.";
            }
            $stmt->close();
        }
    }
}

$conn->close();

// Build display name for hero section
$displayName = trim($existingFirstName . ' ' . $existingLastName);
if (empty($displayName)) {
    $displayName = $existingUsername;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profile - Solterra Logistics</title>
    <link rel="stylesheet" href="portal.css">
    <link rel="icon" href="pictures/favicon.png" type="image/x-icon">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        body {
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            min-height: 100vh;
        }
        
        .profile-container {
            padding: 30px 20px;
        }
        
        /* Header Section - Matching global_documents.php */
        .global-documents-header {
            background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%);
            border-radius: 24px;
            padding: 32px;
            margin-bottom: 40px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.06);
            border: 1px solid rgba(72, 140, 154, 0.08);
            position: relative;
            overflow: hidden;
        }

        .global-documents-header::before {
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

        .header-icon {
            width: 80px;
            height: 80px;
            background: linear-gradient(135deg, #488C9A 0%, #3A6E7F 100%);
            border-radius: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 32px;
            box-shadow: 0 12px 24px rgba(72, 140, 154, 0.3);
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

        .header-stats {
            display: flex;
            gap: 24px;
            flex-wrap: wrap;
        }

        .stat-item {
            text-align: center;
            background: rgba(72, 140, 154, 0.08);
            padding: 16px 20px;
            border-radius: 16px;
            min-width: 120px;
        }

        .stat-number {
            font-size: 2em;
            font-weight: 700;
            color: #488C9A;
            margin: 0;
            line-height: 1;
        }

        .stat-label {
            font-size: 0.9em;
            color: #6c757d;
            margin: 4px 0 0 0;
            font-weight: 500;
        }
        
        /* Content Grid */
        .profile-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 25px;
        }
        
        @media (max-width: 968px) {
            .profile-grid {
                grid-template-columns: 1fr;
            }
            
            .hero-content {
                flex-direction: column;
                text-align: center;
            }
            
            .hero-stats {
                justify-content: center;
            }
        }
        
        /* Card Styles */
        .profile-card {
            background: white;
            border-radius: 20px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.08);
            overflow: hidden;
            border: 1px solid #e9ecef;
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }
        
        .profile-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 20px 50px rgba(0, 0, 0, 0.12);
        }
        
        .card-header {
            padding: 25px 30px;
            background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%);
            border-bottom: 1px solid #e9ecef;
        }
        
        .card-header h2 {
            margin: 0;
            font-size: 1.4em;
            font-weight: 600;
            color: #293E4C;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .card-body {
            padding: 30px;
        }

        .form-group {
            margin-bottom: 25px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #293E4C;
            font-size: 0.95em;
        }

        .form-group input {
            width: 100%;
            padding: 14px 18px;
            border: 2px solid #e9ecef;
            border-radius: 12px;
            font-size: 1em;
            font-family: 'Poppins', sans-serif;
            transition: all 0.3s ease;
            background: #f8f9fa;
        }

        .form-group input:focus {
            border-color: #488C9A;
            outline: none;
            background: white;
            box-shadow: 0 0 0 4px rgba(72, 140, 154, 0.1);
        }
        
        .form-group input:disabled {
            background: #e9ecef;
            cursor: not-allowed;
            color: #6c757d;
        }
        
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
        }
        
        @media (max-width: 600px) {
            .form-row {
                grid-template-columns: 1fr;
            }
        }
        
        .btn {
            padding: 14px 28px;
            border-radius: 12px;
            border: none;
            font-weight: 600;
            font-size: 1em;
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-family: 'Poppins', sans-serif;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #488C9A 0%, #3A6E7F 100%);
            color: white;
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(72, 140, 154, 0.3);
        }
        
        .btn-secondary {
            background: #f8f9fa;
            color: #293E4C;
            border: 2px solid #e9ecef;
        }
        
        .btn-secondary:hover {
            background: #e9ecef;
        }
        
        /* Collapsible Section */
        .collapsible-trigger {
            width: 100%;
            padding: 18px 30px;
            background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%);
            border: 2px solid #e9ecef;
            border-radius: 12px;
            font-weight: 600;
            font-size: 1em;
            color: #293E4C;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: space-between;
            transition: all 0.3s ease;
            font-family: 'Poppins', sans-serif;
        }
        
        .collapsible-trigger:hover {
            background: linear-gradient(135deg, #e9ecef 0%, #f8f9fa 100%);
            border-color: #488C9A;
        }
        
        .collapsible-trigger.active {
            background: linear-gradient(135deg, #488C9A 0%, #3A6E7F 100%);
            color: white;
            border-color: #488C9A;
        }
        
        .collapsible-content {
            max-height: 0;
            overflow: hidden;
            transition: max-height 0.4s ease, margin 0.4s ease;
        }
        
        .collapsible-content.active {
            max-height: 600px;
            margin-top: 20px;
        }
        
        /* Notification Settings */
        .form-check {
            display: flex;
            align-items: center;
            margin-bottom: 15px;
            padding: 12px;
            border-radius: 10px;
            transition: background 0.3s ease;
        }
        
        .form-check:hover {
            background: #f8f9fa;
        }
        
        .form-check input[type="checkbox"] {
            width: 22px;
            height: 22px;
            margin-right: 12px;
            cursor: pointer;
            accent-color: #488C9A;
        }
        
        .form-check label {
            flex: 1;
            cursor: pointer;
            color: #293E4C;
            font-weight: 500;
            margin: 0;
        }
        
        .form-check.sub-option {
            margin-left: 34px;
            background: #f8f9fa;
        }
        
        .form-check.sub-option:hover {
            background: #e9ecef;
        }
        
        .settings-section {
            margin-bottom: 25px;
        }
        
        .settings-section-title {
            font-size: 0.85em;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: #9ca3af;
            margin: 0 0 15px 0;
        }
        
        .form-note {
            font-size: 0.9em;
            color: #6c757d;
            margin-top: 15px;
            padding: 15px;
            background: #f0f8fa;
            border-radius: 10px;
            border-left: 4px solid #488C9A;
        }
        
        /* Messages */
        .alert {
            padding: 18px 24px;
            border-radius: 12px;
            margin-bottom: 25px;
            display: flex;
            align-items: center;
            gap: 12px;
            font-weight: 500;
            animation: slideInDown 0.4s ease;
        }
        
        @keyframes slideInDown {
            from {
                opacity: 0;
                transform: translateY(-20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .alert-success {
            background: linear-gradient(135deg, #d4edda 0%, #c3e6cb 100%);
            color: #155724;
            border: 2px solid #c3e6cb;
        }
        
        .alert-error {
            background: linear-gradient(135deg, #f8d7da 0%, #f5c6cb 100%);
            color: #721c24;
            border: 2px solid #f5c6cb;
        }
        
        /* Password Strength Indicator */
        .password-strength {
            height: 6px;
            background: #e9ecef;
            border-radius: 3px;
            margin-top: 8px;
            overflow: hidden;
        }
        
        .password-strength-bar {
            height: 100%;
            width: 0%;
            transition: width 0.3s ease, background-color 0.3s ease;
            border-radius: 3px;
        }
        
        .password-strength-bar.weak { background: #dc3545; width: 33%; }
        .password-strength-bar.medium { background: #ffc107; width: 66%; }
        .password-strength-bar.strong { background: #28a745; width: 100%; }
        
        /* Full Width Card */
        .full-width {
            grid-column: 1 / -1;
        }
    </style>
</head>
<body>
<?php include 'header.php'; ?>

<div class="profile-container">
    <!-- Messages -->
    <?php if (!empty($errors)): ?>
        <div class="alert alert-error">
            <span style="font-size: 1.5em;">❌</span>
            <div>
                <strong>Error!</strong>
                <ul style="margin: 5px 0 0 0; padding-left: 20px;">
                <?php foreach ($errors as $err): ?>
                    <li><?php echo htmlspecialchars($err); ?></li>
                <?php endforeach; ?>
            </ul>
            </div>
        </div>
    <?php endif; ?>

    <?php if (!empty($successMessage)): ?>
        <div class="alert alert-success">
            <span style="font-size: 1.5em;">✅</span>
            <strong><?php echo htmlspecialchars($successMessage); ?></strong>
        </div>
    <?php endif; ?>

    <!-- Hero Section -->
    <div class="global-documents-header">
        <div class="header-content">
            <div class="header-left">
                <div class="header-icon">
                    <i class="fas fa-user-circle"></i>
                </div>
                <div class="header-info">
                    <h1><?php echo htmlspecialchars($displayName); ?></h1>
                    <p class="header-subtitle">Manage your profile and preferences</p>
                </div>
            </div>
            <div class="header-stats">
                <div class="stat-item">
                    <p class="stat-number"><?php echo $projectCount; ?></p>
                    <p class="stat-label">Projects</p>
                </div>
                <div class="stat-item">
                    <p class="stat-number"><?php echo unread_notification_count($user_id); ?></p>
                    <p class="stat-label">Notifications</p>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Profile Content Grid -->
    <div class="profile-grid">
        <!-- Profile Information -->
        <div class="profile-card">
            <div class="card-header">
                <h2>👤 Profile Information</h2>
            </div>
            <div class="card-body">
                <form method="post" action="">
                    <input type="hidden" name="form_type" value="profile">
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="first_name">First Name</label>
                            <input type="text" id="first_name" name="first_name" 
                                   value="<?php echo htmlspecialchars($existingFirstName); ?>" 
                                   placeholder="Your first name">
                        </div>
                        
                        <div class="form-group">
                            <label for="last_name">Last Name</label>
                            <input type="text" id="last_name" name="last_name" 
                                   value="<?php echo htmlspecialchars($existingLastName); ?>" 
                                   placeholder="Your last name">
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="username">Username</label>
                        <input type="text" id="username" name="username" 
                               value="<?php echo htmlspecialchars($existingUsername); ?>" 
                               required>
        </div>

                    <div class="form-group">
                        <label for="email">Email Address</label>
                        <input type="email" id="email" name="email" 
                               value="<?php echo htmlspecialchars($existingEmail); ?>" 
                               placeholder="your.email@example.com">
                        <div class="form-note">
                            📧 Email is required for email notifications
                        </div>
        </div>

                    <button type="submit" class="btn btn-primary">
                        💾 Save Changes
                    </button>
                </form>
            </div>
        </div>

        <!-- Security Card -->
        <div class="profile-card">
            <div class="card-header">
                <h2>🔒 Security</h2>
            </div>
            <div class="card-body">
                <button type="button" class="collapsible-trigger" onclick="togglePasswordSection()">
                    <span>Change Password</span>
                    <span id="passwordToggleIcon">▼</span>
                </button>
                
                <div class="collapsible-content" id="passwordSection">
                    <form method="post" action="">
                        <input type="hidden" name="form_type" value="password">
                        
                        <div class="form-group">
                            <label for="current_password">Current Password</label>
                            <input type="password" id="current_password" name="current_password" required>
            </div>

            <div class="form-group">
                <label for="new_password">New Password</label>
                            <input type="password" id="new_password" name="new_password" required>
                            <div class="password-strength">
                                <div class="password-strength-bar" id="strengthBar"></div>
                            </div>
            </div>

            <div class="form-group">
                <label for="confirm_new_password">Confirm New Password</label>
                            <input type="password" id="confirm_new_password" name="confirm_new_password" required>
                        </div>
                        
                        <button type="submit" class="btn btn-primary">
                            🔐 Update Password
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <!-- Notification Preferences -->
        <div class="profile-card full-width">
            <div class="card-header">
                <h2>🔔 Notification Preferences</h2>
            </div>
            <div class="card-body">
                <form method="post" action="">
                    <input type="hidden" name="form_type" value="notifications">
                    
                    <div class="profile-grid">
                        <div class="settings-section">
                            <div class="settings-section-title">In-App Notifications</div>
                            
                            <div class="form-check">
                                <input type="checkbox" id="in_app_document_upload" name="in_app_document_upload" value="1" 
                                       <?php echo !empty($notif_settings['in_app_document_upload']) ? 'checked' : ''; ?>>
                                <label for="in_app_document_upload">📄 Document Uploads</label>
                            </div>
                            
                            <div class="form-check">
                                <input type="checkbox" id="in_app_project_update" name="in_app_project_update" value="1"
                                       <?php echo !empty($notif_settings['in_app_project_update']) ? 'checked' : ''; ?>>
                                <label for="in_app_project_update">🏗️ Project Updates</label>
                            </div>
                            
                            <div class="form-check">
                                <input type="checkbox" id="in_app_delivery_status" name="in_app_delivery_status" value="1"
                                       <?php echo !empty($notif_settings['in_app_delivery_status']) ? 'checked' : ''; ?>>
                                <label for="in_app_delivery_status">🚚 Delivery Status Changes</label>
                            </div>
                            
                            <div class="form-check">
                                <input type="checkbox" id="in_app_warranty_claim" name="in_app_warranty_claim" value="1"
                                       <?php echo !empty($notif_settings['in_app_warranty_claim']) ? 'checked' : ''; ?>>
                                <label for="in_app_warranty_claim">⚠️ Warranty Claims</label>
                            </div>

                            <div class="form-check">
                                <input type="checkbox" id="in_app_manufacturer_request" name="in_app_manufacturer_request" value="1"
                                       <?php echo !empty($notif_settings['in_app_manufacturer_request']) ? 'checked' : ''; ?>>
                                <label for="in_app_manufacturer_request">🏭 Manufacturer Requests</label>
                            </div>
                        </div>
                        
                        <div class="settings-section">
                            <div class="settings-section-title">Email Notifications</div>
                            
                            <div class="form-check">
                                <input type="checkbox" id="email_enabled" name="email_enabled" value="1"
                                       <?php echo !empty($notif_settings['email_enabled']) ? 'checked' : ''; ?>>
                                <label for="email_enabled">✉️ Enable Email Notifications</label>
                            </div>
                            
                            <div class="form-check sub-option">
                                <input type="checkbox" id="email_document_upload" name="email_document_upload" value="1"
                                       <?php echo !empty($notif_settings['email_document_upload']) ? 'checked' : ''; ?>
                                       <?php echo empty($notif_settings['email_enabled']) ? 'disabled' : ''; ?>>
                                <label for="email_document_upload">Document Uploads</label>
                            </div>
                            
                            <div class="form-check sub-option">
                                <input type="checkbox" id="email_project_update" name="email_project_update" value="1"
                                       <?php echo !empty($notif_settings['email_project_update']) ? 'checked' : ''; ?>
                                       <?php echo empty($notif_settings['email_enabled']) ? 'disabled' : ''; ?>>
                                <label for="email_project_update">Project Updates</label>
                            </div>
                            
                            <div class="form-check sub-option">
                                <input type="checkbox" id="email_delivery_status" name="email_delivery_status" value="1"
                                       <?php echo !empty($notif_settings['email_delivery_status']) ? 'checked' : ''; ?>
                                       <?php echo empty($notif_settings['email_enabled']) ? 'disabled' : ''; ?>>
                                <label for="email_delivery_status">Delivery Status Changes</label>
                            </div>
                            
                            <div class="form-check sub-option">
                                <input type="checkbox" id="email_warranty_claim" name="email_warranty_claim" value="1"
                                       <?php echo !empty($notif_settings['email_warranty_claim']) ? 'checked' : ''; ?>
                                       <?php echo empty($notif_settings['email_enabled']) ? 'disabled' : ''; ?>>
                                <label for="email_warranty_claim">Warranty Claims</label>
                            </div>
                            
                            <div class="form-check sub-option">
                                <input type="checkbox" id="email_manufacturer_request" name="email_manufacturer_request" value="1"
                                       <?php echo !empty($notif_settings['email_manufacturer_request']) ? 'checked' : ''; ?>
                                       <?php echo empty($notif_settings['email_enabled']) ? 'disabled' : ''; ?>>
                                <label for="email_manufacturer_request">Manufacturer Requests</label>
                            </div>
                            
                            <div class="form-note">
                                💡 <strong>Tip:</strong> Email notifications require a valid email address in your profile above.
                            </div>
                        </div>
                    </div>
                    
                    <button type="submit" class="btn btn-primary">
                        💾 Save Notification Settings
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
// Toggle password section
function togglePasswordSection() {
    const section = document.getElementById('passwordSection');
    const trigger = document.querySelector('.collapsible-trigger');
    const icon = document.getElementById('passwordToggleIcon');
    
    if (section.classList.contains('active')) {
        section.classList.remove('active');
        trigger.classList.remove('active');
        icon.textContent = '▼';
    } else {
        section.classList.add('active');
        trigger.classList.add('active');
        icon.textContent = '▲';
    }
}

// Password strength indicator
document.getElementById('new_password')?.addEventListener('input', function(e) {
    const password = e.target.value;
    const strengthBar = document.getElementById('strengthBar');
    
    let strength = 0;
    if (password.length >= 8) strength++;
    if (password.match(/[a-z]/) && password.match(/[A-Z]/)) strength++;
    if (password.match(/\d/)) strength++;
    if (password.match(/[^a-zA-Z\d]/)) strength++;
    
    strengthBar.className = 'password-strength-bar';
    if (strength <= 2) {
        strengthBar.classList.add('weak');
    } else if (strength === 3) {
        strengthBar.classList.add('medium');
    } else {
        strengthBar.classList.add('strong');
    }
});

// Enable/disable email sub-options based on master toggle
    document.getElementById('email_enabled').addEventListener('change', function() {
        const enabled = this.checked;
        document.getElementById('email_document_upload').disabled = !enabled;
        document.getElementById('email_project_update').disabled = !enabled;
        document.getElementById('email_delivery_status').disabled = !enabled;
        document.getElementById('email_warranty_claim').disabled = !enabled;
        document.getElementById('email_manufacturer_request').disabled = !enabled;
    });
</script>
</body>
</html>
