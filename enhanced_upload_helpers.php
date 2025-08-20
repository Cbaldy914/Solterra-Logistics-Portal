<?php
/**
 * Enhanced Upload Helpers for Multiple Document Types
 * Extends the basic document helpers with specific logic for each document type
 */

require_once 'document_helpers.php';

/**
 * Enhanced document type configurations with sub-types and validation rules
 */
function getDocumentTypeConfig() {
    return [
        'invoices' => [
            'name' => 'Invoices',
            'icon' => 'fas fa-file-invoice-dollar',
            'color' => '#22c55e',
            'sub_types' => [
                'Solterra Invoices' => ['description' => 'Internal Solterra billing documents'],
                'OEM Invoices' => ['description' => 'Original Equipment Manufacturer invoices'],
                'Warehouse Invoices' => ['description' => 'Warehousing and storage fees'],
                'Freight Invoices' => ['description' => 'Transportation and logistics costs']
            ],
            'required_context' => [],
            'validation_rules' => [
                'max_size' => 10 * 1024 * 1024, // 10MB
                'allowed_extensions' => ['pdf', 'xlsx', 'xls', 'doc', 'docx']
            ]
        ],
        'pods' => [
            'name' => 'PODs',
            'icon' => 'fas fa-clipboard-check',
            'color' => '#3b82f6',
            'sub_types' => [
                'Warehouse PODs' => ['description' => 'Proof of delivery to warehouse'],
                'Project PODs' => ['description' => 'Proof of delivery to project site'],
                'Delivery PODs' => ['description' => 'General delivery confirmations']
            ],
            'required_context' => ['delivery_id'],
            'validation_rules' => [
                'max_size' => 5 * 1024 * 1024, // 5MB
                'allowed_extensions' => ['pdf', 'jpg', 'jpeg', 'png']
            ]
        ],
        'bills_of_lading' => [
            'name' => 'Bills of Lading',
            'icon' => 'fas fa-shipping-fast',
            'color' => '#8b5cf6',
            'sub_types' => [
                'Inbound BOL' => ['description' => 'Incoming shipment documentation'],
                'Outbound BOL' => ['description' => 'Outgoing shipment documentation'],
                'Intercompany BOL' => ['description' => 'Internal transfer documentation']
            ],
            'required_context' => ['delivery_id'],
            'validation_rules' => [
                'max_size' => 10 * 1024 * 1024, // 10MB
                'allowed_extensions' => ['pdf', 'doc', 'docx']
            ]
        ],
        'warehousing' => [
            'name' => 'Warehousing',
            'icon' => 'fas fa-warehouse',
            'color' => '#06b6d4',
            'sub_types' => [
                'Storage Receipts' => ['description' => 'Warehouse storage confirmations'],
                'Handling Receipts' => ['description' => 'Material handling documentation'],
                'Inspection Reports' => ['description' => 'Quality inspection results']
            ],
            'required_context' => ['warehouse_id'],
            'validation_rules' => [
                'max_size' => 20 * 1024 * 1024, // 20MB
                'allowed_extensions' => ['pdf', 'doc', 'docx', 'xlsx', 'jpg', 'png']
            ]
        ],
        'modules' => [
            'name' => 'Modules',
            'icon' => 'fas fa-microchip',
            'color' => '#10b981',
            'sub_types' => [
                'Specifications' => ['description' => 'Technical specifications and datasheets'],
                'Certifications' => ['description' => 'Quality and compliance certificates'],
                'Test Reports' => ['description' => 'Performance and testing documentation']
            ],
            'required_context' => [],
            'validation_rules' => [
                'max_size' => 50 * 1024 * 1024, // 50MB
                'allowed_extensions' => ['pdf', 'doc', 'docx', 'xlsx', 'csv', 'txt']
            ]
        ],
        'delivery_packet' => [
            'name' => 'Delivery Packet',
            'icon' => 'fas fa-box-open',
            'color' => '#f97316',
            'sub_types' => [
                'Complete Packets' => ['description' => 'Full delivery documentation sets'],
                'Partial Packets' => ['description' => 'Partial or supplementary documents']
            ],
            'required_context' => ['delivery_id'],
            'validation_rules' => [
                'max_size' => 100 * 1024 * 1024, // 100MB for large packets
                'allowed_extensions' => ['pdf', 'zip', 'doc', 'docx', 'xlsx']
            ]
        ],
        'incident_reports' => [
            'name' => 'Incident Reports',
            'icon' => 'fas fa-exclamation-triangle',
            'color' => '#ef4444',
            'sub_types' => [
                'Safety Reports' => ['description' => 'Safety incident documentation'],
                'Damage Reports' => ['description' => 'Equipment or product damage reports'],
                'Delay Reports' => ['description' => 'Schedule delay documentation']
            ],
            'required_context' => [],
            'validation_rules' => [
                'max_size' => 25 * 1024 * 1024, // 25MB
                'allowed_extensions' => ['pdf', 'doc', 'docx', 'jpg', 'png']
            ]
        ],
        'safe_harbor_evidence' => [
            'name' => 'Safe Harbor',
            'icon' => 'fas fa-gavel',
            'color' => '#6366f1',
            'sub_types' => [
                'Legal Documents' => ['description' => 'Legal compliance documentation'],
                'Compliance Certificates' => ['description' => 'Regulatory compliance certificates']
            ],
            'required_context' => [],
            'validation_rules' => [
                'max_size' => 50 * 1024 * 1024, // 50MB
                'allowed_extensions' => ['pdf', 'doc', 'docx']
            ]
        ],
        'flash_test_data' => [
            'name' => 'Flash Test Data',
            'icon' => 'fas fa-bolt',
            'color' => '#f59e0b',
            'sub_types' => [
                'Flash Test Results' => ['description' => 'Module performance test results'],
                'Quality Reports' => ['description' => 'Quality assurance documentation']
            ],
            'required_context' => [],
            'validation_rules' => [
                'max_size' => 100 * 1024 * 1024, // 100MB for data files
                'allowed_extensions' => ['pdf', 'xlsx', 'csv', 'txt', 'xml']
            ]
        ]
    ];
}

/**
 * Enhanced document sub-type determination with context awareness
 */
function determineEnhancedDocumentSubType($document_type, $context = [], $user_selection = null) {
    $config = getDocumentTypeConfig();
    
    // If user explicitly selected a sub-type, validate and use it
    if ($user_selection && isset($config[$document_type]['sub_types'][$user_selection])) {
        return $user_selection;
    }
    
    // Enhanced context-based determination
    switch ($document_type) {
        case 'pods':
            if (isset($context['delivery_status'])) {
                $status = strtolower($context['delivery_status']);
                if (strpos($status, 'warehouse') !== false) {
                    return 'Warehouse PODs';
                } elseif (strpos($status, 'project') !== false || strpos($status, 'site') !== false) {
                    return 'Project PODs';
                }
            }
            return 'Delivery PODs';
            
        case 'invoices':
            if (isset($context['vendor_name'])) {
                $vendor = strtolower($context['vendor_name']);
                if (strpos($vendor, 'solterra') !== false) {
                    return 'Solterra Invoices';
                } elseif (strpos($vendor, 'warehouse') !== false || strpos($vendor, 'storage') !== false) {
                    return 'Warehouse Invoices';
                } elseif (strpos($vendor, 'freight') !== false || strpos($vendor, 'shipping') !== false) {
                    return 'Freight Invoices';
                }
            }
            return 'OEM Invoices';
            
        case 'bills_of_lading':
            if (isset($context['shipment_direction'])) {
                switch (strtolower($context['shipment_direction'])) {
                    case 'inbound':
                    case 'incoming':
                        return 'Inbound BOL';
                    case 'outbound':
                    case 'outgoing':
                        return 'Outbound BOL';
                    case 'internal':
                    case 'intercompany':
                        return 'Intercompany BOL';
                }
            }
            return 'Inbound BOL'; // Default
            
        case 'warehousing':
            if (isset($context['document_purpose'])) {
                $purpose = strtolower($context['document_purpose']);
                if (strpos($purpose, 'storage') !== false) {
                    return 'Storage Receipts';
                } elseif (strpos($purpose, 'handling') !== false) {
                    return 'Handling Receipts';
                } elseif (strpos($purpose, 'inspection') !== false) {
                    return 'Inspection Reports';
                }
            }
            return 'Storage Receipts';
            
        default:
            // For other document types, return the first available sub-type or null
            if (isset($config[$document_type]['sub_types'])) {
                $sub_types = array_keys($config[$document_type]['sub_types']);
                return $sub_types[0] ?? null;
            }
            return null;
    }
}

/**
 * Upload document with enhanced validation and sub-type handling
 */
function uploadEnhancedDocument($conn, $document_data, $user_selected_sub_type = null) {
    $document_type = $document_data['document_type'];
    $config = getDocumentTypeConfig();
    
    if (!isset($config[$document_type])) {
        throw new Exception("Invalid document type: $document_type");
    }
    
    $type_config = $config[$document_type];
    
    // Validate file against type-specific rules
    if (isset($type_config['validation_rules'])) {
        $rules = $type_config['validation_rules'];
        
        // Check file size
        if (isset($rules['max_size']) && $document_data['file_size'] > $rules['max_size']) {
            $max_mb = round($rules['max_size'] / (1024 * 1024), 1);
            throw new Exception("File size exceeds maximum allowed for {$type_config['name']}: {$max_mb}MB");
        }
        
        // Check file extension
        if (isset($rules['allowed_extensions'])) {
            $file_ext = strtolower(pathinfo($document_data['original_name'], PATHINFO_EXTENSION));
            if (!in_array($file_ext, $rules['allowed_extensions'])) {
                $allowed = implode(', ', $rules['allowed_extensions']);
                throw new Exception("File type not allowed for {$type_config['name']}. Allowed: $allowed");
            }
        }
    }
    
    // Determine sub-type
    $context = $document_data['context'] ?? [];
    $sub_type = determineEnhancedDocumentSubType($document_type, $context, $user_selected_sub_type);
    
    // Update document data with determined sub-type
    $document_data['document_sub_type'] = $sub_type;
    
    // Use the standard upload function
    return saveDocumentToProjectDocuments($conn, $document_data);
}

/**
 * Get available sub-types for a document type
 */
function getAvailableSubTypes($document_type) {
    $config = getDocumentTypeConfig();
    return $config[$document_type]['sub_types'] ?? [];
}

/**
 * Validate required context for document type
 */
function validateDocumentContext($document_type, $context) {
    $config = getDocumentTypeConfig();
    
    if (!isset($config[$document_type])) {
        return ['valid' => false, 'message' => "Unknown document type: $document_type"];
    }
    
    $required_context = $config[$document_type]['required_context'] ?? [];
    $missing_context = [];
    
    foreach ($required_context as $required_field) {
        if (!isset($context[$required_field]) || empty($context[$required_field])) {
            $missing_context[] = $required_field;
        }
    }
    
    if (!empty($missing_context)) {
        return [
            'valid' => false, 
            'message' => "Missing required context for {$config[$document_type]['name']}: " . implode(', ', $missing_context)
        ];
    }
    
    return ['valid' => true, 'message' => 'Context validation passed'];
}
?>
