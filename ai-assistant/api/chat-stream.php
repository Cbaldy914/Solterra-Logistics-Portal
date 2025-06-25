<?php
// Set headers for Server-Sent Events
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');
header('X-Accel-Buffering: no'); // Disable nginx buffering

// Prevent timeout
set_time_limit(0);
ignore_user_abort(false);

// Use existing session from portal (don't start new session)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
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
require_once __DIR__ . '/openai-client.php';

// Load Sunny configuration
$sunnyConfig = require_once __DIR__ . '/../config/sunny-config.php';

// Get user context
$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['role'] ?? 'user';
$account_id = $_SESSION['account_id'] ?? null; // Get from session
$user_name = $_SESSION['username'] ?? 'User';

// For non-global-admin users, ensure account_id is set
if ($user_role !== 'global_admin' && $account_id === null) {
    // Try to get account_id from customer_account_users table
    try {
        $conn = getDBConnection();
        if ($conn) {
            $stmt = $conn->prepare("SELECT account_id FROM customer_account_users WHERE user_id = ?");
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($row = $result->fetch_assoc()) {
                $account_id = $row['account_id'];
            }
            $stmt->close();
        }
    } catch (Exception $e) {
        error_log("Failed to get account_id for user {$user_id}: " . $e->getMessage());
    }
}

try {
    // Check for OpenAI API key
    $apiKey = $sunnyConfig['openai']['api_key'];
    if (empty($apiKey)) {
        echo "data: " . json_encode(['type' => 'error', 'message' => 'OpenAI API key not configured']) . "\n\n";
        exit;
    }

    // Initialize components
    $queryExecutor = new SunnyQueryExecutor($user_role, $account_id);
    $sunnyTools = new SunnyTools($user_role, $account_id);
    $openAIClient = new OpenAIClient($apiKey, $sunnyConfig['openai']['base_url'], $sunnyConfig['openai']['model']);
    
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
    
    // Stream response from OpenAI
    streamOpenAIResponse($openAIClient, $userContext, $toolResults, $message);
    
} catch (Exception $e) {
    error_log("Chat stream error: " . $e->getMessage() . " | File: " . $e->getFile() . " | Line: " . $e->getLine());
    echo "data: " . json_encode(['type' => 'error', 'message' => 'An error occurred while processing your request. Please try again.']) . "\n\n";
}

function detectToolsFromMessage($message) {
    $message = strtolower($message);
    $tools = [];
    
    // Check for casual conversation first - don't use tools for these
    $casualPatterns = [
        '/^(hi|hello|hey|good morning|good afternoon|good evening)[\s\!\?]*$/i',
        '/^(how are you|how\'s it going|what\'s up|sup)[\s\!\?]*$/i',
        '/^(thanks|thank you|thx|bye|goodbye|see you|take care)[\s\!\?]*$/i',
        '/^(yes|no|ok|okay|sure|fine|great|awesome|cool)[\s\!\?]*$/i',
        '/^(test|testing|hello|hi there)[\s\!\?]*$/i', // Simple test messages
        '/^(what is \d+\+\d+|what is \d+ plus \d+|calculate|math)/', // Math questions
        '/^(who are you|what can you do|help|what do you do)[\s\!\?]*$/i'
    ];
    
    foreach ($casualPatterns as $pattern) {
        if (preg_match($pattern, trim($message))) {
            return []; // No tools needed for casual conversation
        }
    }
    
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
    
    // Only use default tools for actual logistics questions
    if (empty($tools) && preg_match('/\b(show|get|tell|what|how|when|where|list)\b/', $message)) {
        $tools = ['getProjectSummary'];
    }
    
    return array_unique($tools);
}

function streamOpenAIResponse($openAIClient, $userContext, $toolResults, $userMessage) {
    global $sunnyConfig, $streamBuffer;
    
    // Clear any leftover buffer from previous requests
    $streamBuffer = '';
    
    // Set up streaming callback
    $openAIClient->setStreamCallback('handleOpenAIStream');
    
    // Start streaming
    $result = $openAIClient->generateStreamingResponse($userMessage, $userContext, $toolResults);
    
    if (!$result['success']) {
        echo "data: " . json_encode(['type' => 'error', 'message' => 'Failed to connect to AI service']) . "\n\n";
    }
    
    // Send completion signal
    echo "data: " . json_encode(['type' => 'complete']) . "\n\n";
    flush();
}

// Global buffer to handle partial JSON responses
$streamBuffer = '';

function handleOpenAIStream($data) {
    global $streamBuffer;
    
    // Add new data to buffer
    $streamBuffer .= $data;
    
    // Process complete lines from buffer
    while (($pos = strpos($streamBuffer, "\n")) !== false) {
        $line = substr($streamBuffer, 0, $pos);
        $streamBuffer = substr($streamBuffer, $pos + 1);
        
        // Skip empty lines and non-data lines
        if (empty(trim($line)) || strpos($line, 'data: ') !== 0) {
            continue;
        }
        
        // Extract JSON from data: prefix
        $jsonData = trim(substr($line, 6));
        
        // Check for stream end
        if ($jsonData === '[DONE]') {
            $streamBuffer = '';
            break;
        }
        
        try {
            $json = json_decode($jsonData, true);
            if ($json && isset($json['choices'][0]['delta']['content'])) {
                $content = $json['choices'][0]['delta']['content'];
                if ($content !== '') {
                    // Send token to client
                    echo "data: " . json_encode([
                        'type' => 'token',
                        'content' => $content
                    ]) . "\n\n";
                    flush();
                }
            }
        } catch (Exception $e) {
            // Skip malformed JSON but log for debugging
            error_log("JSON parse error in stream: " . $e->getMessage() . " | Line: " . $line);
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