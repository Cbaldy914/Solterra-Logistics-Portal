<?php
/**
 * Sunny AI Usage Status Endpoint
 * Returns current usage % and daily stats for the authenticated user
 */

header('Content-Type: application/json');
header('Cache-Control: no-cache');

// Use existing session from portal
if (session_status() === PHP_SESSION_NONE) {
    session_name('logistics_session');
    session_start();
}

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Authentication required']);
    exit;
}

try {
    // Load configuration
    $configPaths = [
        __DIR__ . '/../../../config.php',
        __DIR__ . '/../../config.php',
        dirname(__DIR__, 3) . '/config.php'
    ];
    foreach ($configPaths as $path) {
        if (file_exists($path)) {
            require_once $path;
            break;
        }
    }

    $sunnyConfig = require __DIR__ . '/../config/sunny-config.php';

    $user_id = $_SESSION['user_id'];
    $today = date('Y-m-d');
    $dailyLimit = $sunnyConfig['usage_caps']['daily_token_limit'] ?? 100000;
    $dailyRequestLimit = $sunnyConfig['usage_caps']['daily_request_limit'] ?? 100;
    $brightModel = $sunnyConfig['gemini']['models']['bright'] ?? 'gemini-3-flash';
    $brightMult = $sunnyConfig['usage_caps']['bright_multiplier'] ?? 3;

    $conn = getDBConnection();
    if (!$conn) {
        echo json_encode([
            'success' => true,
            'data' => [
                'remaining_percent' => 100,
                'daily_used' => 0,
                'daily_limit' => $dailyLimit,
                'requests_today' => 0,
                'mode' => 'default'
            ]
        ]);
        exit;
    }

    $stmt = $conn->prepare(
        "SELECT COALESCE(SUM(
            (prompt_tokens + completion_tokens) *
            CASE WHEN model = ? THEN ? ELSE 1 END
        ), 0) AS weighted_tokens,
        COALESCE(SUM(request_count), 0) AS total_requests
        FROM sunny_usage
        WHERE user_id = ? AND usage_date = ?"
    );
    $stmt->bind_param('siis', $brightModel, $brightMult, $user_id, $today);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $conn->close();

    $weightedTokens = (int)($result['weighted_tokens'] ?? 0);
    $totalRequests = (int)($result['total_requests'] ?? 0);
    $remainingPercent = max(0, round((1 - ($weightedTokens / $dailyLimit)) * 100));

    echo json_encode([
        'success' => true,
        'data' => [
            'remaining_percent' => $remainingPercent,
            'daily_used' => $weightedTokens,
            'daily_limit' => $dailyLimit,
            'requests_today' => $totalRequests,
            'mode' => 'default'
        ]
    ]);

} catch (Exception $e) {
    error_log("Usage status error: " . $e->getMessage());
    // Fail-open: return 100% remaining
    echo json_encode([
        'success' => true,
        'data' => [
            'remaining_percent' => 100,
            'daily_used' => 0,
            'daily_limit' => 100000,
            'requests_today' => 0,
            'mode' => 'default'
        ]
    ]);
}
?>
