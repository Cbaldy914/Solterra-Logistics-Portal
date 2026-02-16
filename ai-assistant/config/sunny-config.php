<?php
/**
 * Sunny AI Assistant Configuration
 *
 * Configuration settings for the Sunny AI chat assistant
 * Powered by Google Gemini
 */

// Load main config for API key helper functions
require_once __DIR__ . '/../../../config.php';

return [
    // Gemini API Service Settings
    'gemini' => [
        'api_key' => getGoogleAIApiKey(),
        'base_url' => 'https://generativelanguage.googleapis.com/v1beta',
        'models' => [
            'default' => 'gemini-2.5-flash',
            'bright'  => 'gemini-3-flash',
        ],
        'timeout' => 30,
        'options' => [
            'temperature' => 0.3,
            'topP' => 0.8,
            'maxOutputTokens' => 1000,
        ]
    ],

    // Model costs (per million tokens)
    'model_costs' => [
        'gemini-2.5-flash' => [
            'input_per_million'  => 0.15,
            'output_per_million' => 0.60,
            'description' => 'Fast, efficient model for everyday tasks',
        ],
        'gemini-3-flash' => [
            'input_per_million'  => 0.45,
            'output_per_million' => 1.80,
            'description' => 'More powerful reasoning, ~3x cost of default',
        ],
    ],

    // Usage caps
    'usage_caps' => [
        'daily_token_limit'    => 500000,
        'monthly_token_limit'  => 2000000,
        'daily_request_limit'  => 100,
        'bright_multiplier'    => 3,
        'warning_threshold'    => 0.80,
    ],

    // Chat Settings
    'chat' => [
        'max_message_length' => 1000,
        'rate_limit_per_minute' => 10,
        'enable_quick_actions' => true,
        'enable_typing_indicator' => true,
        'cost_tracking' => true,
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
        'welcome_message' => "Hi there! I'm Sunny, your logistics assistant. I can help you track deliveries, check project status, and answer questions about your shipments.",
        'quick_actions' => [
            'Recent Deliveries',
            'Project Status',
            'Inventory Summary'
        ],
        'show_cost_info' => true
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
