<?php
header('Content-Type: application/json');

// Start session and check authentication
session_name("logistics_session");
session_start();

$response = [];

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    $response = [
        'success' => false,
        'message' => 'Authentication required'
    ];
} else {
    // Test basic functionality
    try {
        // Check if required files exist
        $requiredFiles = [
            __DIR__ . '/../../../config.php',
            __DIR__ . '/query-executor.php',
            __DIR__ . '/sunny-tools.php',
            __DIR__ . '/../sunny_system_prompt.md'
        ];
        
        $missingFiles = [];
        foreach ($requiredFiles as $file) {
            if (!file_exists($file)) {
                $missingFiles[] = basename($file);
            }
        }
        
        if (!empty($missingFiles)) {
            throw new Exception('Missing required files: ' . implode(', ', $missingFiles));
        }
        
        // Load configuration
        $sunnyConfig = require_once __DIR__ . '/../config/sunny-config.php';
        
        // Test Ollama connectivity
        $ollamaUrl = $sunnyConfig['ollama']['base_url'] . '/api/version';
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $ollamaUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        
        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        
        if ($curlError || $httpCode !== 200) {
            throw new Exception('Ollama service unavailable. URL: ' . $ollamaUrl . ', HTTP Code: ' . $httpCode . ', cURL Error: ' . $curlError);
        }
        
        curl_close($ch);
        
        $response = [
            'success' => true,
            'message' => 'Connection successful',
            'user' => [
                'name' => $_SESSION['username'] ?? 'User',
                'role' => $_SESSION['role'] ?? 'user'
            ]
        ];
        
    } catch (Exception $e) {
        http_response_code(500);
        $response = [
            'success' => false,
            'message' => 'System error: ' . $e->getMessage()
        ];
    }
}

echo json_encode($response);
?> 