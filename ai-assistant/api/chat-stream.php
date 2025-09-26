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
    // Use the same session as the portal
    session_name('logistics_session');
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
    
    // Load existing chat history from session (max 10 messages)
    $chatHistory = $_SESSION['sunny_chat_history'] ?? [];
    $maxHistory = 10;

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
    $lastToolResults = $_SESSION['sunny_last_tool_results'] ?? [];
    $memoryResults = [];
    $needsTools = needsLogisticsTools($message);
    
    // Always try to load memory context and detect memory operations
    try {
        require_once __DIR__ . '/query-executor.php';
        require_once __DIR__ . '/sunny-tools.php';
        
        $sunnyTools = new SunnyTools($user_role, $account_id);

        // If client indicates an attachment should be included, analyze it and add to tool results
        if (!empty($_GET['attach'])) {
            // If upload_id provided and exists in session, ensure it's set as last
            if (!empty($_GET['upload_id']) && !empty($_SESSION['sunny_uploads'][$_GET['upload_id']])) {
                $_SESSION['sunny_last_upload'] = $_GET['upload_id'];
            }
            try {
                $doc = $sunnyTools->analyzeDocument('summary');
                if ($doc) {
                    $toolResults['analyzeDocument'] = $doc;
                }
            } catch (Exception $e) {
                // Ignore, continue
            }
        }
        
        // Check if user wants to store a memory
        if (shouldStoreMemory($message)) {
            $memoryData = extractMemoryFromMessage($message);
            $result = $sunnyTools->storeMemory(
                $memoryData['title'],
                $memoryData['content'],
                $memoryData['type'],
                $memoryData['category'],
                $memoryData['entity_id'],
                $memoryData['importance']
            );
            $memoryResults['store'] = $result;
        }
        
        // Always retrieve relevant memories for context
        $memories = $sunnyTools->getRelevantMemories(null, null, 5);
        if ($memories['success'] && !empty($memories['data'])) {
            $memoryResults['context'] = $memories['data'];
        }
        
    } catch (Exception $e) {
        error_log("Memory loading error: " . $e->getMessage());
    }
    
    if ($needsTools) {
        try {
            // Tools already loaded above for memory
            if (!isset($sunnyTools)) {
                $sunnyTools = new SunnyTools($user_role, $account_id);
            }
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

    // If no tools executed this turn, but we have prior tool results, reuse them to let the model present them
    if (empty($toolResults) && !empty($lastToolResults)) {
        $toolResults = $lastToolResults;
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
            } else if ($data['success'] && empty($data['data'])) {
                $systemMessage .= "{$tool}: (no rows returned)\n";
            } else {
                $systemMessage .= "{$tool}: (error: " . ($data['error'] ?? 'unknown') . ")\n";
            }
        }
        // Persist for follow-up turns like "please present them"
        $_SESSION['sunny_last_tool_results'] = $toolResults;
    } else {
        $systemMessage .= "\n\n(If the user requests specific logistics data that isn't provided above, say clearly that no results were found for their request and ask for a different filter or timeframe. Do not invent placeholders.)";
    }

    // Add memory context to system message
    if (!empty($memoryResults['context'])) {
        $systemMessage .= "\n\n**Previous Context & Preferences**\n";
        foreach ($memoryResults['context'] as $memory) {
            $systemMessage .= "- {$memory['title']}: {$memory['content']}\n";
        }
    }

    // Add concise style and no filler guidance
    $systemMessage .= "\n\nStyle: Be concise, present concrete results immediately. Do not write filler like 'just a moment' or 'let me check'. If data exists above, present it directly. If no data, say 'No results found' with 1 actionable next step.";

    // If we have analyzed document text, surface it explicitly as content (not only JSON)
    $docContextMessage = null;
    if (!empty($toolResults['analyzeDocument']) &&
        !empty($toolResults['analyzeDocument']['success']) &&
        !empty($toolResults['analyzeDocument']['data']) &&
        isset($toolResults['analyzeDocument']['data']['text_preview']) &&
        trim((string)$toolResults['analyzeDocument']['data']['text_preview']) !== '') {
        $docInfo = $toolResults['analyzeDocument']['data'];
        $docName = $docInfo['filename'] ?? 'uploaded_document';
        $docText = $docInfo['text_preview'];
        // Provide the raw text in its own message for the model to use directly
        $docContextMessage = [
            'role' => 'system',
            'content' => "Document Content (" . $docName . ")\n---\n" . $docText . "\n---\nUse the document content above to answer or summarize as requested."
        ];
    }

    // Construct message array: system prompt + explicit doc content (if any) + trimmed chat history + current user msg
    $messagesForOpenAI = [];
    $messagesForOpenAI[] = ['role' => 'system', 'content' => $systemMessage];
    if ($docContextMessage) {
        $messagesForOpenAI[] = $docContextMessage;
    }

    // Append recent history
    foreach ($chatHistory as $entry) {
        $messagesForOpenAI[] = $entry; // each entry already ['role'=>..,'content'=>..]
    }

    // Current user message
    $messagesForOpenAI[] = ['role' => 'user', 'content' => $message];

    // OpenAI API payload
    $chatData = [
        'model' => $sunnyConfig['openai']['model'],
        'messages' => $messagesForOpenAI,
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

    // Capture assistant response globally during stream
    global $assistantResponseBuffer;
    $assistantResponseBuffer = '';

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

    // ---- Persist chat history ----
    if (!empty($assistantResponseBuffer)) {
        // Append new user & assistant messages
        $chatHistory[] = ['role' => 'user', 'content' => $message];
        $chatHistory[] = ['role' => 'assistant', 'content' => $assistantResponseBuffer];
        // Trim to last N entries
        if (count($chatHistory) > $maxHistory) {
            $chatHistory = array_slice($chatHistory, -1 * $maxHistory);
        }
        $_SESSION['sunny_chat_history'] = $chatHistory;
    }

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
    
    // Use tools for logistics questions (extended with docs)
    $logisticsPatterns = [
        '/\b(project|projects|delivery|deliveries|shipment|tracking|carrier|inventory|warehouse|storage|stock|module|modules|document|documents|doc|pods?|invoices?|bol|proof of delivery|spec\s*sheet|safe\s*harbor)\b/i',
        '/\b(recent|latest|new|status|summary|overview|performance|download|find|open|show)\b/i',
        '/\b(show|get|tell|what|how|when|where|list)\b/i'
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
        // Upcoming timeframe detection (e.g., "next 2 weeks", "coming week")
        if (preg_match('/\b(next|coming|this)\b/', $message)) {
            $tools[] = 'getUpcomingDeliveries';
        } else {
            $tools[] = 'getDeliveryStatus';
        }
    }

    // Inventory/warehouse related
    if (preg_match('/\b(inventory|warehouse|storage|stock)\b/', $message)) {
        $tools[] = 'getWarehouseInventory';
    }

    // Document-related
    if (preg_match('/\b(document|documents|doc|pdf|pods?|invoices?|bol|proof of delivery|spec\s*sheet|safe\s*harbor)\b/i', $message)) {
        // If message mentions a project, prefer project documents, else global
        if (preg_match('/\bproject\b/i', $message)) {
            $tools[] = 'getProjectDocuments';
        } else {
            $tools[] = 'getGlobalDocuments';
        }
        // Also fetch POD status if asking about PODs
        if (preg_match('/\b(pods?|proof of delivery)\b/i', $message)) {
            $tools[] = 'getPODStatus';
        }

        // If asking to summarize/analyze documents
        if (preg_match('/\b(analy(s|z)e|summary|summarize|review)\b/i', $message)) {
            $tools[] = 'analyzeDocument';
        }
    }

    // KPIs and performance metrics
    if (preg_match('/\b(kpi|dashboard)\b/i', $message)) {
        $tools[] = 'getKPIDashboard';
    }
    if (preg_match('/\b(performance|on[-\s]?time|late|trend|trends)\b/i', $message)) {
        $tools[] = 'getDeliveryPerformance';
    }

    // If the user mentions uploaded file explicitly
    if (preg_match('/\b(uploaded\s+file|attached\s+file|analy(s|z)e\s+(the\s+)?(upload|attachment|file|document))\b/i', $message)) {
        $tools[] = 'analyzeDocument';
    }

    // Default for general logistics questions
    if (empty($tools) && preg_match('/\b(show|get|tell|what|recent|latest)\b/', $message)) {
        $tools = ['getProjectSummary'];
    }
    
    return array_unique($tools);
}

// Memory detection functions
function shouldStoreMemory($message) {
    $message = strtolower($message);
    $patterns = [
        '/\b(remember|save|store|my name is|call me|i prefer|i like|i need|i want)\b/',
        '/\b(please remember|don\'t forget|keep in mind|note that)\b/'
    ];
    
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $message)) {
            return true;
        }
    }
    
    return false;
}

function extractMemoryFromMessage($message) {
    $message = trim($message);
    $messageLower = strtolower($message);
    
    // Default values
    $title = 'User preference';
    $content = $message;
    $type = 'preference';
    $category = null;
    $entityId = null;
    $importance = 1;
    
    // Extract name
    if (preg_match('/my name is\s+([a-zA-Z\s]+)/i', $message, $matches)) {
        $name = trim($matches[1]);
        $title = 'User name';
        $content = "User's name is {$name}";
        $type = 'preference';
        $importance = 3;
    }
    // Extract preferences
    else if (preg_match('/i prefer\s+(.+)/i', $message, $matches)) {
        $preference = trim($matches[1]);
        $title = 'User preference';
        $content = "User prefers {$preference}";
        $type = 'preference';
        $importance = 2;
    }
    // General remember requests
    else if (preg_match('/remember\s+(.+)/i', $message, $matches)) {
        $toRemember = trim($matches[1]);
        $title = 'User note';
        $content = $toRemember;
        $type = 'note';
        $importance = 2;
    }
    
    return [
        'title' => $title,
        'content' => $content,
        'type' => $type,
        'category' => $category,
        'entity_id' => $entityId,
        'importance' => $importance
    ];
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
                    // Append token to full assistant response for history
                    global $assistantResponseBuffer;
                    $assistantResponseBuffer .= $content;

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
