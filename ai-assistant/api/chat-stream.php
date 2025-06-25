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

// Global variable for streaming buffer
$streamBuffer = '';

try {
    // Try to load config files with multiple paths
    $configPaths = [
        __DIR__ . '/../../../config.php',
        __DIR__ . '/../../config.php',
        dirname(__DIR__, 3) . '/config.php'
    ];
    
    $configLoaded = false;
    foreach ($configPaths as $path) {
        if (file_exists($path)) {
            require_once $path;
            $configLoaded = true;
            break;
        }
    }
    
    if (!$configLoaded) {
        echo "data: " . json_encode(['type' => 'error', 'message' => 'Could not load config.php']) . "\n\n";
        exit;
    }

    // Load Sunny configuration
    $sunnyConfigPath = __DIR__ . '/../config/sunny-config.php';
    if (!file_exists($sunnyConfigPath)) {
        echo "data: " . json_encode(['type' => 'error', 'message' => 'Sunny config not found']) . "\n\n";
        exit;
    }
    
    $sunnyConfig = require_once $sunnyConfigPath;

    // Check for OpenAI API key
    $apiKey = $sunnyConfig['openai']['api_key'] ?? '';
    if (empty($apiKey)) {
        echo "data: " . json_encode(['type' => 'error', 'message' => 'OpenAI API key not configured']) . "\n\n";
        exit;
    }

    // Get user context
    $user_id = $_SESSION['user_id'];
    $user_role = $_SESSION['role'] ?? 'user';
    $account_id = $_SESSION['account_id'] ?? null;
    $user_name = $_SESSION['username'] ?? 'User';
    
    // Get account_id if needed (for tools)
    if ($user_role !== 'global_admin' && $account_id === null) {
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

    // Load tools if needed (carefully)
    $toolResults = [];
    $needsTools = needsLogisticsTools($message);
    
    if ($needsTools) {
        try {
            // Only load tools if we actually need them
            require_once __DIR__ . '/query-executor.php';
            require_once __DIR__ . '/sunny-tools.php';
            
            $sunnyTools = new SunnyTools($user_role, $account_id);
            $toolsToUse = detectToolsFromMessage($message);
            
            // Execute tools
            foreach ($toolsToUse as $tool) {
                try {
                    $result = $sunnyTools->executeTool($tool, $message, [
                        'user_id' => $user_id,
                        'user_name' => $user_name,
                        'role' => $user_role,
                        'account_id' => $account_id
                    ]);
                    if ($result) {
                        $toolResults[$tool] = $result;
                    }
                } catch (Exception $e) {
                    error_log("Tool execution error for $tool: " . $e->getMessage());
                }
            }
        } catch (Exception $e) {
            error_log("Tools loading error: " . $e->getMessage());
            // Continue without tools if they fail
        }
    }

    // Build system message starting with the canonical Sunny system prompt (markdown file)
    $promptPath = dirname(__DIR__) . '/sunny_system_prompt.md';
    if (file_exists($promptPath)) {
        $systemMessage = file_get_contents($promptPath);
    } else {
        // Fallback in unlikely scenario the file is missing
        $systemMessage = "You are Sunny, a helpful logistics assistant for Solterra Solutions.";
    }

    // Append dynamic tool context (if any) so the model can ground its answer
    if (!empty($toolResults)) {
        $systemMessage .= "\n\n**Available Data From Tools**\n";
        foreach ($toolResults as $tool => $data) {
            if ($data['success'] && !empty($data['data'])) {
                $systemMessage .= "{$tool}: " . json_encode($data['data']) . "\n";
            }
        }
    } else {
        $systemMessage .= "\n\n(If the user requests specific logistics data that isn't provided above, gently let them know data isn't available and offer general guidance.)";
    }

    // OpenAI API call
    $chatData = [
        'model' => $sunnyConfig['openai']['model'],
        'messages' => [
            [
                'role' => 'system',
                'content' => $systemMessage
            ],
            [
                'role' => 'user',
                'content' => $message
            ]
        ],
        'max_tokens' => 300,
        'temperature' => 0.7,
        'stream' => true
    ];

    // Make streaming request to OpenAI
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $sunnyConfig['openai']['base_url'] . '/chat/completions',
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($chatData),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey
        ],
        CURLOPT_WRITEFUNCTION => 'handleStreamData',
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2
    ]);

    $result = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($result === false || !empty($error)) {
        echo "data: " . json_encode(['type' => 'error', 'message' => 'OpenAI API request failed: ' . $error]) . "\n\n";
        exit;
    }
    
    if ($httpCode !== 200) {
        echo "data: " . json_encode(['type' => 'error', 'message' => 'OpenAI API returned HTTP ' . $httpCode]) . "\n\n";
        exit;
    }

    // Send completion signal
    echo "data: " . json_encode(['type' => 'complete']) . "\n\n";
    flush();

} catch (Exception $e) {
    error_log("Chat stream error: " . $e->getMessage() . " | File: " . $e->getFile() . " | Line: " . $e->getLine());
    echo "data: " . json_encode(['type' => 'error', 'message' => 'Error: ' . $e->getMessage()]) . "\n\n";
}

function needsLogisticsTools($message) {
    $message = strtolower($message);
    
    // Don't use tools for casual conversation
    $casualPatterns = [
        '/^(hi|hello|hey|good morning|good afternoon|good evening)[\s\!\?]*$/i',
        '/^(how are you|how\'s it going|what\'s up|sup)[\s\!\?]*$/i',
        '/^(thanks|thank you|thx|bye|goodbye|see you|take care)[\s\!\?]*$/i',
        '/^(yes|no|ok|okay|sure|fine|great|awesome|cool)[\s\!\?]*$/i',
        '/^(what is \d+\+\d+|what is \d+ plus \d+|calculate|math)/',
        '/^(who are you|what can you do|help|what do you do)[\s\!\?]*$/i'
    ];
    
    foreach ($casualPatterns as $pattern) {
        if (preg_match($pattern, trim($message))) {
            return false;
        }
    }
    
    // Use tools for logistics questions
    $logisticsPatterns = [
        '/\b(project|projects|delivery|deliveries|shipment|tracking|carrier|inventory|warehouse|storage|stock|module|modules)\b/',
        '/\b(recent|latest|new|status|summary|overview|performance)\b/',
        '/\b(show|get|tell|what|how|when|where|list)\b/'
    ];
    
    foreach ($logisticsPatterns as $pattern) {
        if (preg_match($pattern, $message)) {
            return true;
        }
    }
    
    return false;
}

function detectToolsFromMessage($message) {
    $message = strtolower($message);
    $tools = [];
    
    // Project related
    if (preg_match('/\b(project|projects|status)\b/', $message)) {
        $tools[] = 'getProjectSummary';
    }
    
    // Delivery related
    if (preg_match('/\b(delivery|deliveries|shipment|tracking|carrier)\b/', $message)) {
        $tools[] = 'getDeliveryStatus';
    }
    
    // Inventory/warehouse related
    if (preg_match('/\b(inventory|warehouse|storage|stock)\b/', $message)) {
        $tools[] = 'getWarehouseInventory';
    }
    
    // Default for general logistics questions
    if (empty($tools) && preg_match('/\b(show|get|tell|what|recent|latest)\b/', $message)) {
        $tools = ['getProjectSummary'];
    }
    
    return array_unique($tools);
}

function handleStreamData($ch, $data) {
    global $streamBuffer;
    
    // Add new data to buffer
    $streamBuffer .= $data;
    
    // Process complete lines from buffer
    while (($pos = strpos($streamBuffer, "\n")) !== false) {
        $line = substr($streamBuffer, 0, $pos);
        $streamBuffer = substr($streamBuffer, $pos + 1);
        
        $line = trim($line);
        
        // Skip empty lines and non-data lines
        if (empty($line) || strpos($line, 'data: ') !== 0) {
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
            // Skip malformed JSON but continue processing
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