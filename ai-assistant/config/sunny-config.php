<?php
/**
 * Sunny AI Assistant Configuration
 * 
 * Configuration settings for the Sunny AI chat assistant
 */

return [
    // Ollama AI Service Settings
    'ollama' => [
        'base_url' => 'http://129.153.150.117:80',
        'model' => 'gemma:2b',
        'timeout' => 60,
        'options' => [
            'temperature' => 0.3,
            'top_p' => 0.8,
            'num_predict' => 50,
            'num_ctx' => 512,
            'top_k' => 10,
            'num_thread' => 4,
            'num_batch' => 8
        ]
    ],
    
    // Chat Settings
    'chat' => [
        'max_message_length' => 1000,
        'rate_limit_per_minute' => 10,
        'enable_quick_actions' => true,
        'enable_typing_indicator' => true
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
        ]
    ],
    
    // Logging Settings  
    'logging' => [
        'enable_chat_logs' => true,
        'enable_error_logs' => true,
        'log_file_path' => __DIR__ . '/../logs/sunny.log',
        'max_log_size' => 10485760 // 10MB
    ]
];
?> 