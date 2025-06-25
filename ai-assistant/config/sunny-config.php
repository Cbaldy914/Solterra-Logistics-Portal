<?php
/**
 * Sunny AI Assistant Configuration
 * 
 * Configuration settings for the Sunny AI chat assistant
 */

// Load main config for API key helper functions
require_once __DIR__ . '/../../../config.php';

return [
    // OpenAI API Service Settings
    'openai' => [
        'api_key' => getOpenAIApiKey(), // Use config helper function
        'base_url' => 'https://api.openai.com/v1',
        'model' => 'gpt-4o-mini', // Start cost-effective, can upgrade to O3 later
        'timeout' => 30,
        'options' => [
            'temperature' => 0.3,
            'top_p' => 0.8,
            'max_tokens' => 1000,
            'stream' => true
        ]
    ],
    
    // Alternative models (updated pricing)
    'alternative_models' => [
        'gpt-4o' => [
            'cost_per_1k_input' => 0.0025,
            'cost_per_1k_output' => 0.01,
            'description' => 'Good quality, higher cost than O3'
        ],
        'gpt-4o-mini' => [
            'cost_per_1k_input' => 0.00015,
            'cost_per_1k_output' => 0.0006,
            'description' => 'Most cost effective, good quality'
        ],
        'o3' => [
            'cost_per_1k_input' => 2.0,
            'cost_per_1k_output' => 8.0,
            'description' => 'Highest reasoning, competitive pricing!'
        ]
    ],
    
    // Chat Settings
    'chat' => [
        'max_message_length' => 1000,
        'rate_limit_per_minute' => 10,
        'enable_quick_actions' => true,
        'enable_typing_indicator' => true,
        'cost_tracking' => true, // Enable cost tracking
        'max_cost_per_user_per_day' => 2.00 // Increased limit for O3 usage
    ],
    
    // Security Settings
    'security' => [
        'require_authentication' => true,
        'allowed_roles' => ['user', 'admin', 'global_admin', 'DDPm'],
        'enable_sql_filtering' => true,
        'log_queries' => true
    ],
    
    // Database Tools Settings
    'tools' => [
        'getProjectSummary' => true,
        'getDeliveryStatus' => true,
        'getWarehouseInventory' => true,
        'getModuleMovements' => true,
        'getProjectCostAnalysis' => true,
        'getDeliveryPerformance' => true,
        'searchLogistics' => true,
        'getKPIDashboard' => true
    ],
    
    // UI Settings
    'ui' => [
        'assistant_name' => 'Sunny',
        'assistant_title' => 'Logistics Assistant',
        'welcome_message' => "Hi there! 👋 I'm Sunny, your logistics assistant. I can help you track deliveries, check project status, and answer questions about your shipments.",
        'quick_actions' => [
            'Recent Deliveries',
            'Project Status', 
            'Inventory Summary'
        ],
        'show_cost_info' => true // Show cost information to users
    ],
    
    // Logging Settings  
    'logging' => [
        'enable_chat_logs' => true,
        'enable_error_logs' => true,
        'enable_cost_logs' => true,
        'log_file_path' => __DIR__ . '/../logs/sunny.log',
        'cost_log_file_path' => __DIR__ . '/../logs/sunny-costs.log',
        'max_log_size' => 10485760 // 10MB
    ]
];
?> 