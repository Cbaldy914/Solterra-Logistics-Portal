<?php
/**
 * Test Google Gemini API Connection for Sunny AI Assistant
 * Returns single JSON response only
 */

// Set headers for JSON response
header('Content-Type: application/json');
header('Cache-Control: no-cache');

// Use existing session from portal (ensure correct session name)
if (session_status() === PHP_SESSION_NONE) {
    session_name('logistics_session');
    session_start();
}

// Check authentication
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'error' => 'Authentication required',
        'status' => 'unauthorized'
    ]);
    exit;
}

try {
    // Load configuration
    $sunnyConfig = require_once __DIR__ . '/../config/sunny-config.php';

    // Check if API key is configured
    $apiKey = $sunnyConfig['gemini']['api_key'] ?? '';
    if (empty($apiKey)) {
        echo json_encode([
            'success' => false,
            'error' => 'Google AI API key not configured',
            'status' => 'configuration_error'
        ]);
        exit;
    }

    // Test basic API connectivity with a model list request
    $baseUrl = $sunnyConfig['gemini']['base_url'] ?? 'https://generativelanguage.googleapis.com/v1beta';
    $testUrl = $baseUrl . '/models?key=' . urlencode($apiKey);

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $testUrl,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json'
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2
    ]);

    $modelsResponse = curl_exec($ch);
    $modelsHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $modelsError = curl_error($ch);
    curl_close($ch);

    // Check if API connection is working
    if ($modelsHttpCode === 200 && empty($modelsError)) {
        $defaultModel = $sunnyConfig['gemini']['models']['default'] ?? 'gemini-2.5-flash';
        echo json_encode([
            'success' => true,
            'message' => 'Gemini API connection successful!',
            'status' => 'connected',
            'model' => $defaultModel,
            'api_key_length' => strlen($apiKey)
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'error' => 'Failed to connect to Gemini API',
            'status' => 'connection_failed',
            'http_code' => $modelsHttpCode,
            'curl_error' => $modelsError
        ]);
    }

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => 'Connection test failed',
        'status' => 'exception',
        'details' => $e->getMessage()
    ]);
}
?>
