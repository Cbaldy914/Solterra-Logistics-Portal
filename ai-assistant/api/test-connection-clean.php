<?php
/**
 * Clean Test OpenAI API Connection for Sunny AI Assistant
 * Returns single JSON response only
 */

// Set headers for JSON response
header('Content-Type: application/json');
header('Cache-Control: no-cache');

// Use existing session from portal
if (session_status() === PHP_SESSION_NONE) {
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
    require_once __DIR__ . '/openai-client.php';
    
    // Check if API key is configured
    $apiKey = $sunnyConfig['openai']['api_key'];
    if (empty($apiKey)) {
        echo json_encode([
            'success' => false,
            'error' => 'OpenAI API key not configured',
            'status' => 'configuration_error'
        ]);
        exit;
    }
    
    // Test basic API connectivity with a simple model list request
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $sunnyConfig['openai']['base_url'] . '/models',
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $apiKey
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
        echo json_encode([
            'success' => true,
            'message' => 'OpenAI API connection successful!',
            'status' => 'connected',
            'model' => $sunnyConfig['openai']['model'],
            'api_key_length' => strlen($apiKey)
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'error' => 'Failed to connect to OpenAI API',
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