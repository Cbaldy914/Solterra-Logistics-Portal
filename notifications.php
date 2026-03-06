<?php
session_name("logistics_session");
    session_start();

// Check if the user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login");
    exit();
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

require_once '../config.php';
require_once 'notification_helpers.php';

$conn = getDBConnection();
$user_id = $_SESSION['user_id'];
$role = $_SESSION['role'] ?? 'user';

// Ensure notification settings exist
ensure_notification_settings($user_id);

// Get user's notification settings
$settings = notification_settings_for($user_id);
$available_settings_cols = [];
try {
    $colRes = $conn->query("SHOW COLUMNS FROM notification_settings");
    while ($c = $colRes->fetch_assoc()) {
        $available_settings_cols[] = $c['Field'];
    }
} catch (Throwable $e) {
    error_log('Notification settings column discovery error: '.$e->getMessage());
}

// Fetch recent notifications (last 50)
$stmt = $conn->prepare('
    SELECT * FROM notifications 
    WHERE user_id = ? 
    ORDER BY created_at DESC 
    LIMIT 50
');
$stmt->bind_param('i', $user_id);
$stmt->execute();
$result = $stmt->get_result();
$notifications = [];
while ($row = $result->fetch_assoc()) {
    $notifications[] = $row;
}
$stmt->close();

// Count unread
$unread_count = unread_notification_count($user_id);

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notifications - Solterra Logistics</title>
    <link rel="stylesheet" href="portal.css">
    <link rel="icon" href="pictures/favicon.png" type="image/x-icon">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        .notifications-container {
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
        
        .notifications-layout {
            display: grid;
            grid-template-columns: 1fr 400px;
            gap: 30px;
            align-items: start;
        }
        
        @media (max-width: 1024px) {
            .notifications-layout {
                grid-template-columns: 1fr;
            }
        }
        
        .notifications-card, .settings-card {
            background: #ffffff;
            border-radius: 20px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.08);
            overflow: hidden;
            border: 1px solid #e9ecef;
        }
        
        .card-header {
            padding: 25px 30px;
            background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%);
            border-bottom: 1px solid #e9ecef;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .card-header h2 {
            margin: 0;
            font-size: 1.4em;
            font-weight: 600;
            color: #293E4C;
        }
        
        .card-header .subtitle {
            font-size: 0.85em;
            color: #6c757d;
            font-weight: 400;
            margin-top: 5px;
        }
        
        .card-actions {
            display: flex;
            gap: 10px;
        }
        
        .btn {
            padding: 10px 20px;
            border-radius: 10px;
            border: none;
            font-weight: 500;
            font-size: 0.9em;
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #488C9A 0%, #3A6E7F 100%);
            color: white;
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(72, 140, 154, 0.3);
        }
        
        .btn-secondary {
            background: #f8f9fa;
            color: #293E4C;
            border: 1px solid #e9ecef;
        }
        
        .btn-secondary:hover {
            background: #e9ecef;
        }
        
        .btn-danger {
            background: #fff;
            color: #dc3545;
            border: 1px solid #dc3545;
        }
        
        .btn-danger:hover {
            background: #dc3545;
            color: white;
        }
        
        .notifications-list {
            max-height: 700px;
            overflow-y: auto;
        }

        /* Select All Row */
        .select-all-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 12px 30px;
            background: #f8f9fa;
            border-bottom: 1px solid #e9ecef;
        }

        .select-all-label {
            display: flex;
            align-items: center;
            gap: 10px;
            cursor: pointer;
            font-weight: 500;
            color: #293E4C;
        }

        .selected-count {
            font-size: 0.9em;
            color: #6c757d;
            font-weight: 500;
        }
        
        .notification-item {
            padding: 20px 30px;
            border-bottom: 1px solid #f1f3f4;
            display: flex;
            align-items: start;
            gap: 15px;
            transition: all 0.3s ease;
            cursor: pointer;
        }
        
        .notification-item:hover {
            background: #f8f9fa;
        }
        
        .notification-item.unread {
            background: #f0f8fa;
        }
        
        .notification-item.unread:hover {
            background: #e6f4f7;
        }
        
        .notification-checkbox {
            width: 20px;
            height: 20px;
            margin-top: 2px;
            cursor: pointer;
            accent-color: #488C9A;
        }
        
        .notification-icon {
            width: 45px;
            height: 45px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.3em;
            flex-shrink: 0;
        }
        
        .notification-icon.document_upload {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }
        
        .notification-icon.project_update {
            background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
        }
        
        .notification-icon.delivery_status {
            background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
        }
        
        .notification-icon.warranty_claim {
            background: linear-gradient(135deg, #fa709a 0%, #fee140 100%);
        }
        
        .notification-content {
            flex: 1;
            min-width: 0;
        }
        
        .notification-title {
            font-weight: 600;
            color: #293E4C;
            margin: 0 0 5px 0;
            font-size: 1em;
        }
        
        .notification-message {
            color: #6c757d;
            font-size: 0.9em;
            margin: 0 0 8px 0;
            line-height: 1.5;
        }
        
        .notification-time {
            color: #9ca3af;
            font-size: 0.85em;
            display: flex;
            align-items: center;
            gap: 5px;
        }
        
        .notification-link {
            margin-top: 10px;
        }
        
        .notification-link .btn {
            padding: 8px 16px;
            font-size: 0.85em;
        }
        
        .empty-state {
            padding: 60px 30px;
            text-align: center;
            color: #9ca3af;
        }
        
        .empty-state-icon {
            font-size: 4em;
            margin-bottom: 20px;
            opacity: 0.3;
        }
        
        .empty-state-text {
            font-size: 1.1em;
            color: #6c757d;
        }
        
        /* Settings Form */
        .settings-form {
            padding: 30px;
        }
        
        .settings-section {
            margin-bottom: 30px;
        }
        
        .settings-section:last-child {
            margin-bottom: 0;
        }
        
        .settings-section-title {
            font-size: 0.9em;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #9ca3af;
            margin: 0 0 15px 0;
        }
        
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
            width: 20px;
            height: 20px;
            margin-right: 12px;
            cursor: pointer;
            accent-color: #488C9A;
        }
        
        .form-check label {
            flex: 1;
            cursor: pointer;
            color: #293E4C;
            font-weight: 500;
        }
        
        .form-check.sub-option {
            margin-left: 32px;
            background: #f8f9fa;
        }
        
        .form-check.sub-option:hover {
            background: #e9ecef;
        }
        
        .form-note {
            font-size: 0.85em;
            color: #6c757d;
            margin-top: 15px;
            padding: 12px;
            background: #f8f9fa;
            border-radius: 8px;
            border-left: 3px solid #488C9A;
        }
        
        .submit-actions {
            display: flex;
            gap: 10px;
            margin-top: 25px;
        }
        
        /* Scrollbar Styling */
        .notifications-list::-webkit-scrollbar {
            width: 8px;
        }
        
        .notifications-list::-webkit-scrollbar-track {
            background: #f1f3f4;
        }
        
        .notifications-list::-webkit-scrollbar-thumb {
            background: #cbd5e0;
            border-radius: 4px;
        }
        
        .notifications-list::-webkit-scrollbar-thumb:hover {
            background: #a0aec0;
        }
    </style>
</head>
<body>
<?php include 'header.php'; ?>

<div class="global-documents-header">
    <div class="header-content">
        <div class="header-left">
            <div class="header-icon">
                <i class="fas fa-bell"></i>
            </div>
            <div class="header-info">
                <h1>Notifications</h1>
                <p class="header-subtitle">Stay updated with your projects and activities</p>
            </div>
        </div>
        <div class="header-stats">
            <div class="stat-item">
                <p class="stat-number"><?php echo $unread_count; ?></p>
                <p class="stat-label">Unread</p>
            </div>
            <div class="stat-item">
                <p class="stat-number"><?php echo count($notifications); ?></p>
                <p class="stat-label">Total</p>
            </div>
        </div>
    </div>
</div>

<div class="notifications-container">
    
    <div class="notifications-layout">
        <!-- Notifications List -->
        <div class="notifications-card">
            <div class="card-header">
                <div>
                    <h2>Recent Notifications</h2>
                    <div class="subtitle"><?php echo $unread_count; ?> unread · Last 50 notifications</div>
                </div>
                <div class="card-actions">
                    <button type="button" class="btn btn-secondary" onclick="markSelectedAsRead()">
                        Mark as Read
                    </button>
                    <button type="button" class="btn btn-danger" onclick="deleteSelected()">
                        Delete
                    </button>
                </div>
            </div>

            <!-- Select All Row -->
            <?php if (!empty($notifications)): ?>
            <div class="select-all-row">
                <label class="select-all-label">
                    <input type="checkbox" id="selectAllCheckbox" class="notification-checkbox" onchange="toggleSelectAll(this)" />
                    <span>Select All</span>
                </label>
                <span class="selected-count" id="selectedCount">0 selected</span>
            </div>
            <?php endif; ?>

            <div class="notifications-list">
                <?php if (empty($notifications)): ?>
                    <div class="empty-state">
                        <div class="empty-state-icon">🔕</div>
                        <div class="empty-state-text">No notifications yet. You're all caught up!</div>
                    </div>
                <?php else: ?>
                    <?php foreach ($notifications as $notif): ?>
                        <div class="notification-item <?php echo $notif['read_at'] ? '' : 'unread'; ?>">
                            <input type="checkbox" class="notification-checkbox notification-item-checkbox" value="<?php echo $notif['id']; ?>" onchange="updateSelectedCount()" />
                            
                            <div class="notification-icon <?php echo htmlspecialchars($notif['type']); ?>">
                                <?php
                                $icons = [
                                    'document_upload' => '📄',
                                    'project_update' => '🏗️',
                                    'delivery_status' => '🚚',
                                    'warranty_claim' => '⚠️',
                                    'manufacturer_request' => '🏭'
                                ];
                                echo $icons[$notif['type']] ?? '📋';
                                ?>
                            </div>
                            
                            <div class="notification-content">
                                <div class="notification-title"><?php echo htmlspecialchars($notif['title']); ?></div>
                                <?php if (!empty($notif['message'])): ?>
                                    <div class="notification-message"><?php echo nl2br(htmlspecialchars($notif['message'])); ?></div>
                                <?php endif; ?>
                                <div class="notification-time">
                                    <span>🕒</span>
                                    <span><?php echo date('M j, Y g:i A', strtotime($notif['created_at'])); ?></span>
                                </div>
                                <?php if (!empty($notif['link'])): ?>
                                    <?php $markReadSeparator = strpos($notif['link'], '?') === false ? '?' : '&'; ?>
                                    <div class="notification-link">
                                        <a href="<?php echo htmlspecialchars($notif['link'] . $markReadSeparator . 'mark_read=' . urlencode((string)$notif['id']) . '&mark_read_token=' . urlencode((string)$_SESSION['csrf_token'])); ?>" class="btn btn-primary">
                                            View Details →
                                        </a>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Settings Panel -->
        <div class="settings-card">
            <div class="card-header">
                <div>
                    <h2>Notification Settings</h2>
                </div>
            </div>
            
            <form method="post" action="api/save_notification_settings.php" class="settings-form">
                <!-- In-App Notifications -->
                <div class="settings-section">
                    <div class="settings-section-title">In-App Notifications</div>
                    
                    <div class="form-check">
                        <input type="checkbox" id="in_app_document_upload" name="in_app_document_upload" value="1" 
                               <?php echo !empty($settings['in_app_document_upload']) ? 'checked' : ''; ?>>
                        <label for="in_app_document_upload">📄 Document Uploads</label>
                    </div>
                    
                    <div class="form-check">
                        <input type="checkbox" id="in_app_project_update" name="in_app_project_update" value="1"
                               <?php echo !empty($settings['in_app_project_update']) ? 'checked' : ''; ?>>
                        <label for="in_app_project_update">🏗️ Project Updates</label>
                    </div>
                    
                    <div class="form-check">
                        <input type="checkbox" id="in_app_delivery_status" name="in_app_delivery_status" value="1"
                               <?php echo !empty($settings['in_app_delivery_status']) ? 'checked' : ''; ?>>
                        <label for="in_app_delivery_status">🚚 Delivery Status Changes</label>
                    </div>
                    
                    <div class="form-check">
                        <input type="checkbox" id="in_app_warranty_claim" name="in_app_warranty_claim" value="1"
                               <?php echo !empty($settings['in_app_warranty_claim']) ? 'checked' : ''; ?>>
                        <label for="in_app_warranty_claim">⚠️ Warranty Claims</label>
                    </div>
                    <?php if ($role !== "user" && in_array("in_app_freight_estimate_request", $available_settings_cols, true)): ?>
                    <div class="form-check">
                        <input type="checkbox" id="in_app_freight_estimate_request" name="in_app_freight_estimate_request" value="1" 
                               <?php echo !empty($settings['in_app_freight_estimate_request']) ? "checked" : ""; ?>>
                        <label for="in_app_freight_estimate_request">🚛 Freight Requests</label>
                    </div>
                    <?php endif; ?>
                    <?php if ($role === "user" && in_array("in_app_freight_estimate_rated", $available_settings_cols, true)): ?>
                    <div class="form-check">
                        <input type="checkbox" id="in_app_freight_estimate_rated" name="in_app_freight_estimate_rated" value="1" 
                               <?php echo !empty($settings['in_app_freight_estimate_rated']) ? "checked" : ""; ?>>
                        <label for="in_app_freight_estimate_rated">✅ Freight Rates Added</label>
                    </div>
                    <?php endif; ?>

                    <?php if ($role !== "user" && in_array("in_app_warehouse_estimate_request", $available_settings_cols, true)): ?>
                    <div class="form-check">
                        <input type="checkbox" id="in_app_warehouse_estimate_request" name="in_app_warehouse_estimate_request" value="1" 
                               <?php echo !empty($settings['in_app_warehouse_estimate_request']) ? "checked" : ""; ?>>
                        <label for="in_app_warehouse_estimate_request">🏢 Warehouse Requests</label>
                    </div>
                    <?php endif; ?>
                    <?php if ($role === "user" && in_array("in_app_warehouse_estimate_rated", $available_settings_cols, true)): ?>
                    <div class="form-check">
                        <input type="checkbox" id="in_app_warehouse_estimate_rated" name="in_app_warehouse_estimate_rated" value="1" 
                               <?php echo !empty($settings['in_app_warehouse_estimate_rated']) ? "checked" : ""; ?>>
                        <label for="in_app_warehouse_estimate_rated">✅ Warehouse Rates Added</label>
                    </div>
                    <?php endif; ?>
                    <?php if (in_array("in_app_manufacturer_request", $available_settings_cols, true)): ?>
                    <div class="form-check">
                        <input type="checkbox" id="in_app_manufacturer_request" name="in_app_manufacturer_request" value="1"
                               <?php echo !empty($settings['in_app_manufacturer_request']) ? 'checked' : ''; ?>>
                        <label for="in_app_manufacturer_request">🏭 Manufacturer Requests</label>
                    </div>
                    <?php endif; ?>
                </div>
                
                <!-- Email Notifications -->
                <div class="settings-section">
                    <div class="settings-section-title">Email Notifications</div>
                    
                    <div class="form-check">
                        <input type="checkbox" id="email_enabled" name="email_enabled" value="1"
                               <?php echo !empty($settings['email_enabled']) ? 'checked' : ''; ?>>
                        <label for="email_enabled">✉️ Enable Email Notifications</label>
                    </div>
                    
                    <div class="form-check sub-option">
                        <input type="checkbox" id="email_document_upload" name="email_document_upload" value="1"
                               <?php echo !empty($settings['email_document_upload']) ? 'checked' : ''; ?>
                               <?php echo empty($settings['email_enabled']) ? 'disabled' : ''; ?>>
                        <label for="email_document_upload">Document Uploads</label>
                    </div>
                    
                    <div class="form-check sub-option">
                        <input type="checkbox" id="email_project_update" name="email_project_update" value="1"
                               <?php echo !empty($settings['email_project_update']) ? 'checked' : ''; ?>
                               <?php echo empty($settings['email_enabled']) ? 'disabled' : ''; ?>>
                        <label for="email_project_update">Project Updates</label>
                    </div>
                    
                    <div class="form-check sub-option">
                        <input type="checkbox" id="email_delivery_status" name="email_delivery_status" value="1"
                               <?php echo !empty($settings['email_delivery_status']) ? 'checked' : ''; ?>
                               <?php echo empty($settings['email_enabled']) ? 'disabled' : ''; ?>>
                        <label for="email_delivery_status">Delivery Status Changes</label>
                    </div>
                    
                    <div class="form-check sub-option">
                        <input type="checkbox" id="email_warranty_claim" name="email_warranty_claim" value="1"
                               <?php echo !empty($settings['email_warranty_claim']) ? 'checked' : ''; ?>
                               <?php echo empty($settings['email_enabled']) ? 'disabled' : ''; ?>>
                        <label for="email_warranty_claim">Warranty Claims</label>
                    </div>
                    <?php if ($role !== "user" && in_array("email_freight_estimate_request", $available_settings_cols, true)): ?>
                    <div class="form-check sub-option">
                        <input type="checkbox" id="email_freight_estimate_request" name="email_freight_estimate_request" value="1"
                               <?php echo !empty($settings['email_freight_estimate_request']) ? 'checked' : ''; ?>
                               <?php echo empty($settings['email_enabled']) ? 'disabled' : ''; ?>>
                        <label for="email_freight_estimate_request">Freight Requests</label>
                    </div>
                    <?php endif; ?>

                    <?php if ($role === "user" && in_array("email_freight_estimate_rated", $available_settings_cols, true)): ?>
                    <div class="form-check sub-option">
                        <input type="checkbox" id="email_freight_estimate_rated" name="email_freight_estimate_rated" value="1"
                               <?php echo !empty($settings['email_freight_estimate_rated']) ? 'checked' : ''; ?>
                               <?php echo empty($settings['email_enabled']) ? 'disabled' : ''; ?>>
                        <label for="email_freight_estimate_rated">Freight Rates Added</label>
                    </div>
                    <?php endif; ?>

                    <?php if ($role !== "user" && in_array("email_warehouse_estimate_request", $available_settings_cols, true)): ?>
                    <div class="form-check sub-option">
                        <input type="checkbox" id="email_warehouse_estimate_request" name="email_warehouse_estimate_request" value="1"
                               <?php echo !empty($settings['email_warehouse_estimate_request']) ? 'checked' : ''; ?>
                               <?php echo empty($settings['email_enabled']) ? 'disabled' : ''; ?>>
                        <label for="email_warehouse_estimate_request">Warehouse Requests</label>
                    </div>
                    <?php endif; ?>

                    <?php if ($role === "user" && in_array("email_warehouse_estimate_rated", $available_settings_cols, true)): ?>
                    <div class="form-check sub-option">
                        <input type="checkbox" id="email_warehouse_estimate_rated" name="email_warehouse_estimate_rated" value="1"
                               <?php echo !empty($settings['email_warehouse_estimate_rated']) ? 'checked' : ''; ?>
                               <?php echo empty($settings['email_enabled']) ? 'disabled' : ''; ?>>
                        <label for="email_warehouse_estimate_rated">Warehouse Rates Added</label>
                    </div>
                    <?php endif; ?>
                    <?php if (in_array("email_manufacturer_request", $available_settings_cols, true)): ?>
                    <div class="form-check sub-option">
                        <input type="checkbox" id="email_manufacturer_request" name="email_manufacturer_request" value="1"
                               <?php echo !empty($settings['email_manufacturer_request']) ? 'checked' : ''; ?>
                               <?php echo empty($settings['email_enabled']) ? 'disabled' : ''; ?>>
                        <label for="email_manufacturer_request">Manufacturer Requests</label>
                    </div>
                    <?php endif; ?>
                </div>
                <div style="padding: 20px 30px; display: flex; justify-content: flex-end;">
                    <button type="submit" class="btn btn-primary">Save Settings</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
const csrfToken = <?php echo json_encode($_SESSION['csrf_token']); ?>;

// Enable/disable email sub-options based on master toggle
document.getElementById('email_enabled').addEventListener('change', function() {
    const enabled = this.checked;
    document.getElementById('email_document_upload').disabled = !enabled;
    document.getElementById('email_project_update').disabled = !enabled;
    document.getElementById('email_delivery_status').disabled = !enabled;
    document.getElementById('email_warranty_claim').disabled = !enabled;
});

// Toggle select all checkboxes
function toggleSelectAll(masterCheckbox) {
    const checkboxes = document.querySelectorAll('.notification-item-checkbox');
    checkboxes.forEach(cb => {
        cb.checked = masterCheckbox.checked;
    });
    updateSelectedCount();
}

// Update the selected count display
function updateSelectedCount() {
    const checkboxes = document.querySelectorAll('.notification-item-checkbox');
    const checkedCount = document.querySelectorAll('.notification-item-checkbox:checked').length;
    const totalCount = checkboxes.length;

    document.getElementById('selectedCount').textContent = checkedCount + ' selected';

    // Update select all checkbox state
    const selectAllCheckbox = document.getElementById('selectAllCheckbox');
    if (selectAllCheckbox) {
        selectAllCheckbox.checked = checkedCount === totalCount && totalCount > 0;
        selectAllCheckbox.indeterminate = checkedCount > 0 && checkedCount < totalCount;
    }
}

// Mark selected notifications as read
function markSelectedAsRead() {
    const selected = Array.from(document.querySelectorAll('.notification-item-checkbox:checked')).map(cb => cb.value);
    if (selected.length === 0) {
        alert('Please select at least one notification');
        return;
    }
    
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = 'api/mark_notification_read.php';

    const csrfInput = document.createElement('input');
    csrfInput.type = 'hidden';
    csrfInput.name = 'csrf_token';
    csrfInput.value = csrfToken;
    form.appendChild(csrfInput);
    
    selected.forEach(id => {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'ids[]';
        input.value = id;
        form.appendChild(input);
    });
    
    document.body.appendChild(form);
    form.submit();
}

// Delete selected notifications
function deleteSelected() {
    const selected = Array.from(document.querySelectorAll('.notification-item-checkbox:checked')).map(cb => cb.value);
    if (selected.length === 0) {
        alert('Please select at least one notification');
        return;
    }
    
    if (!confirm(`Delete ${selected.length} notification(s)? This cannot be undone.`)) {
        return;
    }
    
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = 'api/delete_notifications.php';

    const csrfInput = document.createElement('input');
    csrfInput.type = 'hidden';
    csrfInput.name = 'csrf_token';
    csrfInput.value = csrfToken;
    form.appendChild(csrfInput);
    
    selected.forEach(id => {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'ids[]';
        input.value = id;
        form.appendChild(input);
    });
    
    document.body.appendChild(form);
    form.submit();
}
</script>
</body>
</html>
