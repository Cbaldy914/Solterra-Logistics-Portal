<?php
/**
 * OpenAI Client for Sunny AI Assistant
 * Handles communication with OpenAI API and streaming responses
 */

class OpenAIClient {
    private $apiKey;
    private $baseUrl;
    private $model;
    private $systemPrompt;
    private $timeout;
    private $streamCallback;
    
    public function __construct($apiKey, $baseUrl = 'https://api.openai.com/v1', $model = 'gpt-4.1-mini') {
        $this->apiKey = $apiKey;
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->model = $model;
        $this->timeout = 30;
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
     * Generate a streaming response from OpenAI
     */
    public function generateStreamingResponse($userMessage, $context = [], $toolResults = []) {
        $messages = $this->buildMessages($userMessage, $context, $toolResults);
        
        $data = [
            'model' => $this->model,
            'messages' => $messages,
            'stream' => true,
            'temperature' => 0.7,
            'max_tokens' => 1000,
            'top_p' => 0.9
        ];
        
        return $this->streamRequest('/chat/completions', $data);
    }
    
    /**
     * Generate a non-streaming response from OpenAI
     */
    public function generateResponse($userMessage, $context = [], $toolResults = []) {
        $messages = $this->buildMessages($userMessage, $context, $toolResults);
        
        $data = [
            'model' => $this->model,
            'messages' => $messages,
            'stream' => false,
            'temperature' => 0.7,
            'max_tokens' => 1000,
            'top_p' => 0.9
        ];
        
        return $this->makeRequest('/chat/completions', $data);
    }
    
    /**
     * Build the messages array for OpenAI API
     */
    private function buildMessages($userMessage, $context = [], $toolResults = []) {
        $messages = [];
        
        // System message
        $systemMessage = $this->systemPrompt;
        
        // Add user context
        if (!empty($context)) {
            $systemMessage .= "\n\n**User Context:**\n";
            $systemMessage .= "Role: " . ($context['role'] ?? 'user') . "\n";
            $systemMessage .= "Account ID: " . ($context['account_id'] ?? 'unknown') . "\n";
            if (isset($context['username'])) {
                $systemMessage .= "Username: " . $context['username'] . "\n";
            }
        }
        
        // Add tool results if available
        if (!empty($toolResults)) {
            $systemMessage .= "\n\n**Available Data:**\n";
            foreach ($toolResults as $toolName => $result) {
                $systemMessage .= "Tool: {$toolName}\n";
                if ($result['success']) {
                    $systemMessage .= "Result: " . json_encode($result['data'], JSON_PRETTY_PRINT) . "\n";
                } else {
                    $systemMessage .= "Error: " . $result['error'] . "\n";
                }
                $systemMessage .= "\n";
            }
        }
        
        $messages[] = [
            'role' => 'system',
            'content' => $systemMessage
        ];
        
        $messages[] = [
            'role' => 'user',
            'content' => $userMessage
        ];
        
        return $messages;
    }
    
    /**
     * Make a streaming request to OpenAI API
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
                'Authorization: Bearer ' . $this->apiKey
            ],
            CURLOPT_WRITEFUNCTION => [$this, 'handleStreamChunk'],
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2
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
     * Make a regular (non-streaming) request to OpenAI API
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
                'Authorization: Bearer ' . $this->apiKey
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2
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
     * Check API health by making a simple request
     */
    public function checkHealth() {
        $response = $this->makeRequest('/models', []);
        return $response['success'] ?? false;
    }
    
    /**
     * Calculate token usage and cost
     */
    public function calculateCost($inputTokens, $outputTokens) {
        // Cost per 1K tokens (in USD) - Updated pricing
        $costs = [
            'gpt-4.1-mini' => ['input' => 0.0004, 'output' => 0.0016],
            'gpt-4o-mini' => ['input' => 0.00015, 'output' => 0.0006],
            'gpt-4o' => ['input' => 0.0025, 'output' => 0.01],
            'o3' => ['input' => 2.0, 'output' => 8.0], // Updated pricing!
            'o3-mini' => ['input' => 1.0, 'output' => 4.0]
        ];
        
        $modelCosts = $costs[$this->model] ?? $costs['gpt-4o-mini'];
        
        $inputCost = ($inputTokens / 1000) * $modelCosts['input'];
        $outputCost = ($outputTokens / 1000) * $modelCosts['output'];
        
        return [
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'input_cost' => $inputCost,
            'output_cost' => $outputCost,
            'total_cost' => $inputCost + $outputCost,
            'model' => $this->model
        ];
    }
    
    /**
     * Set system prompt
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
    
    /**
     * Set model
     */
    public function setModel($model) {
        $this->model = $model;
    }
    
    /**
     * Get current model
     */
    public function getModel() {
        return $this->model;
    }
}
?> 