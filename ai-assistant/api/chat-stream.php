<?php
// Set headers for Server-Sent Events
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');
header('X-Accel-Buffering: no'); // Disable nginx buffering

// Prevent timeout
set_time_limit(0);
ignore_user_abort(false);

// Start session and check authentication
session_name("logistics_session");
session_start();
if (!isset($_SESSION['user_id'])) {
    echo "data: " . json_encode(['type' => 'error', 'message' => 'Authentication required']) . "\n\n";
    exit;
}

// Get user message
$message = $_GET['message'] ?? '';
if (empty($message)) {
    echo "data: " . json_encode(['type' => 'error', 'message' => 'Message is required']) . "\n\n";
    exit;
}

// Include required files
require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/query-executor.php';
require_once __DIR__ . '/sunny-tools.php';

// Load Sunny configuration
$sunnyConfig = require_once __DIR__ . '/../config/sunny-config.php';

// Get user context
$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['role'] ?? 'user';
$account_id = null; // Will be fetched from database if needed
$user_name = $_SESSION['username'] ?? 'User';

try {
    // Initialize components
    $queryExecutor = new QueryExecutor();
    $sunnyTools = new SunnyTools();
    
    // Build user context
    $userContext = [
        'user_id' => $user_id,
        'user_name' => $user_name,
        'role' => $user_role,
        'account_id' => $account_id,
        'timestamp' => date('Y-m-d H:i:s')
    ];
    
    // Detect which tools to use based on the message
    $toolsToUse = detectToolsFromMessage($message);
    
    // Execute tools and gather context
    $toolResults = [];
    foreach ($toolsToUse as $tool) {
        try {
            $result = $sunnyTools->executeTool($tool, $message, $userContext);
            if ($result) {
                $toolResults[$tool] = $result;
            }
        } catch (Exception $e) {
            error_log("Tool execution error for $tool: " . $e->getMessage());
        }
    }
    
    // Build context for Ollama
    $systemPrompt = file_get_contents(__DIR__ . '/../sunny_system_prompt.md');
    $contextData = buildContextForOllama($userContext, $toolResults, $message);
    
    // Stream response from Ollama
    streamOllamaResponse($systemPrompt, $contextData, $message);
    
} catch (Exception $e) {
    error_log("Chat stream error: " . $e->getMessage());
    echo "data: " . json_encode(['type' => 'error', 'message' => 'An error occurred while processing your request']) . "\n\n";
}

function detectToolsFromMessage($message) {
    $message = strtolower($message);
    $tools = [];
    
    // Delivery related
    if (preg_match('/\b(delivery|deliveries|shipment|tracking|carrier)\b/', $message)) {
        $tools[] = 'getDeliveryStatus';
        $tools[] = 'getDeliveryPerformance';
    }
    
    // Project related
    if (preg_match('/\b(project|projects|status)\b/', $message)) {
        $tools[] = 'getProjectSummary';
        $tools[] = 'getProjectCostAnalysis';
    }
    
    // Inventory/warehouse related
    if (preg_match('/\b(inventory|warehouse|storage|stock)\b/', $message)) {
        $tools[] = 'getWarehouseInventory';
    }
    
    // Module related
    if (preg_match('/\b(module|modules|movement|location)\b/', $message)) {
        $tools[] = 'getModuleMovements';
    }
    
    // Recent activities
    if (preg_match('/\b(recent|latest|new)\b/', $message)) {
        $tools[] = 'getDeliveryStatus';
        $tools[] = 'getModuleMovements';
    }
    
    // KPI/dashboard related
    if (preg_match('/\b(kpi|dashboard|summary|overview|performance)\b/', $message)) {
        $tools[] = 'getKPIDashboard';
    }
    
    // Search related
    if (preg_match('/\b(search|find|lookup)\b/', $message)) {
        $tools[] = 'searchLogistics';
    }
    
    // If no specific tools detected, use general ones
    if (empty($tools)) {
        $tools = ['getProjectSummary', 'getDeliveryStatus'];
    }
    
    return array_unique($tools);
}

function buildContextForOllama($userContext, $toolResults, $message) {
    $context = "User Information:\n";
    $context .= "- Name: " . $userContext['user_name'] . "\n";
    $context .= "- Role: " . $userContext['role'] . "\n";
    $context .= "- Account ID: " . $userContext['account_id'] . "\n";
    $context .= "- Time: " . $userContext['timestamp'] . "\n\n";
    
    if (!empty($toolResults)) {
        $context .= "Available Data:\n";
        foreach ($toolResults as $tool => $data) {
            $context .= "\n" . ucfirst(str_replace(['get', 'search'], '', $tool)) . ":\n";
            if (is_array($data)) {
                $context .= json_encode($data, JSON_PRETTY_PRINT) . "\n";
            } else {
                $context .= $data . "\n";
            }
        }
    }
    
    $context .= "\nUser Question: " . $message . "\n";
    
    return $context;
}

function streamOllamaResponse($systemPrompt, $context, $userMessage) {
    global $sunnyConfig;
    
    // Ollama endpoint
    $ollamaUrl = $sunnyConfig['ollama']['base_url'] . '/api/generate';
    
    // Build the prompt
    $fullPrompt = $systemPrompt . "\n\n" . $context . "\n\nPlease provide a helpful response:";
    
    $postData = json_encode([
        'model' => $sunnyConfig['ollama']['model'],
        'prompt' => $fullPrompt,
        'stream' => true,
        'options' => $sunnyConfig['ollama']['options']
    ]);
    
    // Initialize cURL for streaming
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $ollamaUrl);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Content-Length: ' . strlen($postData)
    ]);
    curl_setopt($ch, CURLOPT_WRITEFUNCTION, 'handleOllamaStream');
    curl_setopt($ch, CURLOPT_TIMEOUT, $sunnyConfig['ollama']['timeout']);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    
    // Execute the request
    $result = curl_exec($ch);
    
    if (curl_error($ch)) {
        echo "data: " . json_encode(['type' => 'error', 'message' => 'Failed to connect to AI service']) . "\n\n";
    }
    
    curl_close($ch);
    
    // Send completion signal
    echo "data: " . json_encode(['type' => 'complete']) . "\n\n";
    flush();
}

function handleOllamaStream($ch, $data) {
    $lines = explode("\n", trim($data));
    
    foreach ($lines as $line) {
        if (empty($line)) continue;
        
        try {
            $json = json_decode($line, true);
            if ($json && isset($json['response'])) {
                // Send token to client
                echo "data: " . json_encode([
                    'type' => 'token',
                    'content' => $json['response']
                ]) . "\n\n";
                flush();
                
                // Check if generation is done
                if (isset($json['done']) && $json['done']) {
                    break;
                }
            }
        } catch (Exception $e) {
            // Skip malformed JSON
            continue;
        }
    }
    
    return strlen($data);
}

// Ensure output is sent immediately
if (ob_get_level()) {
    ob_end_flush();
}
flush();
?> 