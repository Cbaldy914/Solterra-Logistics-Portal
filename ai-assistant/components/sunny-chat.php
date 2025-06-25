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

<!-- Sunny Chat JavaScript -->
<script>
// Pass PHP session data to JavaScript
window.SunnyConfig = {
    userId: <?php echo json_encode($user_id); ?>,
    username: <?php echo json_encode($username); ?>,
    userRole: <?php echo json_encode($user_role); ?>,
    userAccountId: <?php echo json_encode($user_account_id); ?>,
    userInitials: <?php echo json_encode($user_initials); ?>,
    apiUrl: './ai-assistant/api/'
};
</script>
<script src="ai-assistant/components/sunny-chat.js"></script> 