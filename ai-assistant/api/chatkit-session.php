<?php
// ChatKit session endpoint
// Creates a ChatKit session via OpenAI API and returns the client_secret

// Session and access control
if (session_status() === PHP_SESSION_NONE) {
    session_name("logistics_session");
    session_start();
}

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit();
}

$role = $_SESSION['role'] ?? 'user';
if ($role !== 'global_admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Forbidden']);
    exit();
}

// Resolve OpenAI API key
$openai_api_key = '';

// Prefer central config helper if available
@include_once dirname(__DIR__, 3) . '/config.php';
if (function_exists('getOpenAIApiKey')) {
    try { $openai_api_key = (string) getOpenAIApiKey(); } catch (Throwable $e) { /* ignore */ }
}
if ($openai_api_key === '' && function_exists('getenv')) {
    $openai_api_key = getenv('OPENAI_API_KEY') ?: '';
}
if ($openai_api_key === '' && defined('OPENAI_API_KEY')) {
    $openai_api_key = (string) OPENAI_API_KEY;
}

if ($openai_api_key === '') {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'OpenAI API key not configured']);
    exit();
}

// Workflow ID from config/env (preferred), with safe fallback
$workflow_id = '';
if (function_exists('getChatKitWorkflowId')) {
    try { $workflow_id = (string) getChatKitWorkflowId(); } catch (Throwable $e) { /* ignore */ }
}
if ($workflow_id === '' && function_exists('getenv')) {
    $workflow_id = getenv('CHATKIT_WORKFLOW_ID') ?: '';
}
if ($workflow_id === '' && defined('CHATKIT_WORKFLOW_ID')) {
    $workflow_id = (string) CHATKIT_WORKFLOW_ID;
}
if ($workflow_id === '') {
    // Last resort: existing known id to avoid hard failure; recommend setting env/config
    $workflow_id = 'wf_68ee5cb4e8f481909a58d336a02d6ae506d8bfc069874f75';
}

// Read JSON input
$raw = file_get_contents('php://input');
$json = json_decode($raw, true);
$deviceId = '';
if (is_array($json) && isset($json['deviceId'])) {
    $deviceId = preg_replace('/[^a-zA-Z0-9_\-:.]/', '', (string) $json['deviceId']);
}
if ($deviceId === '') {
    // Fall back to a stable identifier based on session user
    $deviceId = 'user_' . (int) $_SESSION['user_id'];
}

// Prepare request to OpenAI ChatKit sessions API
$url = 'https://api.openai.com/v1/chatkit/sessions';
$payload = json_encode([
    'workflow' => ['id' => $workflow_id],
    'user' => $deviceId,
]);

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $openai_api_key,
        'OpenAI-Beta: chatkit_beta=v1',
    ],
    CURLOPT_POSTFIELDS => $payload,
    CURLOPT_CONNECTTIMEOUT => 10,   // seconds
    CURLOPT_TIMEOUT => 30,          // seconds
]);

$result = curl_exec($ch);
$error = curl_error($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($result === false) {
    http_response_code(502);
    echo json_encode(['success' => false, 'message' => 'OpenAI API request failed', 'error' => $error]);
    exit();
}

$resp = json_decode($result, true);
if (!is_array($resp)) {
    http_response_code(502);
    echo json_encode(['success' => false, 'message' => 'Invalid response from OpenAI API']);
    exit();
}

if ($httpCode < 200 || $httpCode >= 300) {
    http_response_code($httpCode);
    echo json_encode(['success' => false, 'message' => 'OpenAI API error', 'status' => $httpCode, 'response' => $resp]);
    exit();
}

$clientSecret = $resp['client_secret'] ?? null;
if (!$clientSecret) {
    http_response_code(502);
    echo json_encode(['success' => false, 'message' => 'Missing client_secret in OpenAI response', 'response' => $resp]);
    exit();
}

echo json_encode(['success' => true, 'client_secret' => $clientSecret]);
exit();
?>


