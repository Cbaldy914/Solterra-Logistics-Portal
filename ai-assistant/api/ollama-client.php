<?php
/**
 * Ollama Client for Sunny AI Assistant
 * Handles communication with Ollama API and streaming responses
 */

class OllamaClient {
    private $baseUrl;
    private $model;
    private $systemPrompt;
    private $timeout;
    
    public function __construct($baseUrl = 'https://ai.solterrasol.com:11434', $model = 'gemma:2b') {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->model = $model;
        $this->timeout = 120; // 2 minutes timeout
        $this->loadSystemPrompt();
    }
    
    private function loadSystemPrompt() {
        $promptFile = dirname(__DIR__) . '/sunny_system_prompt.md';
        if (file_exists($promptFile)) {
            $this->systemPrompt = file_get_contents($promptFile);
        } else {
            $this->systemPrompt = $this->getDefaultSystemPrompt();
        }
    }
    
    private function getDefaultSystemPrompt() {
        return "You are Sunny, the virtual Logistics Analyst for Solterra Solutions' customer portal.

**Mission**
• Help customers retrieve reports, shipment documents, and status updates.
• Answer logistics questions in plain English, backed by Solterra data.
• Suggest next steps that drive smart, timely decisions.

**Data Access**
• Use only the read-only API functions provided by the backend.
• Every call must include account_id filtering to enforce tenant isolation.
• If the user asks for something outside their account, politely refuse.

**Tone & Style**
• Friendly, concise, and professional—think experienced analyst on Slack.
• When sharing numbers, include context.
• End each actionable reply with a short question that moves the conversation forward.

**Security & Privacy**
• Never reveal internal IDs, SQL, or stack traces.
• Strip or mask any PII unless the user already sees it in the portal.
• If unsure or the data isn't available, say so and offer to escalate to human support.";
    }
    
    /**
     * Generate a streaming response from Ollama
     */
    public function generateStreamingResponse($userMessage, $context = [], $toolResults = []) {
        $prompt = $this->buildPrompt($userMessage, $context, $toolResults);
        
        $data = [
            'model' => $this->model,
            'prompt' => $prompt,
            'stream' => true,
            'options' => [
                'temperature' => 0.7,
                'top_k' => 40,
                'top_p' => 0.9,
                'num_predict' => 1000
            ]
        ];
        
        return $this->streamRequest('/api/generate', $data);
    }
    
    /**
     * Generate a non-streaming response from Ollama
     */
    public function generateResponse($userMessage, $context = [], $toolResults = []) {
        $prompt = $this->buildPrompt($userMessage, $context, $toolResults);
        
        $data = [
            'model' => $this->model,
            'prompt' => $prompt,
            'stream' => false,
            'options' => [
                'temperature' => 0.7,
                'top_k' => 40,
                'top_p' => 0.9,
                'num_predict' => 1000
            ]
        ];
        
        return $this->makeRequest('/api/generate', $data);
    }
    
    /**
     * Build the complete prompt with system prompt, context, and user message
     */
    private function buildPrompt($userMessage, $context = [], $toolResults = []) {
        $prompt = $this->systemPrompt . "\n\n";
        
        // Add user context
        if (!empty($context)) {
            $prompt .= "**User Context:**\n";
            $prompt .= "Role: " . ($context['role'] ?? 'user') . "\n";
            $prompt .= "Account ID: " . ($context['account_id'] ?? 'unknown') . "\n";
            if (isset($context['username'])) {
                $prompt .= "Username: " . $context['username'] . "\n";
            }
            $prompt .= "\n";
        }
        
        // Add tool results if available
        if (!empty($toolResults)) {
            $prompt .= "**Available Data:**\n";
            foreach ($toolResults as $toolName => $result) {
                $prompt .= "Tool: {$toolName}\n";
                if ($result['success']) {
                    $prompt .= "Result: " . json_encode($result['data'], JSON_PRETTY_PRINT) . "\n";
                } else {
                    $prompt .= "Error: " . $result['error'] . "\n";
                }
                $prompt .= "\n";
            }
        }
        
        // Add the actual user message
        $prompt .= "**User Question:** " . $userMessage . "\n\n";
        $prompt .= "**Response:**";
        
        return $prompt;
    }
    
    /**
     * Make a streaming request to Ollama API
     */
    private function streamRequest($endpoint, $data) {
        $url = $this->baseUrl . $endpoint;
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json'
            ],
            CURLOPT_WRITEFUNCTION => [$this, 'handleStreamChunk'],
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => false, // For development - should be true in production
            CURLOPT_SSL_VERIFYHOST => false
        ]);
        
        $this->streamCallback = null;
        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($result === false || !empty($error)) {
            return [
                'success' => false,
                'error' => $error ?: 'Request failed'
            ];
        }
        
        if ($httpCode !== 200) {
            return [
                'success' => false,
                'error' => "HTTP {$httpCode} error"
            ];
        }
        
        return [
            'success' => true,
            'message' => 'Streaming completed'
        ];
    }
    
    /**
     * Handle individual chunks of streaming response
     */
    private function handleStreamChunk($ch, $chunk) {
        if ($this->streamCallback) {
            call_user_func($this->streamCallback, $chunk);
        }
        return strlen($chunk);
    }
    
    /**
     * Set callback function for handling stream chunks
     */
    public function setStreamCallback($callback) {
        $this->streamCallback = $callback;
    }
    
    /**
     * Make a regular (non-streaming) request to Ollama API
     */
    private function makeRequest($endpoint, $data) {
        $url = $this->baseUrl . $endpoint;
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json'
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => false, // For development
            CURLOPT_SSL_VERIFYHOST => false
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($response === false || !empty($error)) {
            return [
                'success' => false,
                'error' => $error ?: 'Request failed'
            ];
        }
        
        if ($httpCode !== 200) {
            return [
                'success' => false,
                'error' => "HTTP {$httpCode} error",
                'response' => $response
            ];
        }
        
        $decodedResponse = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return [
                'success' => false,
                'error' => 'Invalid JSON response'
            ];
        }
        
        return [
            'success' => true,
            'data' => $decodedResponse
        ];
    }
    
    /**
     * Check if Ollama service is available
     */
    public function checkHealth() {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $this->baseUrl . '/api/tags',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        return [
            'available' => ($response !== false && $httpCode === 200),
            'http_code' => $httpCode,
            'base_url' => $this->baseUrl
        ];
    }
    
    /**
     * Get available models
     */
    public function getModels() {
        return $this->makeRequest('/api/tags', []);
    }
    
    /**
     * Process tool usage in user message
     */
    public function parseToolUsage($userMessage) {
        $tools = [];
        
        // Simple pattern matching for tool usage
        // In a more sophisticated implementation, you'd use the AI to determine tool usage
        
        if (stripos($userMessage, 'project') !== false) {
            if (stripos($userMessage, 'summary') !== false || stripos($userMessage, 'status') !== false) {
                $tools[] = 'getProjectSummary';
            }
        }
        
        if (stripos($userMessage, 'deliver') !== false || stripos($userMessage, 'shipment') !== false) {
            $tools[] = 'getDeliveryStatus';
        }
        
        if (stripos($userMessage, 'warehouse') !== false || stripos($userMessage, 'inventory') !== false) {
            $tools[] = 'getWarehouseInventory';
        }
        
        if (stripos($userMessage, 'movement') !== false) {
            $tools[] = 'getModuleMovements';
        }
        
        if (stripos($userMessage, 'cost') !== false || stripos($userMessage, 'analysis') !== false) {
            $tools[] = 'getProjectCostAnalysis';
        }
        
        if (stripos($userMessage, 'performance') !== false || stripos($userMessage, 'metrics') !== false) {
            $tools[] = 'getDeliveryPerformance';
        }
        
        if (stripos($userMessage, 'dashboard') !== false || stripos($userMessage, 'overview') !== false) {
            $tools[] = 'getKPIDashboard';
        }
        
        // Search functionality
        if (stripos($userMessage, 'search') !== false || stripos($userMessage, 'find') !== false) {
            $tools[] = 'searchLogistics';
        }
        
        return $tools;
    }
    
    /**
     * Update system prompt
     */
    public function setSystemPrompt($prompt) {
        $this->systemPrompt = $prompt;
    }
    
    /**
     * Get current system prompt
     */
    public function getSystemPrompt() {
        return $this->systemPrompt;
    }
} 