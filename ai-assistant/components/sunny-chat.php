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
if (empty($_SESSION['sunny_session_nonce'])) {
    $_SESSION['sunny_session_nonce'] = bin2hex(random_bytes(8));
}
$sunny_session_nonce = $_SESSION['sunny_session_nonce'];

// Get user's first name for personalized greeting
$display_name = $username; // default to username
require_once dirname(__DIR__, 3) . '/config.php';
$conn = getDBConnection();

// Determine user's account_id for data filtering
$user_account_id = null;
if ($conn) {
    // Get first name if available
    $stmt_name = $conn->prepare("SELECT first_name FROM users WHERE id = ?");
    $stmt_name->bind_param("i", $user_id);
    $stmt_name->execute();
    $stmt_name->bind_result($first_name);
    if ($stmt_name->fetch()) {
        if (!empty($first_name)) {
            $display_name = $first_name;
        }
    }
    $stmt_name->close();
    
    // For non-global admins, get their account_id
    if ($user_role !== 'global_admin') {
        $resolved_accounts = [];

        // Prefer account mapping that matches the active session role.
        $stmt = $conn->prepare("SELECT DISTINCT account_id FROM customer_account_users WHERE user_id = ? AND LOWER(role) = LOWER(?) ORDER BY account_id ASC LIMIT 2");
        $stmt->bind_param("is", $user_id, $user_role);
        $stmt->execute();
        $stmt->bind_result($account_id);
        while ($stmt->fetch()) {
            $resolved_accounts[] = (int)$account_id;
        }
        $stmt->close();

        // Legacy fallback for environments where role mapping rows are incomplete.
        if (count($resolved_accounts) === 0) {
            $stmt = $conn->prepare("SELECT DISTINCT account_id FROM customer_account_users WHERE user_id = ? ORDER BY account_id ASC LIMIT 2");
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $stmt->bind_result($account_id);
            while ($stmt->fetch()) {
                $resolved_accounts[] = (int)$account_id;
            }
            $stmt->close();
        }

        if (count($resolved_accounts) === 1) {
            $user_account_id = $resolved_accounts[0];
        } elseif (count($resolved_accounts) > 1) {
            // Keep null for safety; backend will refuse ambiguous account scope.
            error_log("Sunny account context ambiguous for user {$user_id} (role {$user_role})");
        }
    }
    $conn->close();
}
?>

<!-- Include Sunny Chat CSS -->
<link rel="stylesheet" href="ai-assistant/components/sunny-chat.css?v=<?php echo filemtime(__DIR__ . '/sunny-chat.css'); ?>">

<!-- Sunny Chat JavaScript -->
<script>
// Pass PHP session data to JavaScript
window.SunnyConfig = {
    userId: <?php echo json_encode($user_id); ?>,
    username: <?php echo json_encode($username); ?>,
    displayName: <?php echo json_encode($display_name); ?>, // First name if available, otherwise username
    userRole: <?php echo json_encode($user_role); ?>,
    userAccountId: <?php echo json_encode($user_account_id); ?>,
    userInitials: <?php echo json_encode($user_initials); ?>,
    sessionNonce: <?php echo json_encode($sunny_session_nonce); ?>,
    apiUrl: './ai-assistant/api/'
};
</script>
<script src="ai-assistant/components/sunny-chat.js?v=<?php echo filemtime(__DIR__ . '/sunny-chat.js'); ?>"></script>
