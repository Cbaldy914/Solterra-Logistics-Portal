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

// Global variable to capture usageMetadata from Gemini's final chunk
$geminiUsageMetadata = null;
$sunnyRequestStartedAt = microtime(true);
$sunnyTraceId = function_exists('random_bytes')
    ? bin2hex(random_bytes(8))
    : uniqid('sunny_', true);

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

    // Check for Gemini API key
    $apiKey = $sunnyConfig['gemini']['api_key'] ?? '';
    if (empty($apiKey)) {
        echo "data: " . json_encode(['type' => 'error', 'message' => 'Google AI API key not configured']) . "\n\n";
        exit;
    }

    // Get user context
    $user_id = $_SESSION['user_id'];
    $user_role = $_SESSION['role'] ?? 'user';
    $account_id = $_SESSION['account_id'] ?? null;
    $user_name = $_SESSION['username'] ?? 'User';
    $allowedRoles = $sunnyConfig['security']['allowed_roles'] ?? [];
    if (!isSunnyRoleAllowed($user_role, $allowedRoles)) {
        echo "data: " . json_encode(['type' => 'error', 'message' => 'Your role is not authorized for Sunny access.']) . "\n\n";
        flush();
        exit;
    }
    // Ensure we have an active conversation id for this user
    $conversationId = $_SESSION['sunny_active_conversation_id'] ?? null;

    sunnyTraceLog('request_start', [
        'trace_id' => $sunnyTraceId,
        'user_id' => (int)$user_id,
        'role' => (string)$user_role,
        'has_session_account_id' => $account_id !== null,
        'message_length' => strlen($message),
    ]);

    // Load existing chat history from session (max 10 messages)
    $chatHistory = $_SESSION['sunny_chat_history'] ?? [];
    $maxHistory = 10;

    // ── Mode detection: default vs bright ──
    $mode = $_GET['mode'] ?? 'default';
    if ($mode !== 'bright') $mode = 'default';
    $selectedModel = $sunnyConfig['gemini']['models'][$mode] ?? $sunnyConfig['gemini']['models']['default'];
    $budgetMultiplier = ($mode === 'bright') ? ($sunnyConfig['usage_caps']['bright_multiplier'] ?? 3) : 1;

    // ── Usage cap check (fail-open) ──
    $usageRemainingPercent = 100;
    try {
        $connUsage = getDBConnection();
        if ($connUsage) {
            $today = date('Y-m-d');
            $dailyLimit = $sunnyConfig['usage_caps']['daily_token_limit'] ?? 100000;
            $dailyRequestLimit = $sunnyConfig['usage_caps']['daily_request_limit'] ?? 100;

            // Sum weighted tokens for today (bright tokens count 3x)
            $stmtUsage = $connUsage->prepare(
                "SELECT COALESCE(SUM(
                    (prompt_tokens + completion_tokens) *
                    CASE WHEN model = ? THEN ? ELSE 1 END
                ), 0) AS weighted_tokens,
                COALESCE(SUM(request_count), 0) AS total_requests
                FROM sunny_usage
                WHERE user_id = ? AND usage_date = ?"
            );
            $brightModel = $sunnyConfig['gemini']['models']['bright'];
            $brightMult = $sunnyConfig['usage_caps']['bright_multiplier'] ?? 3;
            $stmtUsage->bind_param('siis', $brightModel, $brightMult, $user_id, $today);
            $stmtUsage->execute();
            $usageResult = $stmtUsage->get_result()->fetch_assoc();
            $stmtUsage->close();
            $connUsage->close();

            $weightedTokens = (int)($usageResult['weighted_tokens'] ?? 0);
            $totalRequests = (int)($usageResult['total_requests'] ?? 0);
            $usageRemainingPercent = max(0, round((1 - ($weightedTokens / $dailyLimit)) * 100));

            // Check if over limit
            if ($weightedTokens >= $dailyLimit || $totalRequests >= $dailyRequestLimit) {
                echo "data: " . json_encode([
                    'type' => 'error',
                    'message' => "You've reached your daily usage limit. Your budget resets at midnight. Try again tomorrow, or switch to standard mode to use less budget per message."
                ]) . "\n\n";
                echo "data: " . json_encode([
                    'type' => 'usage_info',
                    'remaining_percent' => 0,
                    'mode' => $mode
                ]) . "\n\n";
                flush();
                exit;
            }

            // Send usage info event before streaming
            echo "data: " . json_encode([
                'type' => 'usage_info',
                'remaining_percent' => $usageRemainingPercent,
                'mode' => $mode
            ]) . "\n\n";
            flush();
        }
    } catch (Exception $e) {
        error_log("Usage cap check failed (proceeding anyway): " . $e->getMessage());
    }

    // Resolve account context deterministically (strict tenant isolation).
    $accountResolution = resolveSunnyAccountContext($user_id, $user_role, $account_id);
    if (!$accountResolution['success']) {
        sunnyTraceLog('account_context_error', [
            'trace_id' => $sunnyTraceId,
            'user_id' => (int)$user_id,
            'role' => (string)$user_role,
            'error_code' => (string)($accountResolution['error_code'] ?? 'unknown'),
            'error_message' => (string)($accountResolution['error_message'] ?? 'Unknown account context error'),
        ]);
        echo "data: " . json_encode([
            'type' => 'error',
            'message' => $accountResolution['error_message'] ?? 'Unable to determine your account scope for this request.'
        ]) . "\n\n";
        flush();
        exit;
    }
    $account_id = $accountResolution['account_id'];
    sunnyTraceLog('account_context_resolved', [
        'trace_id' => $sunnyTraceId,
        'user_id' => (int)$user_id,
        'role' => (string)$user_role,
        'account_id' => $account_id !== null ? (int)$account_id : null,
        'source' => (string)($accountResolution['source'] ?? 'unknown'),
    ]);

    // Create a conversation if none exists
    try {
        if (!$conversationId) {
            $connTmp = getDBConnection();
            if ($connTmp) {
                $title = 'Chat on ' . date('Y-m-d H:i');
                $stmt = $connTmp->prepare("INSERT INTO sunny_conversations (user_id, title) VALUES (?, ?)");
                $stmt->bind_param('is', $user_id, $title);
                if ($stmt->execute()) {
                    $conversationId = $connTmp->insert_id;
                    $_SESSION['sunny_active_conversation_id'] = $conversationId;
                }
                $stmt->close();
                $connTmp->close();
            }
        }
    } catch (Exception $e) {
        // Ignore; non-fatal for chat
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
                    $toolResults['analyzeDocument'] = normalizeToolResultEnvelope('analyzeDocument', $doc);
                }
            } catch (Exception $e) {
                $toolResults['analyzeDocument'] = normalizeToolResultEnvelope('analyzeDocument', [
                    'success' => false,
                    'error' => 'Document analysis failed: ' . $e->getMessage(),
                    'data' => []
                ]);
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
            sunnyTraceLog('tools_selected', [
                'trace_id' => $sunnyTraceId,
                'user_id' => (int)$user_id,
                'account_id' => $account_id !== null ? (int)$account_id : null,
                'role' => (string)$user_role,
                'tools' => array_values($toolsToUse),
            ]);

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
                        $normalized = normalizeToolResultEnvelope($tool, $result);
                        $toolResults[$tool] = $normalized;
                        sunnyTraceLog('tool_result', [
                            'trace_id' => $sunnyTraceId,
                            'tool' => (string)$tool,
                            'success' => !empty($normalized['success']),
                            'error_code' => $normalized['error_code'] ?? null,
                            'row_count' => is_array($normalized['data'] ?? null) ? count($normalized['data']) : null,
                        ]);
                    }
                } catch (Exception $e) {
                    error_log("Tool execution error for $tool: " . $e->getMessage());
                    $normalized = normalizeToolResultEnvelope($tool, [
                        'success' => false,
                        'error' => 'Tool execution exception: ' . $e->getMessage(),
                        'data' => []
                    ]);
                    $toolResults[$tool] = $normalized;
                    sunnyTraceLog('tool_result', [
                        'trace_id' => $sunnyTraceId,
                        'tool' => (string)$tool,
                        'success' => false,
                        'error_code' => $normalized['error_code'] ?? 'tool_exception',
                        'row_count' => 0,
                    ]);
                }
            }
        } catch (Exception $e) {
            error_log("Tools loading error: " . $e->getMessage());
            sunnyTraceLog('tools_loading_error', [
                'trace_id' => $sunnyTraceId,
                'error' => $e->getMessage(),
            ]);
            // Continue without tools if they fail
        }
    }

    // If no tools executed this turn, but we have prior tool results, reuse them to let the model present them
    if (empty($toolResults) && !empty($lastToolResults)) {
        $toolResults = $lastToolResults;
        sunnyTraceLog('tool_results_reused', [
            'trace_id' => $sunnyTraceId,
            'count' => count($toolResults),
        ]);
    }

    // Build system message starting with the canonical Sunny system prompt (markdown file)
    $promptPath = dirname(__DIR__) . '/sunny_system_prompt.md';
    if (file_exists($promptPath)) {
        $systemMessage = file_get_contents($promptPath);
    } else {
        // Fallback in unlikely scenario the file is missing
        $systemMessage = "You are Sunny, a helpful logistics assistant for Solterra Solutions.";
    }

    // Append FAQ & glossary knowledge so Sunny can answer portal "how do I" questions
    $faqKnowledgePath = dirname(__DIR__) . '/sunny_faq_knowledge.md';
    if (file_exists($faqKnowledgePath)) {
        $systemMessage .= "\n\n" . file_get_contents($faqKnowledgePath);
    }

    // ── Page-awareness: inject context about which page the user is currently viewing ──
    $currentPage = $_GET['page'] ?? '';
    $pageContextMap = [
        'dashboard' => [
            'name' => 'Dashboard',
            'description' => 'The main portfolio overview showing project cards/table, health badges, delivery charts, and key metrics (total MW, cost per watt, deliveries, modules in storage).',
            'help' => 'Help users understand their portfolio status, explain health badge colors, guide them to projects needing attention, and explain the grid/table toggle and MW/module unit switcher.',
            'actions' => ['View project details', 'Filter by health status', 'Toggle grid/table view', 'Switch MW/module units'],
        ],
        'project_overview' => [
            'name' => 'Project Overview',
            'description' => 'Detailed view of a single project showing delivery progress, order progress, module batches, milestones, financials, and delivery timeline.',
            'help' => 'Explain project metrics, delivery vs order progress, milestone status, health badges, and help users find the right action (create shipment, view reports, manage modules).',
            'actions' => ['View delivery progress', 'Check milestones', 'Access reports', 'View module batches', 'Create a shipment'],
        ],
        'create_shipment' => [
            'name' => 'Create Shipment',
            'description' => 'Form for creating new shipments. Users select pallets, choose destination, set shipment mode (single/multi), and enter carrier, cost, and BOL details. Supports domestic and overseas/container flows.',
            'help' => 'Explain the difference between single and multi shipment modes, what each cost field means (freight, customer, accessorial), when to use overseas vs domestic flow, what BOL/Master BOL/House BOL are, and what "pallets per truck" controls.',
            'actions' => ['Select pallets to ship', 'Choose single vs multi mode', 'Enter carrier and cost details', 'Set overseas container info'],
        ],
        'manage_warehouse_inventory' => [
            'name' => 'Warehouse Inventory / Receiving',
            'description' => 'Shows inventory for a specific warehouse or port. Users can receive inbound shipments, view stored pallets, and track outbound movements. Ports additionally handle containers and customs.',
            'help' => 'Walk users through the receiving process, explain port vs warehouse differences, explain storage cost structure (entry/exit/monthly fees), and help with damage reporting during receiving.',
            'actions' => ['Receive inbound shipments', 'View stored inventory', 'Track outbound movements', 'Report damage on receipt'],
        ],
        'warehousing_overview' => [
            'name' => 'Warehousing Overview',
            'description' => 'Aggregate view across all warehouses showing total pallets in storage, inbound/outbound activity, and facility summaries.',
            'help' => 'Help users understand their overall storage footprint, compare warehouse utilization, and navigate to specific warehouse detail pages.',
            'actions' => ['View all warehouse inventory', 'Compare facility utilization', 'Navigate to specific warehouse'],
        ],
        'scheduling' => [
            'name' => 'Scheduling / Delivery Appointments',
            'description' => 'Calendar and form for scheduling delivery appointments at project sites. Users pick dates/times, manage existing appointments, report damage, safety incidents, and upload POD.',
            'help' => 'Explain operating hours, how to schedule and reschedule, what happens when marking as delivered (permanent, triggers milestones), how to report damage and safety incidents, and what POD is.',
            'actions' => ['Schedule a delivery appointment', 'Reschedule existing delivery', 'Mark delivery as completed', 'Report damage or safety incident', 'Upload Proof of Delivery'],
        ],
        'modules' => [
            'name' => 'Manage Modules',
            'description' => 'Lists all module batches with status, wattage, quantities, and palletization progress. Users can add new batches, edit existing ones, and navigate to batch detail pages.',
            'help' => 'Explain what module batches are, what "unassigned" means, palletization status badges, and guide users to palletize or edit batches.',
            'actions' => ['View all module batches', 'Add a new module batch', 'Edit batch details', 'Navigate to palletization'],
        ],
        'module_overview' => [
            'name' => 'Module Batch Overview',
            'description' => 'Detailed view of a single module batch with tabs for palletization, pricing, logistics, and documentation. Users generate/undo pallets and manage batch configuration.',
            'help' => 'Explain how to generate pallets (set modules per pallet, click generate), pallet dimensions, cost per watt, domestic content tracking, and what each tab shows.',
            'actions' => ['Generate pallets', 'Undo palletization', 'View pricing details', 'Manage batch documentation'],
        ],
        'documents' => [
            'name' => 'Project Documents',
            'description' => 'Documents organized by project. Click a project to see all its files.',
            'help' => 'Explain the difference between Project Documents and Global Documents, what document types exist, and how to find specific files.',
            'actions' => ['Browse documents by project', 'Navigate to Global Documents for cross-project search'],
        ],
        'global_documents' => [
            'name' => 'Global Documents',
            'description' => 'All documents across all projects with filters by type, sub-type, project, date range, and BOL number. Supports upload and bulk download.',
            'help' => 'Help users find documents using filters, explain document types and sub-types, guide uploads, and explain bulk download.',
            'actions' => ['Upload a document', 'Filter by type/project/date', 'Bulk download selected files', 'Find a specific BOL document'],
        ],
        'anticipated_deliveries' => [
            'name' => 'Delivery Projections / Anticipated Deliveries',
            'description' => 'Planning tool for modeling delivery routes, stops, legs, and costs. Includes an interactive map for drawing routes and weekly cost projection tables.',
            'help' => 'Explain what stops vs legs are, how freight costs are estimated, the difference between projected and actual costs, and how to use the route builder.',
            'actions' => ['Create a delivery projection', 'Add stops and legs to a route', 'View weekly cost projections', 'Compare projected vs actual costs'],
        ],
        'project_planning' => [
            'name' => 'Project Planning',
            'description' => 'High-level planning view for creating and managing delivery projections across projects. Can create general (unattached) projections for "what-if" analysis.',
            'help' => 'Explain the difference between project delivery plans and general projections, how to start planning, and what Plan Set/In Progress/Add Plan badges mean.',
            'actions' => ['Create a new projection', 'View existing delivery plans', 'Compare scenarios'],
        ],
        'add_project' => [
            'name' => 'Add New Project',
            'description' => 'Form for creating a new project with name, address, target MW, operating hours, and optional site documents.',
            'help' => 'Explain what each field means, especially Project Size (MW), and what site documents are helpful to upload during setup (delivery SOPs, site maps, safety docs).',
            'actions' => ['Fill in project details', 'Set site operating hours', 'Upload site documents'],
        ],
        'add_warehouse' => [
            'name' => 'Add New Warehouse',
            'description' => 'Form for requesting a new warehouse or port facility with address, contact info, and fee structure.',
            'help' => 'Explain the difference between port and warehouse types, what fee fields mean (entry, exit, monthly storage), and that the request goes to an account manager for setup.',
            'actions' => ['Enter warehouse details', 'Set fee structure', 'Submit request'],
        ],
        'questions' => [
            'name' => 'Questions & Support',
            'description' => 'FAQ page with 57 searchable questions across 10 categories, plus a contact form for reaching the Solterra team.',
            'help' => 'Help users find answers in the FAQ, explain how to use the search and category filters, and offer to answer their question directly.',
            'actions' => ['Search the FAQ', 'Filter by category', 'Contact support via the form'],
        ],
        'profile_settings' => [
            'name' => 'Profile Settings',
            'description' => 'User profile management including name, email, phone, password change, and notification preferences.',
            'help' => 'Guide users through updating their profile, changing passwords, and configuring notification preferences (in-app vs email per event type).',
            'actions' => ['Update profile info', 'Change password', 'Manage notification preferences'],
        ],
        'notifications' => [
            'name' => 'Notifications',
            'description' => 'List of in-app notifications for document uploads, project updates, delivery changes, and other events.',
            'help' => 'Explain what each notification type means and how to manage notification preferences from Profile Settings.',
            'actions' => ['View recent notifications', 'Navigate to related items'],
        ],
        'warehouse_info' => [
            'name' => 'Warehouse Detail',
            'description' => 'Detailed info for a specific warehouse showing address, contacts, fee structure (entry/exit/monthly), and operational details.',
            'help' => 'Explain the fee structure, how storage costs are calculated, and how to navigate to the inventory page for this warehouse.',
            'actions' => ['View fee structure', 'See warehouse contacts', 'Navigate to inventory'],
        ],
        'cost_analysis' => [
            'name' => 'Cost Analysis',
            'description' => 'Financial breakdown for a project showing freight costs, accessorial costs, cost per watt, and accounts payable.',
            'help' => 'Explain how costs are categorized, what cost per watt means, and how freight vs accessorial vs customer costs relate.',
            'actions' => ['View cost breakdown', 'Filter by date range', 'Export cost data'],
        ],
        'sustainability' => [
            'name' => 'Sustainability / Domestic Content',
            'description' => 'Domestic content reporting showing weighted average domestic content % across module batches for IRA tax credit compliance.',
            'help' => 'Explain what Domestic Content % means, why it matters for tax credits, and how the weighted average is calculated across batches.',
            'actions' => ['View domestic content percentages', 'Check IRA compliance status'],
        ],
        'module_movements' => [
            'name' => 'Module Movement Tracking',
            'description' => 'Tracks where modules/pallets have been and where they are now. Shows location history through the supply chain: At Manufacturer → In Transit → In Warehouse → In Transit to Project → Delivered.',
            'help' => 'Explain what module movements are, how to read the movement timeline, what each status means, and help users trace where specific pallets are.',
            'actions' => ['Select a project to view movements', 'View movement map', 'Track pallet location history'],
        ],
        'manage_deliveries' => [
            'name' => 'Manage Deliveries',
            'description' => 'Lists all deliveries across projects with status, BOL numbers, scheduling info, and pallet counts. Entry point for editing deliveries, marking as complete, and managing PODs.',
            'help' => 'Help users find specific deliveries, explain delivery statuses, guide them to schedule or edit a delivery, and explain POD requirements.',
            'actions' => ['View all deliveries', 'Filter by status or project', 'Edit delivery details', 'Upload POD'],
        ],
        'manufacturers' => [
            'name' => 'Manufacturers',
            'description' => 'Lists all solar module manufacturers associated with the account. Shows manufacturer details, locations, and associated module batches.',
            'help' => 'Explain how manufacturers relate to module batches, how to request a new manufacturer, and how to view manufacturer details.',
            'actions' => ['View manufacturer list', 'See manufacturer details', 'Request a new manufacturer'],
        ],
        'manufacturer_overview' => [
            'name' => 'Manufacturer Overview',
            'description' => 'Detailed view of a single manufacturer showing locations, contact info, and all associated module batches.',
            'help' => 'Help users understand manufacturer details, find associated batches, and navigate to related module or shipment pages.',
            'actions' => ['View manufacturer locations', 'See associated module batches', 'View contact details'],
        ],
        'invoices' => [
            'name' => 'Invoices',
            'description' => 'Invoice management page showing all invoices (Solterra, freight, module) with filtering and search.',
            'help' => 'Help users find specific invoices, explain invoice types, and guide them through the invoice workflow.',
            'actions' => ['View invoices', 'Filter by type or project', 'Download invoices'],
        ],
        'manage_warehouses' => [
            'name' => 'Manage Warehouses',
            'description' => 'Lists all warehouse and port facilities with addresses, types, and inventory summaries.',
            'help' => 'Explain the difference between ports and warehouses, help users find specific facilities, and guide them to inventory or detail pages.',
            'actions' => ['View all warehouses', 'Navigate to warehouse detail', 'Request a new warehouse'],
        ],
        'bills_of_lading' => [
            'name' => 'Bills of Lading',
            'description' => 'Lists all BOL documents across shipments with status, dates, and associated projects.',
            'help' => 'Explain what BOLs are, help users find specific BOL numbers, and explain the relationship between BOLs and shipments.',
            'actions' => ['Search BOL numbers', 'View BOL details', 'Download BOL documents'],
        ],
        'pods' => [
            'name' => 'Proof of Delivery',
            'description' => 'Lists all POD documents with upload status, delivery dates, and associated projects/BOLs.',
            'help' => 'Explain what PODs are, help users find missing PODs, and guide them through uploading POD documents.',
            'actions' => ['View POD status', 'Upload POD documents', 'Download PODs', 'Identify missing PODs'],
        ],
        'ftd' => [
            'name' => 'Flash Test Data',
            'description' => 'Flash test results from manufacturers verifying module power output. Shows test data per batch.',
            'help' => 'Explain what flash test data is, why it matters (quality verification), and how to view/download test results.',
            'actions' => ['View flash test results', 'Download test data', 'Check module quality verification'],
        ],
        'warranty' => [
            'name' => 'Warranty Claims',
            'description' => 'Warranty claim management showing open/closed claims, damage documentation, and resolution status.',
            'help' => 'Explain the warranty claim process, help users file or track claims, and explain damage documentation requirements.',
            'actions' => ['View warranty claims', 'Create new claim', 'Track claim status', 'Upload damage documentation'],
        ],
        'add_module_batch' => [
            'name' => 'Add Module Batch',
            'description' => 'Form for adding a new module batch with manufacturer, project assignment, wattage/quantity rows, and domestic content tracking.',
            'help' => 'Explain what a module batch is, guide users through the form fields, explain domestic content tracking, and clarify that multiple wattage/quantity rows can be added.',
            'actions' => ['Select manufacturer', 'Assign to project (optional)', 'Enter wattage and quantity', 'Enable domestic content tracking'],
        ],
        'pallet_details' => [
            'name' => 'Pallet Details',
            'description' => 'Detailed view of a single pallet showing its current status, location history, module count, and associated shipment/BOL information.',
            'help' => 'Explain pallet statuses, location history, and how to trace a pallet through the supply chain.',
            'actions' => ['View pallet status', 'See location history', 'View associated shipment'],
        ],
        'project_photos' => [
            'name' => 'Project Photos',
            'description' => 'Photo gallery for a project showing site photos, progress documentation, and damage images.',
            'help' => 'Help users upload and organize project photos, explain photo categories, and guide them through the gallery.',
            'actions' => ['View project photos', 'Upload new photos', 'Reorder photos'],
        ],
        'archived_projects' => [
            'name' => 'Archived Projects',
            'description' => 'List of completed or archived projects that are no longer active.',
            'help' => 'Explain why projects get archived, how to find them, and how to access their historical data.',
            'actions' => ['View archived projects', 'Access historical project data'],
        ],
        'sustainability_overview' => [
            'name' => 'Sustainability Overview',
            'description' => 'Portfolio-wide domestic content and sustainability reporting across all projects.',
            'help' => 'Explain domestic content requirements, IRA tax credit implications, and how the weighted average is calculated.',
            'actions' => ['View portfolio domestic content', 'Check compliance status'],
        ],
        'incident_reports' => [
            'name' => 'Incident Reports',
            'description' => 'Safety incident reports from deliveries including driver incidents, equipment damage, and near-miss events.',
            'help' => 'Explain what constitutes a safety incident, how to file reports, and how incidents are tracked.',
            'actions' => ['View incident reports', 'File new incident report', 'Track resolution status'],
        ],
    ];

    if (!empty($currentPage) && isset($pageContextMap[$currentPage])) {
        $ctx = $pageContextMap[$currentPage];
        $actionsStr = implode(', ', $ctx['actions']);
        $systemMessage .= "\n\n**Current Page Context**\n";
        $systemMessage .= "The user is currently on the **{$ctx['name']}** page (`{$currentPage}.php`).\n";
        $systemMessage .= "Page description: {$ctx['description']}\n";
        $systemMessage .= "How to help on this page: {$ctx['help']}\n";
        $systemMessage .= "Key actions available: {$actionsStr}\n";
        $systemMessage .= "When the user asks vague questions like \"help\", \"what can I do here?\", or \"what is this page?\", use this context to give a specific, relevant answer about THIS page rather than a generic response.\n";
    } elseif (!empty($currentPage)) {
        // Page not in map — provide the page name but don't make a big deal of it
        $readableName = ucwords(str_replace('_', ' ', $currentPage));
        $systemMessage .= "\n\n**Current Page Context**\n";
        $systemMessage .= "The user is currently on the **{$readableName}** page (`{$currentPage}.php`). No detailed context is available for this page, but use the page name to tailor your response. Do NOT tell the user you can't see their page — just answer their question naturally.\n";
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
                $toolError = $data['error_message'] ?? $data['error'] ?? 'unknown';
                $nextStep = $data['actionable_next_step'] ?? null;
                $systemMessage .= "{$tool}: (error: " . $toolError . ")\n";
                if (!empty($nextStep)) {
                    $systemMessage .= "{$tool}_next_step: {$nextStep}\n";
                }
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

    // If we have analyzed document text, surface it explicitly as content
    $docContextText = null;
    if (!empty($toolResults['analyzeDocument']) &&
        !empty($toolResults['analyzeDocument']['success']) &&
        !empty($toolResults['analyzeDocument']['data']) &&
        isset($toolResults['analyzeDocument']['data']['text_preview']) &&
        trim((string)$toolResults['analyzeDocument']['data']['text_preview']) !== '') {
        $docInfo = $toolResults['analyzeDocument']['data'];
        $docName = $docInfo['filename'] ?? 'uploaded_document';
        $docText = $docInfo['text_preview'];
        $docContextText = "Document Content (" . $docName . ")\n---\n" . $docText . "\n---\nUse the document content above to answer or summarize as requested.";
    }

    // ── Build Gemini API payload ──
    // Convert chat history + current message to Gemini format
    $geminiContents = [];

    // Add document context as a user turn if present
    if ($docContextText) {
        $geminiContents[] = ['role' => 'user', 'parts' => [['text' => $docContextText]]];
        $geminiContents[] = ['role' => 'model', 'parts' => [['text' => 'I have reviewed the document content. How can I help you with it?']]];
    }

    // Append recent history (convert OpenAI roles to Gemini roles)
    foreach ($chatHistory as $entry) {
        $geminiRole = ($entry['role'] === 'assistant') ? 'model' : 'user';
        $geminiContents[] = [
            'role' => $geminiRole,
            'parts' => [['text' => $entry['content']]]
        ];
    }

    // Current user message
    $geminiContents[] = ['role' => 'user', 'parts' => [['text' => $message]]];

    // Gemini API payload — use config values for generation settings
    $geminiOptions = $sunnyConfig['gemini']['options'] ?? [];
    $chatData = [
        'system_instruction' => [
            'parts' => [['text' => $systemMessage]]
        ],
        'contents' => $geminiContents,
        'generationConfig' => [
            'maxOutputTokens' => $geminiOptions['maxOutputTokens'] ?? 1000,
            'temperature' => $geminiOptions['temperature'] ?? 0.3,
            'topP' => $geminiOptions['topP'] ?? 0.8,
        ]
    ];

    // Make streaming request to Gemini
    $geminiUrl = $sunnyConfig['gemini']['base_url']
        . '/models/' . $selectedModel
        . ':streamGenerateContent?alt=sse&key=' . urlencode($apiKey);

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $geminiUrl,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($chatData),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json'
        ],
        CURLOPT_WRITEFUNCTION => 'handleGeminiStreamData',
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
        echo "data: " . json_encode(['type' => 'error', 'message' => 'Gemini API request failed: ' . $error]) . "\n\n";
        exit;
    }

    if ($httpCode !== 200) {
        echo "data: " . json_encode(['type' => 'error', 'message' => 'Gemini API returned HTTP ' . $httpCode]) . "\n\n";
        exit;
    }

    // ── Usage recording (best effort) ──
    try {
        if (!empty($geminiUsageMetadata)) {
            $connTrack = getDBConnection();
            if ($connTrack) {
                $today = date('Y-m-d');
                $promptTokens = (int)($geminiUsageMetadata['promptTokenCount'] ?? 0);
                $completionTokens = (int)($geminiUsageMetadata['candidatesTokenCount'] ?? 0);

                // Calculate estimated cost
                $modelCosts = $sunnyConfig['model_costs'][$selectedModel] ?? null;
                $estimatedCost = 0.0;
                if ($modelCosts) {
                    $estimatedCost = ($promptTokens / 1000000) * $modelCosts['input_per_million']
                                   + ($completionTokens / 1000000) * $modelCosts['output_per_million'];
                }

                $stmtTrack = $connTrack->prepare(
                    "INSERT INTO sunny_usage (user_id, usage_date, model, prompt_tokens, completion_tokens, request_count, estimated_cost_usd)
                     VALUES (?, ?, ?, ?, ?, 1, ?)
                     ON DUPLICATE KEY UPDATE
                        prompt_tokens = prompt_tokens + VALUES(prompt_tokens),
                        completion_tokens = completion_tokens + VALUES(completion_tokens),
                        request_count = request_count + 1,
                        estimated_cost_usd = estimated_cost_usd + VALUES(estimated_cost_usd)"
                );
                $stmtTrack->bind_param('issiid', $user_id, $today, $selectedModel, $promptTokens, $completionTokens, $estimatedCost);
                $stmtTrack->execute();
                $stmtTrack->close();

                // Send updated usage info after recording
                $dailyLimit = $sunnyConfig['usage_caps']['daily_token_limit'] ?? 100000;
                $brightModel = $sunnyConfig['gemini']['models']['bright'];
                $brightMult = $sunnyConfig['usage_caps']['bright_multiplier'] ?? 3;
                $stmtPost = $connTrack->prepare(
                    "SELECT COALESCE(SUM(
                        (prompt_tokens + completion_tokens) *
                        CASE WHEN model = ? THEN ? ELSE 1 END
                    ), 0) AS weighted_tokens
                    FROM sunny_usage
                    WHERE user_id = ? AND usage_date = ?"
                );
                $stmtPost->bind_param('siis', $brightModel, $brightMult, $user_id, $today);
                $stmtPost->execute();
                $postUsage = $stmtPost->get_result()->fetch_assoc();
                $stmtPost->close();
                $connTrack->close();

                $postWeighted = (int)($postUsage['weighted_tokens'] ?? 0);
                $postRemaining = max(0, round((1 - ($postWeighted / $dailyLimit)) * 100));

                echo "data: " . json_encode([
                    'type' => 'usage_info',
                    'remaining_percent' => $postRemaining,
                    'mode' => $mode
                ]) . "\n\n";
                flush();
            }
        }
    } catch (Exception $e) {
        error_log("Usage recording failed: " . $e->getMessage());
    }

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

        // Also persist to DB conversation (best effort)
        try {
            if (!empty($_SESSION['sunny_active_conversation_id'])) {
                $cid = intval($_SESSION['sunny_active_conversation_id']);
                $conn2 = getDBConnection();
                if ($conn2) {
                    $stmt1 = $conn2->prepare("INSERT INTO sunny_messages (conversation_id, role, content) VALUES (?,?,?)");
                    $roleUser = 'user';
                    $stmt1->bind_param('iss', $cid, $roleUser, $message);
                    $stmt1->execute();
                    $stmt1->close();

                    $stmt2 = $conn2->prepare("INSERT INTO sunny_messages (conversation_id, role, content) VALUES (?,?,?)");
                    $roleAsst = 'assistant';
                    $stmt2->bind_param('iss', $cid, $roleAsst, $assistantResponseBuffer);
                    $stmt2->execute();
                    $stmt2->close();

                    $stmt3 = $conn2->prepare("UPDATE sunny_conversations SET last_message_at = NOW() WHERE id = ?");
                    $stmt3->bind_param('i', $cid);
                    $stmt3->execute();
                    $stmt3->close();

                    $conn2->close();
                }
            }
        } catch (Exception $e) {
            // Swallow persistence errors, don't break SSE
        }
    }

    sunnyTraceLog('request_complete', [
        'trace_id' => $sunnyTraceId,
        'user_id' => (int)$user_id,
        'account_id' => $account_id !== null ? (int)$account_id : null,
        'role' => (string)$user_role,
        'duration_ms' => (int)round((microtime(true) - $sunnyRequestStartedAt) * 1000),
        'tools_executed' => array_keys($toolResults),
        'assistant_chars' => strlen((string)($assistantResponseBuffer ?? '')),
    ]);

    // Send completion signal (after persistence)
    echo "data: " . json_encode(['type' => 'complete', 'conversation_id' => ($_SESSION['sunny_active_conversation_id'] ?? null)]) . "\n\n";
    flush();

} catch (Exception $e) {
    sunnyTraceLog('request_exception', [
        'trace_id' => $sunnyTraceId ?? null,
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
    ]);
    error_log("Chat stream error: " . $e->getMessage() . " | File: " . $e->getFile() . " | Line: " . $e->getLine());
    echo "data: " . json_encode(['type' => 'error', 'message' => 'Error: ' . $e->getMessage()]) . "\n\n";
}

function sunnyTraceLog($event, $payload = []) {
    $entry = [
        'event' => $event,
        'at' => date('c'),
        'payload' => $payload
    ];
    error_log('[SUNNY_TRACE] ' . json_encode($entry));
}

function isSunnyRoleAllowed($role, $allowedRoles) {
    if (empty($allowedRoles) || !is_array($allowedRoles)) {
        return true;
    }

    $roleNormalized = strtolower(trim((string)$role));
    foreach ($allowedRoles as $allowedRole) {
        if ($roleNormalized === strtolower(trim((string)$allowedRole))) {
            return true;
        }
    }
    return false;
}

function resolveSunnyAccountContext($userId, $userRole, $sessionAccountId = null) {
    $roleNormalized = strtolower(trim((string)$userRole));
    if ($roleNormalized === 'global_admin') {
        return [
            'success' => true,
            'account_id' => null,
            'source' => 'global_admin'
        ];
    }

    $sessionAccountId = is_numeric($sessionAccountId) ? (int)$sessionAccountId : null;
    if (!empty($sessionAccountId) && $sessionAccountId > 0) {
        return [
            'success' => true,
            'account_id' => $sessionAccountId,
            'source' => 'session'
        ];
    }

    $conn = getDBConnection();
    if (!$conn) {
        return [
            'success' => false,
            'error_code' => 'account_context_db_unavailable',
            'error_message' => 'Unable to access account context right now. Please try again.'
        ];
    }

    $accounts = [];
    $stmt = $conn->prepare("SELECT DISTINCT account_id FROM customer_account_users WHERE user_id = ? AND LOWER(role) = LOWER(?) ORDER BY account_id ASC LIMIT 2");
    if ($stmt) {
        $stmt->bind_param("is", $userId, $userRole);
        $stmt->execute();
        $stmt->bind_result($accountId);
        while ($stmt->fetch()) {
            $accounts[] = (int)$accountId;
        }
        $stmt->close();
    }

    // Legacy fallback for environments where role mappings are incomplete.
    if (count($accounts) === 0) {
        $stmt = $conn->prepare("SELECT DISTINCT account_id FROM customer_account_users WHERE user_id = ? ORDER BY account_id ASC LIMIT 2");
        if ($stmt) {
            $stmt->bind_param("i", $userId);
            $stmt->execute();
            $stmt->bind_result($accountId);
            while ($stmt->fetch()) {
                $accounts[] = (int)$accountId;
            }
            $stmt->close();
        }
    }
    $conn->close();

    if (count($accounts) === 1) {
        return [
            'success' => true,
            'account_id' => $accounts[0],
            'source' => 'customer_account_users'
        ];
    }
    if (count($accounts) > 1) {
        return [
            'success' => false,
            'error_code' => 'account_context_ambiguous',
            'error_message' => 'Your account context is ambiguous. Please contact support to correct account mappings.'
        ];
    }

    return [
        'success' => false,
        'error_code' => 'account_context_missing',
        'error_message' => 'No account context was found for your login. Please contact support.'
    ];
}

function normalizeToolResultEnvelope($toolName, $result) {
    if (!is_array($result)) {
        $result = [
            'success' => false,
            'error' => 'Tool returned an invalid response format',
            'data' => []
        ];
    }

    $success = !empty($result['success']);
    if ($success) {
        $result['success'] = true;
        $result['data'] = (isset($result['data']) && is_array($result['data'])) ? $result['data'] : [];
        $result['error_code'] = null;
        $result['error_message'] = null;
        $result['actionable_next_step'] = null;
        return $result;
    }

    $rawError = trim((string)($result['error'] ?? $result['error_message'] ?? 'Unknown tool error'));
    $errorCode = classifySunnyToolErrorCode($rawError);
    $result['success'] = false;
    $result['data'] = (isset($result['data']) && is_array($result['data'])) ? $result['data'] : [];
    $result['error_code'] = $errorCode;
    $result['error_message'] = $rawError;
    $result['actionable_next_step'] = suggestSunnyToolNextStep($errorCode, $toolName);
    return $result;
}

function classifySunnyToolErrorCode($errorMessage) {
    $lower = strtolower((string)$errorMessage);
    if (strpos($lower, 'access denied') !== false || strpos($lower, 'unauthorized') !== false || strpos($lower, 'permission') !== false) {
        return 'permission_denied';
    }
    if (strpos($lower, 'database') !== false || strpos($lower, 'query') !== false || strpos($lower, 'prepare') !== false || strpos($lower, 'connection') !== false) {
        return 'database_error';
    }
    if (strpos($lower, 'timeout') !== false) {
        return 'timeout';
    }
    if (strpos($lower, 'unknown tool') !== false) {
        return 'unknown_tool';
    }
    return 'tool_error';
}

function suggestSunnyToolNextStep($errorCode, $toolName) {
    switch ($errorCode) {
        case 'permission_denied':
            return 'Verify your role permissions for this request scope.';
        case 'database_error':
            return 'Try again in a moment. If this continues, contact support.';
        case 'timeout':
            return 'Try a narrower filter such as a specific project or timeframe.';
        case 'unknown_tool':
            return 'Rephrase the request with the specific data you want.';
        default:
            return 'Try specifying a project, warehouse, or timeframe.';
    }
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
    if (preg_match('/\b(project|projects|status|portfolio)\b/', $message)) {
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
    if (
        preg_match('/\b(inventory|warehouse|storage|stock)\b/', $message) ||
        (preg_match('/\b(module|modules)\b/', $message) && preg_match('/\b(in storage|stored|warehouse|inventory)\b/', $message))
    ) {
        $tools[] = 'getWarehouseInventory';
    }

    // Cost / value related
    if (preg_match('/\b(cost|costs|value|valuation|price|pricing|spend|spent|payable|financial|\$)\b/i', $message)) {
        $tools[] = 'getProjectCostAnalysis';
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
        '/\b(remember|save|store|my name is|my name\'?s|call me|i prefer|i like|i need|i want)\b/',
        '/\b(please remember|don\'t forget|keep in mind|note that)\b/',
        '/\bi\'?m\s+[A-Z]/i'
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

    // Extract name — handles "my name is Connor", "my name's Connor", "I'm Connor", "call me Connor"
    if (preg_match('/my name(?:\'?s|\s+is)\s+([a-zA-Z][a-zA-Z\s\-]*)/i', $message, $matches)) {
        $name = trim(preg_replace('/[\.\!\?]+$/', '', trim($matches[1])));
        $title = 'User name';
        $content = "User's name is {$name}";
        $type = 'preference';
        $importance = 3;
    }
    else if (preg_match('/(?:^|\b)I\'?m\s+([A-Z][a-zA-Z\-]+)/u', $message, $matches)) {
        $name = trim($matches[1]);
        $title = 'User name';
        $content = "User's name is {$name}";
        $type = 'preference';
        $importance = 3;
    }
    else if (preg_match('/call me\s+([a-zA-Z][a-zA-Z\s\-]*)/i', $message, $matches)) {
        $name = trim(preg_replace('/[\.\!\?]+$/', '', trim($matches[1])));
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
    // General remember requests — strip trailing filler like "that", "that?"
    else if (preg_match('/remember\s+(.+)/i', $message, $matches)) {
        $toRemember = trim($matches[1]);
        // If the captured text is just filler like "that", "that?", "this" — use the full message as context
        if (preg_match('/^(that|this|it)\?*$/i', $toRemember)) {
            $title = 'User note';
            $content = $message;
            $type = 'note';
            $importance = 2;
        } else {
            $title = 'User note';
            $content = $toRemember;
            $type = 'note';
            $importance = 2;
        }
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

/**
 * Gemini SSE stream parser
 * Handles Gemini's streaming format: data: {JSON} lines
 * Extracts text from candidates[0].content.parts[0].text
 * Captures usageMetadata from final chunk
 */
function handleGeminiStreamData($ch, $data) {
    global $streamBuffer, $assistantResponseBuffer, $geminiUsageMetadata;

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

        if (empty($jsonData)) {
            continue;
        }

        try {
            $json = json_decode($jsonData, true);
            if (!$json) continue;

            // Extract text content from Gemini response
            if (isset($json['candidates'][0]['content']['parts'][0]['text'])) {
                $content = $json['candidates'][0]['content']['parts'][0]['text'];
                if ($content !== '') {
                    $assistantResponseBuffer .= $content;

                    // Send token to client
                    echo "data: " . json_encode([
                        'type' => 'token',
                        'content' => $content
                    ]) . "\n\n";
                    flush();
                }
            }

            // Capture usage metadata (typically in the final chunk)
            if (isset($json['usageMetadata'])) {
                $geminiUsageMetadata = $json['usageMetadata'];
            }

            // Check for finish reason
            if (isset($json['candidates'][0]['finishReason']) &&
                $json['candidates'][0]['finishReason'] === 'STOP') {
                $streamBuffer = '';
                break;
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
