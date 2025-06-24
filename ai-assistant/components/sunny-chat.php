<?php
/**
 * Sunny Chat Assistant Component
 * Integrates with existing portal session and role system
 */

// Ensure this component is only included when user is logged in
if (!isset($_SESSION['user_id'])) {
    return; // Don't render if not logged in
}

$user_role = $_SESSION['role'] ?? 'user';
$user_id = $_SESSION['user_id'];
$username = $_SESSION['username'] ?? 'User';
$user_initials = strtoupper(substr($username, 0, 2));

// Determine user's account_id for data filtering
$user_account_id = null;
if ($user_role !== 'global_admin') {
    // For non-global admins, get their account_id
    require_once dirname(__DIR__, 3) . '/config.php';
    $conn = getDBConnection();
    if ($conn) {
        $stmt = $conn->prepare("SELECT account_id FROM customer_account_users WHERE user_id = ? LIMIT 1");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $stmt->bind_result($account_id);
        if ($stmt->fetch()) {
            $user_account_id = $account_id;
        }
        $stmt->close();
        $conn->close();
    }
}
?>

<!-- Include Sunny Chat CSS -->
<link rel="stylesheet" href="ai-assistant/components/sunny-chat.css">

<!-- Sunny Chat Container -->
<div class="sunny-chat-container" id="sunnyChatContainer">
    <!-- Chat Toggle Button -->
    <button class="sunny-chat-toggle" id="sunnyChatToggle" aria-label="Toggle Sunny Chat">
        <!-- Chat Icon -->
        <svg class="chat-icon" viewBox="0 0 24 24">
            <path d="M20 2H4c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h4l4 4 4-4h4c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2zm-2 12H6v-2h12v2zm0-3H6V9h12v2zm0-3H6V6h12v2z"/>
        </svg>
        <!-- Close Icon -->
        <svg class="close-icon" viewBox="0 0 24 24">
            <path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/>
        </svg>
        <!-- Notification Badge -->
        <div class="sunny-notification-badge" id="sunnyNotificationBadge">1</div>
    </button>

    <!-- Chat Window -->
    <div class="sunny-chat-window" id="sunnyChatWindow">
        <!-- Chat Header -->
        <div class="sunny-chat-header">
            <div class="sunny-avatar">☀️</div>
            <div class="sunny-header-info">
                <h3>Sunny</h3>
                <p>Logistics Assistant</p>
            </div>
            <div class="sunny-status-indicator" id="sunnyStatusIndicator"></div>
        </div>

        <!-- Chat Messages -->
        <div class="sunny-chat-messages" id="sunnyChatMessages">
            <div class="sunny-welcome-message">
                <h4>Hi <?php echo htmlspecialchars($username); ?>! 👋</h4>
                <p>I'm Sunny, your logistics assistant. I can help you track deliveries, check project status, and answer questions about your shipments.</p>
            </div>
            
            <!-- Quick Actions -->
            <div class="sunny-quick-actions">
                <button class="sunny-quick-action" onclick="SunnyChat.sendQuickMessage('Show my recent deliveries')">Recent Deliveries</button>
                <button class="sunny-quick-action" onclick="SunnyChat.sendQuickMessage('What is my project status?')">Project Status</button>
                <?php if ($user_role === 'global_admin' || $user_role === 'admin'): ?>
                <button class="sunny-quick-action" onclick="SunnyChat.sendQuickMessage('Show warehouse inventory summary')">Inventory Summary</button>
                <?php endif; ?>
            </div>
        </div>

        <!-- Typing Indicator -->
        <div class="sunny-typing-indicator" id="sunnyTypingIndicator" style="display: none;">
            <span>Sunny is thinking</span>
            <div class="sunny-typing-dots">
                <span></span>
                <span></span>
                <span></span>
            </div>
        </div>

        <!-- Chat Input -->
        <div class="sunny-chat-input">
            <div class="sunny-input-container">
                <textarea 
                    class="sunny-input-field" 
                    id="sunnyChatInput" 
                    placeholder="Ask me anything about your logistics..."
                    rows="1"
                    maxlength="500"
                ></textarea>
                <button class="sunny-send-button" id="sunnySendButton" type="button" aria-label="Send message">
                    <svg viewBox="0 0 24 24">
                        <path d="M2.01 21L23 12 2.01 3 2 10l15 2-15 2z"/>
                    </svg>
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Include Socket.IO Client -->
<script src="https://cdn.socket.io/4.7.4/socket.io.min.js"></script>

<!-- Sunny Chat JavaScript -->
<script>
// Pass PHP session data to JavaScript
window.SunnyConfig = {
    userId: <?php echo json_encode($user_id); ?>,
    username: <?php echo json_encode($username); ?>,
    userRole: <?php echo json_encode($user_role); ?>,
    userAccountId: <?php echo json_encode($user_account_id); ?>,
    userInitials: <?php echo json_encode($user_initials); ?>,
    socketUrl: 'https://<?php echo $_SERVER['HTTP_HOST']; ?>:3001',
    apiUrl: '/Solterra-Logistics-Portal/ai-assistant/api/'
};
</script>
<script src="ai-assistant/components/sunny-chat.js"></script> 