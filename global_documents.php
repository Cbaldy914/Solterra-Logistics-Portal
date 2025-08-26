<?php
session_name("logistics_session");
session_start();

// Check if the user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login");
    exit();
}

$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['role'];

// Database connection
require_once '../config.php';
$conn = getDBConnection();
if (!$conn) {
    die("Connection failed");
}

// Get user's accessible projects based on role
$accessible_projects = [];
if ($user_role === 'global_admin') {
    // Global admin can access all projects
    $stmt = $conn->prepare("SELECT id, project_name FROM projects ORDER BY project_name ASC");
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $accessible_projects[] = $row;
    }
    $stmt->close();
} else {
    // Admin and regular users can only access their account's projects
    $stmt = $conn->prepare("
        SELECT DISTINCT p.id, p.project_name 
        FROM projects p 
        JOIN customer_account_users cau ON p.account_id = cau.account_id 
        WHERE cau.user_id = ?
        ORDER BY p.project_name ASC
    ");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $accessible_projects[] = $row;
    }
    $stmt->close();
}

// Define document types and their sub-filters
$document_types = [
    'invoices' => [
        'name' => 'Invoices',
        'icon' => 'fas fa-file-invoice-dollar',
        'color' => '#22c55e',
        'sub_filters' => ['Solterra Invoices', 'OEM Invoices', 'Warehouse Invoices']
    ],
    'pods' => [
        'name' => 'PODs',
        'icon' => 'fas fa-clipboard-check',
        'color' => '#3b82f6',
        'sub_filters' => ['Warehouse POD', 'Project POD']
    ],
    'shipments' => [
        'name' => 'Shipments',
        'icon' => 'fas fa-shipping-fast',
        'color' => '#8b5cf6',
        'sub_filters' => ['Arrival Notice', 'Customs Document', 'Delivery SOP']
    ],
    'flash_test_data' => [
        'name' => 'Flash Test Data',
        'icon' => 'fas fa-bolt',
        'color' => '#f59e0b',
        'sub_filters' => []
    ],
    'bills_of_lading' => [
        'name' => 'Bills of Lading',
        'icon' => 'fas fa-shipping-fast',
        'color' => '#8b5cf6',
        'sub_filters' => []
    ],
    'warehousing' => [
        'name' => 'Warehousing',
        'icon' => 'fas fa-warehouse',
        'color' => '#06b6d4',
        'sub_filters' => []
    ],
    'modules' => [
        'name' => 'Modules',
        'icon' => 'fas fa-microchip',
        'color' => '#10b981',
        'sub_filters' => []
    ],
    'delivery_packet' => [
        'name' => 'Delivery Packet',
        'icon' => 'fas fa-box-open',
        'color' => '#f97316',
        'sub_filters' => []
    ],
    'incident_reports' => [
        'name' => 'Incident Reports',
        'icon' => 'fas fa-exclamation-triangle',
        'color' => '#ef4444',
        'sub_filters' => []
    ],
    'safe_harbor_evidence' => [
        'name' => 'Safe Harbor',
        'icon' => 'fas fa-gavel',
        'color' => '#6366f1',
        'sub_filters' => []
    ]
];

// Check if user can upload (admin or global_admin only)
$can_upload = in_array($user_role, ['admin', 'global_admin']);

// Check for upload parameters from project documents integration
$auto_open_upload = isset($_GET['upload']) && $_GET['upload'] === '1';
$pre_selected_project = isset($_GET['project_id']) ? (int)$_GET['project_id'] : 0;
$pre_selected_folder = isset($_GET['folder']) ? trim($_GET['folder']) : '';
$pre_selected_subfolder = isset($_GET['subfolder']) ? trim($_GET['subfolder']) : '';

// Map folder keys to document types and sub-types
$folder_mapping = [
    'invoices' => ['document_type' => 'invoices', 'subfolders' => ['freight_invoice' => 'Freight Invoice', 'solterra_invoice' => 'Solterra Invoice', 'module_invoice' => 'Module Invoice']],
    'pods' => ['document_type' => 'pods', 'subfolders' => ['project_pod' => 'Project POD', 'warehouse_pod' => 'Warehouse POD']],
    'shipments' => ['document_type' => 'shipments', 'subfolders' => ['arrival_notice' => 'Arrival Notice', 'customs_document' => 'Customs Document', 'delivery_sop' => 'Delivery SOP']],
    'warehousing' => ['document_type' => 'warehousing', 'subfolders' => ['warehouse_pod' => 'Warehouse POD', 'inventory_report' => 'Inventory Report', 'warehouse_photo' => 'Warehouse Photo']],
    'modules' => ['document_type' => 'modules', 'subfolders' => ['module_invoice' => 'Module Invoice', 'flash_test_data' => 'Flash Test Data', 'spec_sheet' => 'Spec Sheet']],
    'incident_reports' => ['document_type' => 'incident_reports', 'subfolders' => ['damage_photo' => 'Damage Photo', 'warranty_document' => 'Warranty Document', 'project_pod' => 'Project POD', 'warehouse_pod' => 'Warehouse POD']],
    'safe_harbor' => ['document_type' => 'other', 'subfolders' => ['module_invoice' => 'Module Invoice', 'project_pod' => 'Project POD', 'warehouse_pod' => 'Warehouse POD', 'arrival_notice' => 'Arrival Notice', 'customs_document' => 'Customs Document', 'inventory_report' => 'Inventory Report', 'warehouse_photo' => 'Warehouse Photo', 'flash_test_data' => 'Flash Test Data']]
];

$pre_selected_document_type = '';
$pre_selected_document_sub_type = '';

if (!empty($pre_selected_folder) && isset($folder_mapping[$pre_selected_folder])) {
    $pre_selected_document_type = $folder_mapping[$pre_selected_folder]['document_type'];
    
    if (!empty($pre_selected_subfolder) && isset($folder_mapping[$pre_selected_folder]['subfolders'][$pre_selected_subfolder])) {
        $pre_selected_document_sub_type = $folder_mapping[$pre_selected_folder]['subfolders'][$pre_selected_subfolder];
    }
}
$is_pods_context = ($pre_selected_folder === 'pods');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Global Documents - Solterra Logistics Portal</title>
    <link rel="stylesheet" href="portal.css">
    <link rel="icon" href="pictures/favicon.png" type="image/x-icon">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        .breadcrumb {
            display: flex;
            align-items: center;
            margin-bottom: 30px;
            margin-top: 10px;
            font-size: 0.95em;
            color: #6c757d;
        }
        .breadcrumb a {
            color: #488C9A;
            text-decoration: none;
            transition: color 0.3s ease;
            font-weight: 500;
        }
        .breadcrumb a:hover {
            color: #293E4C;
        }
        .breadcrumb .separator {
            margin: 0 12px;
            color: #d1d5db;
            font-weight: 300;
        }

        /* Header Section */
        .global-documents-header {
            background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%);
            border-radius: 24px;
            padding: 32px;
            margin-bottom: 40px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.06);
            border: 1px solid rgba(72, 140, 154, 0.08);
            position: relative;
            overflow: hidden;
        }

        .global-documents-header::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, #488C9A 0%, #293E4C 100%);
        }

        .header-content {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 24px;
        }

        .header-left {
            display: flex;
            align-items: center;
            gap: 24px;
        }

        .header-icon {
            width: 80px;
            height: 80px;
            background: linear-gradient(135deg, #488C9A 0%, #3A6E7F 100%);
            border-radius: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 32px;
            box-shadow: 0 12px 24px rgba(72, 140, 154, 0.3);
        }

        .header-info h1 {
            font-size: 2.5em;
            font-weight: 700;
            background: linear-gradient(135deg, #293E4C 0%, #488C9A 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            margin: 0 0 8px 0;
            line-height: 1.2;
        }

        .header-subtitle {
            color: #6c757d;
            font-size: 1.1em;
            font-weight: 500;
            margin: 0;
        }

        .header-stats {
            display: flex;
            gap: 24px;
            flex-wrap: wrap;
        }

        .stat-item {
            text-align: center;
            background: rgba(72, 140, 154, 0.08);
            padding: 16px 20px;
            border-radius: 16px;
            min-width: 120px;
        }

        .stat-number {
            font-size: 2em;
            font-weight: 700;
            color: #488C9A;
            margin: 0;
            line-height: 1;
        }

        .stat-label {
            font-size: 0.9em;
            color: #6c757d;
            margin: 4px 0 0 0;
            font-weight: 500;
        }

        /* Filter Section */
        .filter-section {
            background: linear-gradient(135deg, #ffffff 0%, #f8f9fa 100%);
            border-radius: 20px;
            padding: 32px;
            margin-bottom: 32px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.06);
            border: 1px solid rgba(72, 140, 154, 0.08);
        }

        .filter-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 24px;
            flex-wrap: wrap;
            gap: 16px;
        }

        .filter-title {
            font-size: 1.4em;
            font-weight: 600;
            color: #293E4C;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .filter-title i {
            color: #488C9A;
        }

        .filter-actions {
            display: flex;
            gap: 12px;
            align-items: center;
        }

        .clear-filters, .apply-filters, .bulk-download {
            padding: 10px 16px;
            border-radius: 10px;
            font-size: 0.9em;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
            border: none;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .clear-filters {
            background: linear-gradient(135deg, rgba(239, 68, 68, 0.1) 0%, rgba(220, 38, 38, 0.15) 100%);
            color: #dc2626;
            border: 1px solid rgba(239, 68, 68, 0.2);
        }

        .clear-filters:hover {
            background: linear-gradient(135deg, rgba(239, 68, 68, 0.15) 0%, rgba(220, 38, 38, 0.2) 100%);
        }

        .apply-filters {
            background: linear-gradient(135deg, #488C9A 0%, #3A6E7F 100%);
            color: white;
            box-shadow: 0 4px 15px rgba(72, 140, 154, 0.3);
        }

        .apply-filters:hover {
            background: linear-gradient(135deg, #3A6E7F 0%, #293E4C 100%);
            transform: translateY(-1px);
            box-shadow: 0 6px 20px rgba(72, 140, 154, 0.4);
        }

        .bulk-download {
            background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
            color: white;
            box-shadow: 0 4px 15px rgba(245, 158, 11, 0.3);
        }

        .bulk-download:hover {
            background: linear-gradient(135deg, #d97706 0%, #b45309 100%);
            transform: translateY(-1px);
            box-shadow: 0 6px 20px rgba(245, 158, 11, 0.4);
        }

        .bulk-download:disabled {
            opacity: 0.5;
            cursor: not-allowed;
            transform: none;
        }

                 .filter-grid {
             display: grid;
             grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
             gap: 24px;
             align-items: start; /* Align all filter groups to the top */
         }

         .filter-group {
             position: relative;
             display: flex;
             flex-direction: column;
             height: fit-content;
         }

        .filter-label {
            font-weight: 600;
            color: #293E4C;
            margin-bottom: 8px;
            display: block;
            font-size: 0.95em;
        }

                 .filter-select, .filter-input {
             width: 100%;
             padding: 12px 16px;
             border: 2px solid rgba(72, 140, 154, 0.15);
             border-radius: 12px;
             background: white;
             font-size: 0.95em;
             transition: all 0.3s ease;
             font-family: 'Poppins', sans-serif;
             box-sizing: border-box;
             height: 48px; /* Fixed height for consistent alignment */
         }

         .filter-select:focus, .filter-input:focus {
             outline: none;
             border-color: #488C9A;
             box-shadow: 0 4px 15px rgba(72, 140, 154, 0.2);
         }

        .sub-filter-group {
            margin-top: 12px;
            opacity: 0;
            max-height: 0;
            overflow: hidden;
            transition: all 0.3s ease;
        }

        .sub-filter-group.active {
            opacity: 1;
            max-height: 200px;
        }

        .sub-filter-options {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: 8px;
        }

        .sub-filter-option {
            padding: 6px 12px;
            border: 2px solid rgba(72, 140, 154, 0.2);
            border-radius: 20px;
            font-size: 0.85em;
            cursor: pointer;
            transition: all 0.3s ease;
            background: white;
            color: #6c757d;
        }

        .sub-filter-option.selected {
            background: linear-gradient(135deg, #488C9A 0%, #3A6E7F 100%);
            color: white;
            border-color: #488C9A;
        }

                 .date-range-group {
             display: grid;
             grid-template-columns: 1fr 1fr;
             gap: 12px;
             width: 100%;
         }

         .date-range-group .filter-input {
             margin: 0;
         }

        /* Document Table */
        .documents-container {
            background: linear-gradient(135deg, #ffffff 0%, #f8f9fa 100%);
            border-radius: 20px;
            overflow: hidden;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.06);
            border: 1px solid rgba(72, 140, 154, 0.08);
        }

        .table-header {
            background: linear-gradient(135deg, #488C9A 0%, #3A6E7F 100%);
            color: white;
            padding: 20px 24px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 16px;
        }

        .table-title {
            font-size: 1.3em;
            font-weight: 600;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 12px;
            color: white;
        }

        .results-info {
            font-size: 0.9em;
            opacity: 0.9;
        }

        .documents-table {
            width: 100%;
            border-collapse: collapse;
        }

        .documents-table th {
            background: #f8f9fa;
            padding: 16px;
            text-align: left;
            font-weight: 600;
            color: #293E4C;
            border-bottom: 2px solid rgba(72, 140, 154, 0.1);
            position: sticky;
            top: 0;
            z-index: 10;
        }

        .documents-table td {
            padding: 16px;
            border-bottom: 1px solid rgba(72, 140, 154, 0.08);
            vertical-align: middle;
        }

        .documents-table tbody tr {
            transition: all 0.3s ease;
            cursor: pointer;
        }

        .documents-table tbody tr:hover {
            background: rgba(72, 140, 154, 0.05);
            transform: translateX(4px);
        }

        .document-checkbox {
            width: 18px;
            height: 18px;
            cursor: pointer;
        }

        .document-icon {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 16px;
            margin-right: 12px;
        }

        .document-info {
            display: flex;
            align-items: center;
        }

        .document-details {
            flex: 1;
        }

        .document-name {
            font-weight: 600;
            color: #293E4C;
            margin-bottom: 4px;
            font-size: 0.95em;
        }

        .document-meta {
            font-size: 0.8em;
            color: #6c757d;
        }

        .document-type-badge {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.8em;
            font-weight: 500;
            background: rgba(72, 140, 154, 0.1);
            color: #488C9A;
        }

        .project-link {
            color: #488C9A;
            text-decoration: none;
            font-weight: 500;
            transition: color 0.3s ease;
        }

        .project-link:hover {
            color: #293E4C;
        }

                 /* Override portal.css action-buttons styling for this page */
         .global-documents-page .action-buttons {
             display: flex !important;
             gap: 8px !important;
             align-items: center !important;
             margin: 0 !important;
             padding: 0 !important;
             background: none !important;
             border: none !important;
             box-shadow: none !important;
         }

         .global-documents-page .btn-download, 
         .global-documents-page .btn-view {
             padding: 6px 12px !important;
             border-radius: 8px !important;
             font-size: 0.8em !important;
             font-weight: 500 !important;
             text-decoration: none !important;
             transition: all 0.3s ease !important;
             display: flex !important;
             align-items: center !important;
             gap: 6px !important;
             border: none !important;
             box-shadow: none !important;
             margin: 0 !important;
             min-width: auto !important;
             width: auto !important;
         }

         .global-documents-page .btn-download {
             background: linear-gradient(135deg, #22c55e 0%, #16a34a 100%) !important;
             color: white !important;
         }

         .global-documents-page .btn-download:hover {
             background: linear-gradient(135deg, #16a34a 0%, #15803d 100%) !important;
             transform: translateY(-1px) !important;
             color: white !important;
         }

         .global-documents-page .btn-view {
             background: linear-gradient(135deg, rgba(72, 140, 154, 0.1) 0%, rgba(58, 110, 127, 0.15) 100%) !important;
             color: #488C9A !important;
             border: 1px solid rgba(72, 140, 154, 0.2) !important;
         }

         .global-documents-page .btn-view:hover {
             background: linear-gradient(135deg, rgba(72, 140, 154, 0.15) 0%, rgba(58, 110, 127, 0.2) 100%) !important;
             color: #488C9A !important;
         }

        /* Pagination */
        .pagination-container {
            padding: 24px;
            background: #f8f9fa;
            display: flex;
            align-items: center;
            justify-content: between;
            flex-wrap: wrap;
            gap: 16px;
        }

        .pagination-info {
            flex: 1;
            color: #6c757d;
            font-size: 0.9em;
        }

        .pagination-controls {
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .page-size-selector {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .page-size-select {
            padding: 6px 12px;
            border: 1px solid rgba(72, 140, 154, 0.2);
            border-radius: 8px;
            font-size: 0.9em;
        }

        .pagination-buttons {
            display: flex;
            gap: 8px;
        }

        .pagination-btn {
            padding: 8px 12px;
            border: 1px solid rgba(72, 140, 154, 0.2);
            border-radius: 8px;
            background: white;
            color: #488C9A;
            cursor: pointer;
            transition: all 0.3s ease;
            font-size: 0.9em;
        }

        .pagination-btn:hover:not(:disabled) {
            background: #488C9A;
            color: white;
        }

        .pagination-btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        .pagination-btn.active {
            background: #488C9A;
            color: white;
        }

        /* Loading and Empty States */
        .loading-state, .empty-state {
            text-align: center;
            padding: 60px 20px;
        }

        .loading-state i, .empty-state i {
            font-size: 3em;
            color: #d1d5db;
            margin-bottom: 16px;
        }

        .loading-state h3, .empty-state h3 {
            font-size: 1.3em;
            color: #6c757d;
            margin-bottom: 8px;
            font-weight: 600;
        }

        .loading-state p, .empty-state p {
            color: #9ca3af;
            margin: 0;
        }

        /* Responsive Design */
        @media (max-width: 768px) {
            .header-content {
                flex-direction: column;
                align-items: flex-start;
            }

            .filter-grid {
                grid-template-columns: 1fr;
            }

            .date-range-group {
                grid-template-columns: 1fr;
            }

            .documents-table {
                font-size: 0.9em;
            }

            .documents-table th,
            .documents-table td {
                padding: 12px 8px;
            }

            .pagination-container {
                flex-direction: column;
                align-items: stretch;
            }

            .pagination-controls {
                justify-content: center;
            }
        }

        /* Animations */
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .documents-table tbody tr {
            animation: fadeInUp 0.3s ease forwards;
        }

        /* Upload Documents Button */
        .upload-documents {
            background: linear-gradient(135deg, #22c55e 0%, #16a34a 100%);
            color: white;
            border: none;
            padding: 12px 20px;
            border-radius: 12px;
            font-weight: 600;
            font-size: 0.95em;
            display: flex;
            align-items: center;
            gap: 8px;
            cursor: pointer;
            transition: all 0.3s ease;
            box-shadow: 0 4px 12px rgba(34, 197, 94, 0.3);
        }

        .upload-documents:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(34, 197, 94, 0.4);
        }

        /* Upload Modal */
        .upload-modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(5px);
        }

        .modal-content {
            background-color: #fff;
            margin: 2% auto;
            padding: 0;
            border: none;
            width: 90%;
            max-width: 800px;
            border-radius: 24px;
            box-shadow: 0 25px 50px rgba(0, 0, 0, 0.15);
            max-height: 90vh;
            overflow-y: auto;
        }

        .modal-header {
            background: linear-gradient(135deg, #488C9A 0%, #3A6E7F 100%);
            color: white;
            padding: 24px 32px;
            border-radius: 24px 24px 0 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-title {
            font-size: 1.5em;
            font-weight: 600;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 12px;
            color: white;
        }

        .close-modal {
            background: none;
            border: none;
            color: white;
            font-size: 24px;
            cursor: pointer;
            padding: 8px;
            border-radius: 8px;
            transition: background-color 0.3s ease;
        }

        .close-modal:hover {
            background-color: rgba(255, 255, 255, 0.1);
        }

        .modal-body {
            padding: 32px;
        }

        .upload-step {
            margin-bottom: 32px;
        }

        .step-header {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 16px;
        }

        .step-number {
            background: linear-gradient(135deg, #22c55e 0%, #16a34a 100%);
            color: white;
            width: 32px;
            height: 32px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 0.95em;
        }

        .step-title {
            font-size: 1.2em;
            font-weight: 600;
            color: #293E4C;
            margin: 0;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-label {
            display: block;
            font-weight: 500;
            color: #374151;
            margin-bottom: 8px;
            font-size: 0.95em;
        }

        .form-select, .form-input {
            width: 100%;
            padding: 12px 16px;
            border: 2px solid #e5e7eb;
            border-radius: 12px;
            font-size: 0.95em;
            transition: all 0.3s ease;
            background-color: #fff;
            resize: vertical;
        }

        .form-select:focus, .form-input:focus {
            outline: none;
            border-color: #488C9A;
            box-shadow: 0 0 0 3px rgba(72, 140, 154, 0.1);
        }

        .bol-autocomplete-container {
            position: relative;
        }

        .bol-suggestions {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            background: white;
            border: 2px solid #e5e7eb;
            border-top: none;
            border-radius: 0 0 12px 12px;
            max-height: 200px;
            overflow-y: auto;
            z-index: 1000;
            display: none;
        }

        .bol-suggestion {
            padding: 12px 16px;
            cursor: pointer;
            border-bottom: 1px solid #f3f4f6;
            transition: background-color 0.2s ease;
        }

        .bol-suggestion:hover {
            background-color: #f8f9fa;
        }

        .bol-suggestion.highlighted {
            background-color: #e5f3f4;
        }

        .bol-suggestion:last-child {
            border-bottom: none;
        }

        .bol-suggestion-main {
            font-weight: 500;
            color: #374151;
        }

        .bol-suggestion-details {
            font-size: 0.85em;
            color: #6b7280;
            margin-top: 2px;
        }

        .bol-validation {
            margin-top: 6px;
            font-size: 0.85em;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .bol-validation.valid {
            color: #059669;
        }

        .bol-validation.invalid {
            color: #dc2626;
        }

        .bol-validation.searching {
            color: #6b7280;
        }

        .form-checkbox {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-top: 16px;
        }

        .form-checkbox input[type="checkbox"] {
            width: 18px;
            height: 18px;
            accent-color: #488C9A;
        }

        .dynamic-fields {
            background: #f8f9fa;
            border-radius: 12px;
            padding: 20px;
            margin-top: 16px;
            border: 1px solid #e5e7eb;
        }

        .file-upload-area {
            border: 2px dashed #d1d5db;
            border-radius: 12px;
            padding: 32px;
            text-align: center;
            transition: all 0.3s ease;
            cursor: pointer;
        }

        .file-upload-area:hover {
            border-color: #488C9A;
            background-color: #f8f9fa;
        }

        .file-upload-area.dragover {
            border-color: #22c55e;
            background-color: #f0f9ff;
        }

        .upload-icon {
            font-size: 3em;
            color: #9ca3af;
            margin-bottom: 16px;
        }

        .upload-text {
            font-size: 1.1em;
            color: #6b7280;
            margin-bottom: 8px;
        }

        .upload-subtext {
            font-size: 0.9em;
            color: #9ca3af;
        }

        .file-list {
            margin-top: 20px;
        }

        .file-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 12px 16px;
            background: #f8f9fa;
            border-radius: 8px;
            margin-bottom: 8px;
        }

        .file-info {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .file-icon {
            color: #488C9A;
        }

        .remove-file {
            background: #ef4444;
            color: white;
            border: none;
            padding: 6px 12px;
            border-radius: 6px;
            font-size: 0.8em;
            cursor: pointer;
        }

        .modal-footer {
            padding: 24px 32px;
            background: #f8f9fa;
            border-radius: 0 0 24px 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .upload-progress {
            display: none;
            flex: 1;
            margin-right: 20px;
        }

        .progress-bar {
            width: 100%;
            height: 8px;
            background: #e5e7eb;
            border-radius: 4px;
            overflow: hidden;
        }

        .progress-fill {
            height: 100%;
            background: linear-gradient(135deg, #22c55e 0%, #16a34a 100%);
            width: 0%;
            transition: width 0.3s ease;
        }

        .modal-buttons {
            display: flex;
            gap: 12px;
        }

        .btn-cancel {
            background: #6b7280;
            color: white;
            border: none;
            padding: 12px 24px;
            border-radius: 12px;
            font-weight: 500;
            cursor: pointer;
            transition: background-color 0.3s ease;
        }

        .btn-cancel:hover {
            background: #4b5563;
        }

        .btn-upload {
            background: linear-gradient(135deg, #22c55e 0%, #16a34a 100%);
            color: white;
            border: none;
            padding: 12px 24px;
            border-radius: 12px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .btn-upload:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(34, 197, 94, 0.3);
        }

        .btn-upload:disabled {
            background: #9ca3af;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }
    </style>
 </head>
 <body class="global-documents-page">
 <?php include 'header.php'; ?>
 <main>
    <div class="breadcrumb">
        <a href="dashboard.php">Dashboard</a>
        <span class="separator">&raquo;</span>
        <a href="documents.php">Documents</a>
        <span class="separator">&raquo;</span>
        <span>Global Documents</span>
    </div>

    <div class="global-documents-header">
        <div class="header-content">
            <div class="header-left">
                <div class="header-icon">
                    <i class="fas fa-globe"></i>
                </div>
                <div class="header-info">
                    <h1>Global Documents</h1>
                    <p class="header-subtitle">Unified document management across all your projects</p>
                </div>
            </div>
            <div class="header-stats">
                <div class="stat-item">
                    <p class="stat-number" id="totalProjects"><?php echo count($accessible_projects); ?></p>
                    <p class="stat-label">Projects</p>
                </div>
                <div class="stat-item">
                    <p class="stat-number" id="totalDocuments">0</p>
                    <p class="stat-label">Documents</p>
                </div>
                <div class="stat-item">
                    <p class="stat-number" id="selectedDocuments">0</p>
                    <p class="stat-label">Selected</p>
                </div>
            </div>
        </div>
    </div>

    <div class="filter-section">
        <div class="filter-header">
            <h2 class="filter-title">
                <i class="fas fa-filter"></i>
                Advanced Filters
            </h2>
            <div class="filter-actions">
                <button type="button" class="clear-filters" onclick="clearAllFilters()">
                    <i class="fas fa-times"></i>
                    Clear All
                </button>
                <button type="button" class="apply-filters" onclick="applyFilters()">
                    <i class="fas fa-search"></i>
                    Apply Filters
                </button>
                <?php if ($can_upload): ?>
                <button type="button" class="upload-documents" onclick="openUploadModal()">
                    <i class="fas fa-upload"></i>
                    Upload Documents
                </button>
                <?php endif; ?>
                <button type="button" class="bulk-download" id="bulkDownload" onclick="downloadSelected()" disabled>
                    <i class="fas fa-download"></i>
                    Download Selected
                </button>
            </div>
        </div>

        <div class="filter-grid">
            <div class="filter-group">
                <label class="filter-label" for="projectFilter">Project</label>
                <select id="projectFilter" class="filter-select">
                    <option value="">All Projects</option>
                    <?php foreach ($accessible_projects as $project): ?>
                        <option value="<?php echo $project['id']; ?>"><?php echo htmlspecialchars($project['project_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="filter-group">
                <label class="filter-label" for="documentTypeFilter">Document Type</label>
                <select id="documentTypeFilter" class="filter-select" onchange="toggleSubFilters()">
                    <option value="">All Document Types</option>
                    <?php foreach ($document_types as $type => $info): ?>
                        <option value="<?php echo $type; ?>"><?php echo htmlspecialchars($info['name']); ?></option>
                    <?php endforeach; ?>
                </select>
                
                <!-- Sub-filters for specific document types -->
                <div class="sub-filter-group" id="subFilterGroup">
                    <label class="filter-label">Sub-category</label>
                    <div class="sub-filter-options" id="subFilterOptions">
                        <!-- Will be populated dynamically -->
                    </div>
                </div>
            </div>

            <div class="filter-group">
                <label class="filter-label">Upload Date Range</label>
                <div class="date-range-group">
                    <input type="date" id="startDate" class="filter-input" placeholder="Start Date">
                    <input type="date" id="endDate" class="filter-input" placeholder="End Date">
                </div>
            </div>

            <div class="filter-group">
                <label class="filter-label" for="searchFilter">Search Documents</label>
                <input type="text" id="searchFilter" class="filter-input" placeholder="Search by filename, description...">
            </div>
        </div>
    </div>

    <!-- Context subfilters: appear below advanced filters when Doc Type selected -->
    <div id="contextSubfilters" style="display: none; margin-bottom: 16px;">
        <div class="filter-section" style="padding: 16px; margin-bottom: 0;">
            <div id="podsSubfilters" style="display: none;">
                <div class="filter-grid">
                    <div class="filter-group">
                        <label class="filter-label">Delivery Date (Actual)</label>
                        <div class="date-range-group">
                            <input type="date" id="podsDeliveryStart" class="filter-input" placeholder="Start Date">
                            <input type="date" id="podsDeliveryEnd" class="filter-input" placeholder="End Date">
                        </div>
                    </div>
                    <div class="filter-group">
                        <label class="filter-label" for="podsBolNumber">BOL Number</label>
                        <input type="text" id="podsBolNumber" class="filter-input" placeholder="e.g., 8086343">
                    </div>
                    <div class="filter-group">
                        <label class="filter-label" for="podsManufacturerSelect">Manufacturer</label>
                        <select id="podsManufacturerSelect" class="filter-select">
                            <option value="">All Manufacturers</option>
                        </select>
                    </div>
                    <div class="filter-group" style="position: relative;">
                        <label class="filter-label" for="podsWattageDisplay">Wattage</label>
                        <div>
                            <input type="text" id="podsWattageDisplay" class="filter-input" readonly placeholder="Select wattages" onclick="toggleWattageMenu(event)">
                            <div id="podsWattageMenu" class="checkbox-menu" style="display:none; position: fixed; background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; padding: 8px; z-index: 10000; max-height: 240px; overflow-y: auto; min-width: 220px; box-shadow: 0 10px 30px rgba(0,0,0,0.12);"></div>
                        </div>
                    </div>
                </div>
            </div>
            <div id="invoicesSubfilters" style="display: none;">
                <div class="filter-grid">
                    <div class="filter-group">
                        <label class="filter-label">Invoice Total ($)</label>
                        <div class="date-range-group">
                            <input type="number" id="invTotalMin" class="filter-input" placeholder="Min" step="0.01" min="0">
                            <input type="number" id="invTotalMax" class="filter-input" placeholder="Max" step="0.01" min="0">
                        </div>
                    </div>
                    <div class="filter-group">
                        <label class="filter-label">Invoice Date</label>
                        <div class="date-range-group">
                            <input type="date" id="invDateStart" class="filter-input" placeholder="Start Date">
                            <input type="date" id="invDateEnd" class="filter-input" placeholder="End Date">
                        </div>
                    </div>
                    <div class="filter-group">
                        <label class="filter-label">Due Date</label>
                        <div class="date-range-group">
                            <input type="date" id="invDueStart" class="filter-input" placeholder="Start Date">
                            <input type="date" id="invDueEnd" class="filter-input" placeholder="End Date">
                        </div>
                    </div>
                    <div class="filter-group" id="invManufacturerGroup" style="display: none;">
                        <label class="filter-label" for="invManufacturerSelect">Manufacturer</label>
                        <select id="invManufacturerSelect" class="filter-select">
                            <option value="">All Manufacturers</option>
                        </select>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="documents-container">
        <div class="table-header">
            <h3 class="table-title">
                <i class="fas fa-table"></i>
                Document Results
            </h3>
            <div class="results-info" id="resultsInfo">
                Loading documents...
            </div>
        </div>

        <div id="documentsTableContainer">
            <div class="loading-state">
                <i class="fas fa-spinner fa-spin"></i>
                <h3>Loading Documents</h3>
                <p>Please wait while we fetch your documents...</p>
            </div>
        </div>

        <div class="pagination-container">
            <div class="pagination-info" id="paginationInfo">
                Showing 0 of 0 documents
            </div>
            <div class="pagination-controls">
                <div class="page-size-selector">
                    <label>Show:</label>
                    <select id="pageSizeSelect" class="page-size-select" onchange="changePageSize()">
                        <option value="25">25</option>
                        <option value="50">50</option>
                        <option value="100" selected>100</option>
                        <option value="200">200</option>
                        <option value="500">500</option>
                    </select>
                    <span>per page</span>
                </div>
                <div class="pagination-buttons" id="paginationButtons">
                    <!-- Will be populated dynamically -->
                </div>
            </div>
        </div>
    </div>

    <!-- Upload Documents Modal -->
    <div id="uploadModal" class="upload-modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title">
                    <i class="fas fa-upload"></i>
                    Upload Documents
                </h2>
                <button class="close-modal" onclick="closeUploadModal()">&times;</button>
            </div>
            
            <div class="modal-body">
                <form id="uploadForm" enctype="multipart/form-data">
                    <!-- Step 1: Project Selection -->
                    <div class="upload-step">
                        <div class="step-header">
                            <div class="step-number">1</div>
                            <h3 class="step-title">Select Project</h3>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="uploadProjectSelect">Project *</label>
                            <select id="uploadProjectSelect" name="project_id" class="form-select" required>
                                <option value="">Choose a project...</option>
                                <?php foreach ($accessible_projects as $project): ?>
                                    <option value="<?php echo $project['id']; ?>"><?php echo htmlspecialchars($project['project_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <!-- Step 2: Document Type Selection -->
                    <div class="upload-step">
                        <div class="step-header">
                            <div class="step-number">2</div>
                            <h3 class="step-title">Document Type & Sub-Type</h3>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="uploadDocumentType">Document Type *</label>
                            <select id="uploadDocumentType" name="document_type" class="form-select" required onchange="updateSubTypes()">
                                <option value="">Choose document type...</option>
                                <option value="invoices">Invoices</option>
                                <option value="pods">PODs</option>
                                <option value="shipments">Shipments</option>
                                <option value="warehousing">Warehousing</option>
                                <option value="modules">Modules</option>
                                <option value="incident_reports">Incident Reports</option>
                                <option value="other">Other</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="uploadDocumentSubType">Sub-Type *</label>
                            <select id="uploadDocumentSubType" name="document_sub_type" class="form-select" required onchange="updateDynamicFields()">
                                <option value="">Choose sub-type...</option>
                            </select>
                        </div>
                    </div>

                    <!-- Step 3: Dynamic Fields Based on Selection -->
                    <div class="upload-step">
                        <div class="step-header">
                            <div class="step-number">3</div>
                            <h3 class="step-title">Document Details</h3>
                        </div>
                        <div id="dynamicFields" class="dynamic-fields" style="display: none;">
                            <!-- Will be populated dynamically -->
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="documentDescription">Description (Optional)</label>
                            <textarea id="documentDescription" name="description" class="form-input" rows="3" placeholder="Enter a description for this document..."></textarea>
                        </div>
                        <div class="form-checkbox">
                            <input type="checkbox" id="isSafeHarbor" name="is_safe_harbor" value="1">
                            <label for="isSafeHarbor" class="form-label">This is a Safe Harbor document</label>
                        </div>
                    </div>

                    <!-- Step 4: File Upload -->
                    <div class="upload-step">
                        <div class="step-header">
                            <div class="step-number">4</div>
                            <h3 class="step-title">Upload Files</h3>
                        </div>
                        <div class="file-upload-area" onclick="document.getElementById('fileInput').click()">
                            <i class="fas fa-cloud-upload-alt upload-icon"></i>
                            <div class="upload-text">Drop files here or click to browse</div>
                            <div class="upload-subtext">Supports: PDF, DOC, DOCX, XLS, XLSX, JPG, PNG (Max: 50MB each)</div>
                        </div>
                        <input type="file" id="fileInput" name="files[]" multiple style="display: none;" accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png,.gif,.txt,.csv" onchange="handleFileSelection(event)">
                        <div id="fileList" class="file-list"></div>
                    </div>
                </form>
            </div>

            <div class="modal-footer">
                <div class="upload-progress" id="uploadProgress" style="display: none;">
                    <div class="progress-bar">
                        <div class="progress-fill" id="progressFill"></div>
                    </div>
                    <div id="progressText">Uploading...</div>
                </div>
                <div class="modal-buttons">
                    <button type="button" class="btn-cancel" onclick="closeUploadModal()">Cancel</button>
                    <button type="button" class="btn-upload" id="uploadBtn" onclick="uploadDocuments()" disabled>
                        <i class="fas fa-upload"></i>
                        Upload Documents
                    </button>
                </div>
            </div>
        </div>
    </div>
</main>

<script>
// Global variables
let currentPage = 1;
let pageSize = 100;
let totalDocuments = 0;
let selectedDocuments = new Set();
let allDocuments = [];
let filtersApplied = false; // Show context subfilters/extra columns only after Apply

 // Document type sub-filters
 const subFilters = {
     'invoices': ['Solterra Invoice', 'Module Invoice'],
     'pods': ['Warehouse POD', 'Project POD'],
     'shipments': ['Arrival Notice', 'Customs Document', 'Delivery SOP'],
     'bills_of_lading': ['Inbound BOL', 'Outbound BOL', 'Intercompany BOL'],
     'warehousing': ['Warehouse POD', 'Inventory Report', 'Warehouse Photo'],
     'modules': ['Module Invoice', 'Flash Test Data', 'Data/Spec Sheet'],
     'delivery_packet': ['Complete Packets', 'Partial Packets'],
     'incident_reports': ['Damage Photo', 'Warranty Document', 'Project POD', 'Warehouse POD'],
     'safe_harbor_evidence': ['Legal Documents', 'Compliance Certificates'],
     'flash_test_data': ['Flash Test Results', 'Quality Reports']
 };

// Initialize page
document.addEventListener('DOMContentLoaded', function() {
    // Preselect filters from query params (project, folder, subfolder)
    <?php if ($pre_selected_project > 0): ?>
      document.getElementById('projectFilter').value = '<?php echo $pre_selected_project; ?>';
    <?php endif; ?>
    <?php if (!empty($pre_selected_document_type)): ?>
      document.getElementById('documentTypeFilter').value = '<?php echo $pre_selected_document_type; ?>';
      toggleSubFilters();
      // Ensure context subfilters match preselected type
      const docTypeSelectInit = document.getElementById('documentTypeFilter');
      if (docTypeSelectInit) {
        const ctx = document.getElementById('contextSubfilters');
        const pods = document.getElementById('podsSubfilters');
        if (docTypeSelectInit.value === 'pods') {
            ctx.style.display = '';
            pods.style.display = '';
        }
      }
      <?php if (!empty($pre_selected_document_sub_type)): ?>
        // Select the matching sub-filter chip if present
        Array.from(document.querySelectorAll('#subFilterOptions .sub-filter-option')).forEach(el => {
          if (el.textContent.trim() === '<?php echo $pre_selected_document_sub_type; ?>') {
            el.classList.add('selected');
          }
        });
      <?php endif; ?>
    <?php endif; ?>

    // Show/hide context subfilters based on document type (only after Apply)
    const docTypeSelect = document.getElementById('documentTypeFilter');
    function toggleContextSubfilters() {
        const type = docTypeSelect.value;
        const ctx = document.getElementById('contextSubfilters');
        const pods = document.getElementById('podsSubfilters');
        const invoices = document.getElementById('invoicesSubfilters');
        if (filtersApplied && type === 'pods') {
            ctx.style.display = '';
            pods.style.display = '';
            invoices.style.display = 'none';
            loadPodsFilterOptions();
        } else if (filtersApplied && type === 'invoices') {
            ctx.style.display = '';
            pods.style.display = 'none';
            invoices.style.display = '';
            updateInvoicesSubfilterVisibility();
        } else {
            pods.style.display = 'none';
            invoices.style.display = 'none';
            ctx.style.display = 'none';
        }
    }
    docTypeSelect.addEventListener('change', toggleContextSubfilters);

    // Initial state if coming from folder context
    <?php if ($pre_selected_document_type === 'pods' || $is_pods_context): ?>
        // Do not auto-show subfilters until Apply is used
    <?php endif; ?>

    loadDocuments();
    attachSubfilterListeners();
    
    // Set up real-time search
    let searchTimeout;
    document.getElementById('searchFilter').addEventListener('input', function() {
        clearTimeout(searchTimeout);
        searchTimeout = setTimeout(() => {
            currentPage = 1;
            loadDocuments();
        }, 500);
    });
});

async function loadPodsFilterOptions() {
    const projectId = document.getElementById('projectFilter').value || '';
    try {
        // Manufacturers (fallback load; will be refined from docs after load)
        const mRes = await fetch(`get_account_manufacturers.php${projectId ? `?project_id=${projectId}` : ''}`);
        const mData = await mRes.json();
        const sel = document.getElementById('podsManufacturerSelect');
        if (sel && mData.success) {
            const current = sel.value;
            sel.innerHTML = '<option value="">All Manufacturers</option>';
            mData.data.forEach(name => {
                const opt = document.createElement('option');
                opt.value = name;
                opt.textContent = name;
                sel.appendChild(opt);
            });
            if (current) sel.value = current;
        }

        // Wattages are derived from current documents after load
    } catch (e) {
        console.warn('Failed to load PODs filter options', e);
    }
}

function updatePodsWattagesFromDocs(documents) {
    const menu = document.getElementById('podsWattageMenu');
    const display = document.getElementById('podsWattageDisplay');
    if (!menu || !display) return;
    const previously = Array.from(menu.querySelectorAll('input[type="checkbox"]:checked')).map(cb => cb.value);
    const set = new Set();
    documents.forEach(d => { if (d.delivery_wattage) set.add(String(d.delivery_wattage)); });
    const sorted = Array.from(set).map(v => parseInt(v, 10)).sort((a,b)=>a-b).map(n => String(n));
    menu.innerHTML = '';
    sorted.forEach(val => {
        const id = `watt_${val}`;
        const wrap = document.createElement('label');
        wrap.style.display = 'flex';
        wrap.style.alignItems = 'center';
        wrap.style.gap = '8px';
        wrap.style.padding = '4px 2px';
        const cb = document.createElement('input');
        cb.type = 'checkbox';
        cb.value = val;
        cb.id = id;
        if (previously.includes(val)) cb.checked = true;
        cb.addEventListener('change', () => {
            updateWattageDisplay();
            if (filtersApplied) loadDocuments();
        });
        const span = document.createElement('span');
        span.textContent = val;
        wrap.appendChild(cb);
        wrap.appendChild(span);
        menu.appendChild(wrap);
    });
    updateWattageDisplay();
}

function updateWattageDisplay() {
    const display = document.getElementById('podsWattageDisplay');
    const menu = document.getElementById('podsWattageMenu');
    if (!display || !menu) return;
    const selected = Array.from(menu.querySelectorAll('input[type="checkbox"]:checked')).map(cb => cb.value);
    display.value = selected.length ? selected.join(', ') : '';
}

function toggleWattageMenu(e) {
    e.stopPropagation();
    const menu = document.getElementById('podsWattageMenu');
    if (!menu) return;
    if (menu.style.display === 'none') {
        // position under the input
        const input = document.getElementById('podsWattageDisplay');
        const rect = input.getBoundingClientRect();
        const menuWidth = 260;
        const left = Math.min(rect.left, window.innerWidth - menuWidth - 12);
        menu.style.left = left + 'px';
        menu.style.top = (rect.bottom + window.scrollY) + 'px';
        menu.style.display = 'block';
        document.addEventListener('click', closeWattMenuOnOutside);
    } else {
        menu.style.display = 'none';
        document.removeEventListener('click', closeWattMenuOnOutside);
    }
}

function closeWattMenuOnOutside(e) {
    const menu = document.getElementById('podsWattageMenu');
    const input = document.getElementById('podsWattageDisplay');
    if (!menu || !input) return;
    if (!menu.contains(e.target) && e.target !== input) {
        menu.style.display = 'none';
        document.removeEventListener('click', closeWattMenuOnOutside);
    }
}

function attachSubfilterListeners() {
    const podsStart = document.getElementById('podsDeliveryStart');
    const podsEnd = document.getElementById('podsDeliveryEnd');
    const podsBol = document.getElementById('podsBolNumber');
    const podsMan = document.getElementById('podsManufacturerSelect');
    const invMin = document.getElementById('invTotalMin');
    const invMax = document.getElementById('invTotalMax');
    const invStart = document.getElementById('invDateStart');
    const invEnd = document.getElementById('invDateEnd');
    const invBol = document.getElementById('invBolNumber');
    const invMan = document.getElementById('invManufacturerSelect');
    [podsStart, podsEnd, podsBol, podsMan, invMin, invMax, invStart, invEnd, invBol, invMan].forEach(el => {
        if (!el) return;
        const evt = (el.tagName === 'SELECT') ? 'change' : 'input';
        el.addEventListener(evt, () => { if (filtersApplied) { currentPage = 1; loadDocuments(); } });
    });
}

function updateInvoicesSubfilterVisibility() {
    const chips = Array.from(document.querySelectorAll('#subFilterOptions .sub-filter-option.selected')).map(el => el.textContent.trim());
    const showBol = chips.includes('Solterra Invoice');
    const showMan = chips.includes('Module Invoice');
    const bolGroup = document.getElementById('invBolGroup');
    const manGroup = document.getElementById('invManufacturerGroup');
    if (bolGroup) bolGroup.style.display = showBol ? '' : 'none';
    if (manGroup) manGroup.style.display = showMan ? '' : 'none';
}

function updateInvoicesManufacturersFromDocs(documents) {
    const sel = document.getElementById('invManufacturerSelect');
    if (!sel) return;
    const current = sel.value;
    const names = new Set();
    documents.forEach(d => {
        if (d.document_sub_type === 'Module Invoice' && d.manufacturer_name) {
            names.add(String(d.manufacturer_name));
        }
    });
    sel.innerHTML = '<option value="">All Manufacturers</option>';
    Array.from(names).sort((a,b)=>a.localeCompare(b)).forEach(name => {
        const opt = document.createElement('option');
        opt.value = name;
        opt.textContent = name;
        sel.appendChild(opt);
    });
    if (current && Array.from(names).includes(current)) sel.value = current;
}

// Toggle sub-filters based on document type selection
function toggleSubFilters() {
    const documentType = document.getElementById('documentTypeFilter').value;
    const subFilterGroup = document.getElementById('subFilterGroup');
    const subFilterOptions = document.getElementById('subFilterOptions');
    
    if (documentType && subFilters[documentType]) {
        // Clear existing sub-filters
        subFilterOptions.innerHTML = '';
        
        // Add sub-filter options
        subFilters[documentType].forEach(filter => {
            const option = document.createElement('div');
            option.className = 'sub-filter-option';
            option.textContent = filter;
            option.onclick = () => toggleSubFilter(option);
            subFilterOptions.appendChild(option);
        });
        
        subFilterGroup.classList.add('active');
    } else {
        subFilterGroup.classList.remove('active');
    }
}

// Toggle individual sub-filter selection
function toggleSubFilter(element) {
    element.classList.toggle('selected');
    if (filtersApplied && document.getElementById('documentTypeFilter').value === 'invoices') {
        updateInvoicesSubfilterVisibility();
    }
}

// Clear all filters
function clearAllFilters() {
    document.getElementById('projectFilter').value = '';
    document.getElementById('documentTypeFilter').value = '';
    document.getElementById('startDate').value = '';
    document.getElementById('endDate').value = '';
    document.getElementById('searchFilter').value = '';
    
    // Clear sub-filters
    document.getElementById('subFilterGroup').classList.remove('active');
    document.querySelectorAll('.sub-filter-option').forEach(el => el.classList.remove('selected'));

    // Clear context subfilters and hide
    const ctx = document.getElementById('contextSubfilters');
    const pods = document.getElementById('podsSubfilters');
    if (document.getElementById('podsDeliveryStart')) document.getElementById('podsDeliveryStart').value = '';
    if (document.getElementById('podsDeliveryEnd')) document.getElementById('podsDeliveryEnd').value = '';
    if (document.getElementById('podsBolNumber')) document.getElementById('podsBolNumber').value = '';
    const podsManSel = document.getElementById('podsManufacturerSelect');
    if (podsManSel) podsManSel.value = '';
    const podsWattSel = document.getElementById('podsWattageSelect');
    if (podsWattSel) Array.from(podsWattSel.options).forEach(o => o.selected = false);
    if (pods) pods.style.display = 'none';
    if (ctx) ctx.style.display = 'none';
    
    // Reset pagination
    currentPage = 1;
    
    // Reload documents
    filtersApplied = false;
    loadDocuments();
}

function applyFilters() {
    filtersApplied = true;
    currentPage = 1;
    loadDocuments();
}

// Change page size
function changePageSize() {
    pageSize = parseInt(document.getElementById('pageSizeSelect').value);
    currentPage = 1;
    loadDocuments();
}

// Load documents with current filters
async function loadDocuments() {
    try {
        // Show loading state
        document.getElementById('documentsTableContainer').innerHTML = `
            <div class="loading-state">
                <i class="fas fa-spinner fa-spin"></i>
                <h3>Loading Documents</h3>
                <p>Please wait while we fetch your documents...</p>
            </div>
        `;
        
        // Collect filter values
        const filters = {
            project_id: document.getElementById('projectFilter').value,
            document_type: document.getElementById('documentTypeFilter').value,
            start_date: document.getElementById('startDate').value,
            end_date: document.getElementById('endDate').value,
            search: document.getElementById('searchFilter').value,
            sub_filters: Array.from(document.querySelectorAll('.sub-filter-option.selected')).map(el => el.textContent),
            page: currentPage,
            page_size: pageSize
        };

        // Context extra filters (pods)
        const podsStart = document.getElementById('podsDeliveryStart');
        const podsEnd = document.getElementById('podsDeliveryEnd');
        const podsBol = document.getElementById('podsBolNumber');
        const podsManSel = document.getElementById('podsManufacturerSelect');
        const podsWattMenu = document.getElementById('podsWattageMenu');
        if (podsStart) filters.pods_delivery_start = podsStart.value;
        if (podsEnd) filters.pods_delivery_end = podsEnd.value;
        if (podsBol) filters.pods_bol = podsBol.value;
        if (podsManSel) filters.pods_supplier = podsManSel.value;
        if (podsWattMenu) {
            const watts = Array.from(podsWattMenu.querySelectorAll('input[type="checkbox"]:checked')).map(cb => cb.value);
            if (watts.length) filters.pods_wattages = watts;
        }

        // Invoices context filters
        const invMin = document.getElementById('invTotalMin');
        const invMax = document.getElementById('invTotalMax');
        const invStart = document.getElementById('invDateStart');
        const invEnd = document.getElementById('invDateEnd');
        const invDueStart = document.getElementById('invDueStart');
        const invDueEnd = document.getElementById('invDueEnd');
        const invMan = document.getElementById('invManufacturerSelect');
        if (invMin && invMin.value) filters.invoice_total_min = invMin.value;
        if (invMax && invMax.value) filters.invoice_total_max = invMax.value;
        if (invStart && invStart.value) filters.invoice_date_start = invStart.value;
        if (invEnd && invEnd.value) filters.invoice_date_end = invEnd.value;
        if (invDueStart && invDueStart.value) filters.invoice_due_start = invDueStart.value;
        if (invDueEnd && invDueEnd.value) filters.invoice_due_end = invDueEnd.value;
        if (invMan && invMan.value) filters.invoice_manufacturer = invMan.value;
        
        // Build query string
        const queryParams = new URLSearchParams();
        Object.keys(filters).forEach(key => {
            if (filters[key] && filters[key] !== '') {
                if (Array.isArray(filters[key])) {
                    filters[key].forEach(value => queryParams.append(key + '[]', value));
                } else {
                    queryParams.append(key, filters[key]);
                }
            }
        });
        
        const response = await fetch(`get_global_documents.php?${queryParams.toString()}`);
        const data = await response.json();
        
        if (data.success) {
            allDocuments = data.documents;
            totalDocuments = data.total_count;
            
            renderDocumentsTable(data.documents);
            attachSortHandlers();
            // If PODs is selected and filters applied, keep subfilters bound to current table
            const docType = document.getElementById('documentTypeFilter').value;
            if (filtersApplied && docType === 'pods') {
                updatePodsManufacturersFromDocs(data.documents);
                updatePodsWattagesFromDocs(data.documents);
            } else if (filtersApplied && docType === 'invoices') {
                updateInvoicesManufacturersFromDocs(data.documents);
            }
            // Update subfilters visibility according to Apply state
            (function(){
                const type = document.getElementById('documentTypeFilter').value;
                const ctx = document.getElementById('contextSubfilters');
                const pods = document.getElementById('podsSubfilters');
                const invoices = document.getElementById('invoicesSubfilters');
                if (filtersApplied && type === 'pods') { ctx.style.display = ''; pods.style.display = ''; invoices.style.display='none'; }
                else if (filtersApplied && type === 'invoices') { ctx.style.display=''; pods.style.display='none'; invoices.style.display=''; }
                else { pods.style.display = 'none'; invoices.style.display='none'; ctx.style.display = 'none'; }
            })();
            updatePagination(data.total_count, data.total_pages);
            updateStats();
        } else {
            showError(data.message || 'Failed to load documents');
        }
    } catch (error) {
        console.error('Error loading documents:', error);
        showError('Network error occurred while loading documents');
    }
}

// Render documents table
function renderDocumentsTable(documents) {
    const container = document.getElementById('documentsTableContainer');
    
    if (documents.length === 0) {
        container.innerHTML = `
            <div class="empty-state">
                <i class="fas fa-folder-open"></i>
                <h3>No Documents Found</h3>
                <p>No documents match your current filter criteria. Try adjusting your filters.</p>
            </div>
        `;
        return;
    }
    
    const docType = document.getElementById('documentTypeFilter').value;
    const showPodsCols = (filtersApplied && docType === 'pods');
    const showInvCols = (filtersApplied && docType === 'invoices');
    const chips = Array.from(document.querySelectorAll('#subFilterOptions .sub-filter-option.selected')).map(el => el.textContent.trim());
    const showInvMan = showInvCols && chips.includes('Module Invoice');
    const tableHTML = `
        <table class="documents-table">
            <thead>
                <tr>
                    <th>
                        <input type="checkbox" id="selectAll" onchange="toggleSelectAll(this)">
                    </th>
                    <th class="sortable" data-sort="document">Document</th>
                    <th class="sortable" data-sort="type">Type</th>
                    <th class="sortable" data-sort="project">Project</th>
                    ${showPodsCols ? '<th class="sortable" data-sort="bol">BOL</th>' : ''}
                    ${showPodsCols ? '<th class="sortable" data-sort="manufacturer">Manufacturer</th>' : ''}
                    ${showPodsCols ? '<th class="sortable" data-sort="wattage">Wattage</th>' : ''}
                    ${showPodsCols ? '<th class="sortable" data-sort="delivered">Delivered</th>' : ''}
                    ${showInvMan ? '<th class="sortable" data-sort="manufacturer">Manufacturer</th>' : ''}
                    ${showInvCols ? '<th class="sortable" data-sort="inv_amount">Invoice Total</th>' : ''}
                    ${showInvCols ? '<th class="sortable" data-sort="inv_date">Invoice Date</th>' : ''}
                    ${showInvCols ? '<th class="sortable" data-sort="inv_due">Due Date</th>' : ''}
                    <th class="sortable" data-sort="size">Size</th>
                    <th class="sortable" data-sort="uploaded">Uploaded</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                ${documents.map(doc => renderDocumentRow(doc, showPodsCols, showInvCols, showInvMan)).join('')}
            </tbody>
        </table>
    `;
    
    container.innerHTML = tableHTML;
}

// Sorting
let currentSortKey = '';
let currentSortDir = 'asc';

function attachSortHandlers() {
    const headerCells = document.querySelectorAll('.documents-table thead th.sortable');
    headerCells.forEach(th => {
        th.style.cursor = 'pointer';
        th.onclick = () => {
            const key = th.getAttribute('data-sort');
            if (currentSortKey === key) {
                currentSortDir = currentSortDir === 'asc' ? 'desc' : 'asc';
            } else {
                currentSortKey = key;
                currentSortDir = 'asc';
            }
            sortDocuments();
        };
    });
}

function sortDocuments() {
    if (!currentSortKey) return;

    const dir = currentSortDir === 'asc' ? 1 : -1;
    const docs = [...allDocuments];
    const cmp = (a, b) => {
        switch (currentSortKey) {
            case 'document': {
                const A = (a.filename || '').toLowerCase();
                const B = (b.filename || '').toLowerCase();
                return A > B ? dir : A < B ? -dir : 0;
            }
            case 'type': {
                const A = `${a.document_type || ''}-${a.document_sub_type || ''}`.toLowerCase();
                const B = `${b.document_type || ''}-${b.document_sub_type || ''}`.toLowerCase();
                return A > B ? dir : A < B ? -dir : 0;
            }
            case 'project': {
                const A = (a.project_name || '').toLowerCase();
                const B = (b.project_name || '').toLowerCase();
                return A > B ? dir : A < B ? -dir : 0;
            }
            case 'bol': {
                const A = (a.bol_number || '').toLowerCase();
                const B = (b.bol_number || '').toLowerCase();
                return A > B ? dir : A < B ? -dir : 0;
            }
            case 'manufacturer': {
                const A = (a.manufacturer_name || '').toLowerCase();
                const B = (b.manufacturer_name || '').toLowerCase();
                return A > B ? dir : A < B ? -dir : 0;
            }
            case 'wattage': {
                const A = Number(a.delivery_wattage || 0);
                const B = Number(b.delivery_wattage || 0);
                return (A - B) * dir;
            }
            case 'delivered': {
                const A = new Date(a.actual_delivery_date || 0).getTime();
                const B = new Date(b.actual_delivery_date || 0).getTime();
                return (A - B) * dir;
            }
            case 'size': {
                const A = Number(a.size_bytes || 0);
                const B = Number(b.size_bytes || 0);
                return (A - B) * dir;
            }
            case 'uploaded': {
                const A = new Date(a.uploaded_at || 0).getTime();
                const B = new Date(b.uploaded_at || 0).getTime();
                return (A - B) * dir;
            }
            case 'inv_amount': {
                const A = Number(a.invoice_amount || 0);
                const B = Number(b.invoice_amount || 0);
                return (A - B) * dir;
            }
            case 'inv_date': {
                const A = new Date(a.invoice_issued_date || 0).getTime();
                const B = new Date(b.invoice_issued_date || 0).getTime();
                return (A - B) * dir;
            }
            default:
                return 0;
        }
    };

    docs.sort(cmp);
    renderDocumentsTable(docs);
    attachSortHandlers();
    updateCheckboxes();
}

// Render individual document row
function renderDocumentRow(doc, showPodsCols = false, showInvCols = false, showInvMan = false) {
    const isSelected = selectedDocuments.has(doc.id);
    const iconStyle = `background: ${getDocumentTypeColor(doc.document_type)};`;
    
    return `
                 <tr onclick="toggleDocumentSelection(${doc.id}, event)" data-doc-id="${doc.id}">
            <td>
                <input type="checkbox" class="document-checkbox" ${isSelected ? 'checked' : ''}
                       onclick="event.stopPropagation();"
                       onchange="toggleDocumentSelection(${doc.id}, event)">
            </td>
             <td>
                 <div class="document-info">
                     <div class="document-icon" style="${iconStyle}">
                         <i class="${getDocumentTypeIcon(doc.document_type)}"></i>
                     </div>
                     <div class="document-details">
                         <div class="document-name">${escapeHtml(doc.filename)}</div>
                         <div class="document-meta">
                             ${doc.description ? escapeHtml(doc.description) : 'No description'}
                             ${doc.bol_number ? `<br><strong>BOL:</strong> ${escapeHtml(doc.bol_number)}` : ''}
                             ${doc.actual_delivery_date ? `<br><strong>Delivered:</strong> ${formatDate(doc.actual_delivery_date)}` : ''}
                             ${doc.delivery_wattage ? `<br><strong>Wattage:</strong> ${escapeHtml(String(doc.delivery_wattage))}` : ''}
                             ${doc.warehouse_name ? `<br><strong>Warehouse:</strong> ${escapeHtml(doc.warehouse_name)}` : ''}
                         </div>
                     </div>
                 </div>
             </td>
            <td>
                <span class="document-type-badge">${getDocumentTypeName(doc.document_type)}</span>
                ${doc.document_sub_type ? `<br><span class="document-type-badge" style="background: rgba(34, 197, 94, 0.1); color: #16a34a; margin-top: 4px; font-size: 0.7em;">${escapeHtml(doc.document_sub_type)}</span>` : ''}
            </td>
            <td>
                <a href="project_documents.php?project_id=${doc.project_id}" class="project-link">
                    ${escapeHtml(doc.project_name)}
                </a>
            </td>
            ${showPodsCols ? `<td>${doc.bol_number ? escapeHtml(doc.bol_number) : ''}</td>` : ''}
            ${showPodsCols ? `<td>${doc.manufacturer_name ? escapeHtml(doc.manufacturer_name) : ''}</td>` : ''}
            ${showPodsCols ? `<td>${doc.delivery_wattage ? escapeHtml(String(doc.delivery_wattage)) : ''}</td>` : ''}
            ${showPodsCols ? `<td>${doc.actual_delivery_date ? formatDate(doc.actual_delivery_date) : ''}</td>` : ''}
            ${showInvMan ? `<td>${doc.manufacturer_name ? escapeHtml(doc.manufacturer_name) : ''}</td>` : ''}
            ${showInvCols ? `<td>${doc.invoice_amount != null ? formatAmount(doc.invoice_amount) : ''}</td>` : ''}
            ${showInvCols ? `<td>${doc.invoice_issued_date ? formatDate(doc.invoice_issued_date) : ''}</td>` : ''}
            ${showInvCols ? `<td>${doc.invoice_due_date ? formatDate(doc.invoice_due_date) : ''}</td>` : ''}
            <td>${doc.size}</td>
            <td>${formatDate(doc.uploaded_at)}</td>
             <td>
                 <div class="action-buttons">
                     <a href="download_document.php?id=${doc.id}" class="btn-download" target="_blank">
                         <i class="fas fa-download"></i>
                         Download
                     </a>
                     ${doc.delivery_id ? `<a href="view_project.php?project_id=${doc.project_id}&delivery_id=${doc.delivery_id}" class="btn-download" style="background: linear-gradient(135deg, #3b82f6, #2563eb);" title="View delivery details">
                         <i class=\"fas fa-eye\"></i>
                         Details
                     </a>` : ''}
                 </div>
             </td>
         </tr>
    `;
}

// Toggle document selection
function toggleDocumentSelection(docId, event) {
    event.stopPropagation();
    
    if (selectedDocuments.has(docId)) {
        selectedDocuments.delete(docId);
    } else {
        selectedDocuments.add(docId);
    }
    
    updateCheckboxes();
    updateStats();
}

// Toggle select all
function toggleSelectAll(checkbox) {
    const documentCheckboxes = document.querySelectorAll('.document-checkbox');
    
    if (checkbox.checked) {
        allDocuments.forEach(doc => selectedDocuments.add(doc.id));
    } else {
        selectedDocuments.clear();
    }
    
    updateCheckboxes();
    updateStats();
}

// Update checkbox states
function updateCheckboxes() {
    document.querySelectorAll('.document-checkbox').forEach(checkbox => {
        const row = checkbox.closest('tr');
        const docId = parseInt(row.dataset.docId);
        checkbox.checked = selectedDocuments.has(docId);
    });
    
    // Update select all checkbox
    const selectAllCheckbox = document.getElementById('selectAll');
    if (selectAllCheckbox) {
        const visibleDocIds = allDocuments.map(doc => doc.id);
        const allVisibleSelected = visibleDocIds.every(id => selectedDocuments.has(id));
        const someVisibleSelected = visibleDocIds.some(id => selectedDocuments.has(id));
        
        selectAllCheckbox.checked = allVisibleSelected && visibleDocIds.length > 0;
        selectAllCheckbox.indeterminate = someVisibleSelected && !allVisibleSelected;
    }
}

// Update statistics
function updateStats() {
    document.getElementById('totalDocuments').textContent = totalDocuments;
    document.getElementById('selectedDocuments').textContent = selectedDocuments.size;
    
    // Enable/disable bulk download button
    const bulkDownloadBtn = document.getElementById('bulkDownload');
    bulkDownloadBtn.disabled = selectedDocuments.size === 0;
    
    // Update results info
    const resultsInfo = document.getElementById('resultsInfo');
    const start = (currentPage - 1) * pageSize + 1;
    const end = Math.min(currentPage * pageSize, totalDocuments);
    resultsInfo.textContent = `Showing ${start}-${end} of ${totalDocuments} documents`;
}

// Update pagination
function updatePagination(totalCount, totalPages) {
    const paginationInfo = document.getElementById('paginationInfo');
    const paginationButtons = document.getElementById('paginationButtons');
    
    const start = (currentPage - 1) * pageSize + 1;
    const end = Math.min(currentPage * pageSize, totalCount);
    paginationInfo.textContent = `Showing ${start}-${end} of ${totalCount} documents`;
    
    // Generate pagination buttons
    let buttonsHTML = '';
    
    // Previous button
    buttonsHTML += `<button class="pagination-btn" onclick="changePage(${currentPage - 1})" ${currentPage <= 1 ? 'disabled' : ''}>
        <i class="fas fa-chevron-left"></i> Previous
    </button>`;
    
    // Page number buttons
    const maxVisiblePages = 5;
    let startPage = Math.max(1, currentPage - Math.floor(maxVisiblePages / 2));
    let endPage = Math.min(totalPages, startPage + maxVisiblePages - 1);
    
    if (endPage - startPage + 1 < maxVisiblePages) {
        startPage = Math.max(1, endPage - maxVisiblePages + 1);
    }
    
    for (let i = startPage; i <= endPage; i++) {
        buttonsHTML += `<button class="pagination-btn ${i === currentPage ? 'active' : ''}" onclick="changePage(${i})">${i}</button>`;
    }
    
    // Next button
    buttonsHTML += `<button class="pagination-btn" onclick="changePage(${currentPage + 1})" ${currentPage >= totalPages ? 'disabled' : ''}>
        Next <i class="fas fa-chevron-right"></i>
    </button>`;
    
    paginationButtons.innerHTML = buttonsHTML;
}

// Change page
function changePage(page) {
    if (page >= 1 && page <= Math.ceil(totalDocuments / pageSize)) {
        currentPage = page;
        loadDocuments();
    }
}

// Download selected documents
async function downloadSelected() {
    if (selectedDocuments.size === 0) {
        alert('Please select documents to download');
        return;
    }
    
    try {
        const documentIds = Array.from(selectedDocuments);
        const response = await fetch('bulk_download_documents.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({ document_ids: documentIds })
        });
        
        if (response.ok) {
            const blob = await response.blob();
            const url = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.style.display = 'none';
            a.href = url;
            a.download = `documents-${new Date().toISOString().split('T')[0]}.zip`;
            document.body.appendChild(a);
            a.click();
            window.URL.revokeObjectURL(url);
            document.body.removeChild(a);
        } else {
            const errorData = await response.json();
            alert(errorData.message || 'Failed to download documents');
        }
    } catch (error) {
        console.error('Error downloading documents:', error);
        alert('Network error occurred while downloading documents');
    }
}

// Helper functions
function getDocumentTypeIcon(type) {
    const icons = <?php echo json_encode(array_column($document_types, 'icon')); ?>;
    const types = <?php echo json_encode(array_keys($document_types)); ?>;
    const index = types.indexOf(type);
    return index !== -1 ? icons[index] : 'fas fa-file';
}

function getDocumentTypeName(type) {
    const names = <?php echo json_encode(array_column($document_types, 'name')); ?>;
    const types = <?php echo json_encode(array_keys($document_types)); ?>;
    const index = types.indexOf(type);
    return index !== -1 ? names[index] : type;
}

function getDocumentTypeColor(type) {
    const colors = <?php echo json_encode(array_column($document_types, 'color')); ?>;
    const types = <?php echo json_encode(array_keys($document_types)); ?>;
    const index = types.indexOf(type);
    return index !== -1 ? colors[index] : '#9ca3af';
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function formatDate(dateString) {
    return new Date(dateString).toLocaleDateString('en-US', {
        year: 'numeric',
        month: 'short',
        day: 'numeric'
    });
}

function formatAmount(val) {
    const n = Number(val);
    if (isNaN(n)) return '';
    return '$' + n.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function showError(message) {
    document.getElementById('documentsTableContainer').innerHTML = `
        <div class="empty-state">
            <i class="fas fa-exclamation-triangle"></i>
            <h3>Error</h3>
            <p>${escapeHtml(message)}</p>
        </div>
    `;
}

// Upload Modal Functions
let selectedFiles = [];

function openUploadModal() {
    document.getElementById('uploadModal').style.display = 'block';
    resetUploadForm();
}

function closeUploadModal() {
    document.getElementById('uploadModal').style.display = 'none';
    resetUploadForm();
}

function resetUploadForm() {
    document.getElementById('uploadForm').reset();
    selectedFiles = [];
    document.getElementById('fileList').innerHTML = '';
    document.getElementById('dynamicFields').style.display = 'none';
    document.getElementById('uploadBtn').disabled = true;
    document.getElementById('uploadProgress').style.display = 'none';
    document.getElementById('documentDescription').value = '';
    updateSubTypes(); // Clear sub-types
}

// Document type and sub-type configurations
const documentConfig = {
    'invoices': {
        'sub_types': ['Solterra Invoice', 'Module Invoice'],
        'fields': {
            'Solterra Invoice': [
                {type: 'number', name: 'invoice_total', label: 'Invoice Total ($)', required: true, step: '0.01'},
                {type: 'date', name: 'invoice_date', label: 'Invoice Date', required: true},
                {type: 'date', name: 'invoice_due_date', label: 'Due Date', required: true}
            ],
            'Module Invoice': [
                {type: 'select', name: 'manufacturer_id', label: 'Manufacturer', required: true, data_source: 'manufacturers'},
                {type: 'number', name: 'invoice_total', label: 'Invoice Total ($)', required: true, step: '0.01'},
                {type: 'date', name: 'invoice_date', label: 'Invoice Date', required: true},
                {type: 'date', name: 'invoice_due_date', label: 'Due Date', required: true}
            ]
        }
    },
    'pods': {
        'sub_types': ['Project POD', 'Warehouse POD'],
        'fields': {
            'Project POD': [
                {type: 'bol_autocomplete', name: 'delivery_id', label: 'BOL/Delivery', required: true}
            ],
            'Warehouse POD': [
                {type: 'bol_autocomplete', name: 'delivery_id', label: 'BOL/Delivery', required: true},
                {type: 'select', name: 'warehouse_id', label: 'Warehouse', required: true, data_source: 'warehouses'}
            ]
        }
    },
    'shipments': {
        'sub_types': ['Arrival Notice', 'Customs Document', 'Delivery SOP'],
        'fields': {
            'Arrival Notice': [
                {type: 'bol_autocomplete', name: 'delivery_id', label: 'BOL/Delivery', required: false},
                {type: 'select', name: 'warehouse_id', label: 'Warehouse/Port', required: false, data_source: 'warehouses'}
            ],
            'Customs Document': [
                {type: 'bol_autocomplete', name: 'delivery_id', label: 'BOL/Delivery', required: false},
                {type: 'select', name: 'warehouse_id', label: 'Warehouse/Port', required: false, data_source: 'warehouses'}
            ],
            'Delivery SOP': []
        }
    },
    'warehousing': {
        'sub_types': ['Warehouse POD', 'Inventory Report', 'Warehouse Photo'],
        'fields': {
            'Warehouse POD': [
                {type: 'bol_autocomplete', name: 'delivery_id', label: 'BOL/Delivery', required: true},
                {type: 'select', name: 'warehouse_id', label: 'Warehouse', required: true, data_source: 'warehouses'}
            ],
            'Inventory Report': [
                {type: 'select', name: 'warehouse_id', label: 'Warehouse', required: true, data_source: 'warehouses'}
            ],
            'Warehouse Photo': [
                {type: 'select', name: 'warehouse_id', label: 'Warehouse', required: true, data_source: 'warehouses'}
            ]
        }
    },
    'modules': {
        'sub_types': ['Module Invoice', 'Flash Test Data', 'Data/Spec Sheet'],
        'fields': {
            'Module Invoice': [
                {type: 'select', name: 'manufacturer_id', label: 'Manufacturer', required: true, data_source: 'manufacturers'},
                {type: 'number', name: 'invoice_total', label: 'Invoice Total ($)', required: false, step: '0.01'},
                {type: 'date', name: 'invoice_date', label: 'Invoice Date', required: false}
            ],
            'Flash Test Data': [
                {type: 'select', name: 'manufacturer_id', label: 'Manufacturer', required: false, data_source: 'manufacturers'}
            ],
            'Data/Spec Sheet': [
                {type: 'select', name: 'manufacturer_id', label: 'Manufacturer', required: false, data_source: 'manufacturers'}
            ]
        }
    },
    'incident_reports': {
        'sub_types': ['Damage Photo', 'Warranty Document', 'Project POD', 'Warehouse POD'],
        'fields': {
            'Damage Photo': [
                {type: 'bol_autocomplete', name: 'delivery_id', label: 'BOL/Delivery', required: false}
            ],
            'Warranty Document': [],
            'Project POD': [
                {type: 'bol_autocomplete', name: 'delivery_id', label: 'BOL/Delivery', required: true}
            ],
            'Warehouse POD': [
                {type: 'bol_autocomplete', name: 'delivery_id', label: 'BOL/Delivery', required: true},
                {type: 'select', name: 'warehouse_id', label: 'Warehouse', required: true, data_source: 'warehouses'}
            ]
        }
    },
    'other': {
        'sub_types': ['General'],
        'fields': {
            'General': []
        }
    }
};

function updateSubTypes() {
    const documentType = document.getElementById('uploadDocumentType').value;
    const subTypeSelect = document.getElementById('uploadDocumentSubType');
    
    // Clear existing options
    subTypeSelect.innerHTML = '<option value="">Choose sub-type...</option>';
    
    if (documentType && documentConfig[documentType]) {
        documentConfig[documentType].sub_types.forEach(subType => {
            const option = document.createElement('option');
            option.value = subType;
            option.textContent = subType;
            subTypeSelect.appendChild(option);
        });
    }
    
    // Clear dynamic fields when document type changes
    document.getElementById('dynamicFields').style.display = 'none';
    validateForm();
}

async function updateDynamicFields() {
    const documentType = document.getElementById('uploadDocumentType').value;
    const subType = document.getElementById('uploadDocumentSubType').value;
    const dynamicFields = document.getElementById('dynamicFields');
    
    if (!documentType || !subType) {
        dynamicFields.style.display = 'none';
        validateForm();
        return;
    }
    
    const fields = documentConfig[documentType]?.fields?.[subType] || [];
    
    if (fields.length === 0) {
        dynamicFields.style.display = 'none';
        validateForm();
        return;
    }
    
    let html = '';
    
    for (const field of fields) {
        html += `<div class="form-group">
            <label class="form-label" for="${field.name}">
                ${field.label}${field.required ? ' *' : ''}
            </label>`;
        
        if (field.type === 'bol_autocomplete') {
            html += `<div class="bol-autocomplete-container">
                <input type="text" id="${field.name}" name="${field.name}_text" class="form-input" 
                    ${field.required ? 'required' : ''} 
                    placeholder="Type BOL number..." 
                    autocomplete="off"
                    oninput="handleBolAutocomplete(this, '${field.name}')"
                    onfocus="showBolSuggestions('${field.name}')"
                    onblur="hideBolSuggestions('${field.name}')">
                <input type="hidden" id="${field.name}_hidden" name="${field.name}" value="">
                <div id="${field.name}_suggestions" class="bol-suggestions"></div>
                <div id="${field.name}_validation" class="bol-validation" style="display: none;"></div>
            </div>`;
        } else if (field.type === 'select' && field.data_source) {
            html += `<select id="${field.name}" name="${field.name}" class="form-select" ${field.required ? 'required' : ''} onchange="validateForm()">
                <option value="">Choose ${field.label.toLowerCase()}...</option>
            </select>`;
            
            // Load data for select fields
            try {
                const response = await fetch(`get_${field.data_source}.php?project_id=${document.getElementById('uploadProjectSelect').value}`);
                const data = await response.json();
                if (data.success) {
                    const selectElement = document.getElementById(field.name);
                    data.data.forEach(item => {
                        const option = document.createElement('option');
                        option.value = item.id;
                        option.textContent = item.name || item.bol_number || item.location_name;
                        selectElement.appendChild(option);
                    });
                }
            } catch (error) {
                console.warn(`Failed to load ${field.data_source}:`, error);
            }
        } else if (field.type === 'number') {
            html += `<input type="number" id="${field.name}" name="${field.name}" class="form-input" 
                ${field.required ? 'required' : ''} ${field.step ? `step="${field.step}"` : ''} 
                onchange="validateForm()" placeholder="Enter ${field.label.toLowerCase()}">`;
        } else if (field.type === 'date') {
            html += `<input type="date" id="${field.name}" name="${field.name}" class="form-input" 
                ${field.required ? 'required' : ''} onchange="validateForm()">`;
        } else {
            html += `<input type="text" id="${field.name}" name="${field.name}" class="form-input" 
                ${field.required ? 'required' : ''} onchange="validateForm()" 
                placeholder="Enter ${field.label.toLowerCase()}">`;
        }
        
        html += '</div>';
    }
    
    dynamicFields.innerHTML = html;
    dynamicFields.style.display = 'block';
    validateForm();
}

function handleFileSelection(event) {
    const files = Array.from(event.target.files);
    selectedFiles = [...selectedFiles, ...files];
    updateFileList();
    validateForm();
}

function updateFileList() {
    const fileList = document.getElementById('fileList');
    
    if (selectedFiles.length === 0) {
        fileList.innerHTML = '';
        return;
    }
    
    let html = '';
    selectedFiles.forEach((file, index) => {
        const fileSize = (file.size / 1024 / 1024).toFixed(2);
        html += `
            <div class="file-item">
                <div class="file-info">
                    <i class="fas fa-file file-icon"></i>
                    <div>
                        <div>${escapeHtml(file.name)}</div>
                        <div style="font-size: 0.8em; color: #9ca3af;">${fileSize} MB</div>
                    </div>
                </div>
                <button type="button" class="remove-file" onclick="removeFile(${index})">Remove</button>
            </div>
        `;
    });
    
    fileList.innerHTML = html;
}

function removeFile(index) {
    selectedFiles.splice(index, 1);
    updateFileList();
    validateForm();
}

function validateForm() {
    const projectId = document.getElementById('uploadProjectSelect').value;
    const documentType = document.getElementById('uploadDocumentType').value;
    const subType = document.getElementById('uploadDocumentSubType').value;
    const hasFiles = selectedFiles.length > 0;
    
    // Check required dynamic fields
    let requiredFieldsValid = true;
    if (documentType && subType && documentConfig[documentType]?.fields?.[subType]) {
        const fields = documentConfig[documentType].fields[subType];
        fields.forEach(field => {
            if (field.required) {
                let element;
                if (field.type === 'bol_autocomplete') {
                    // For BOL autocomplete, check the hidden field
                    element = document.getElementById(field.name + '_hidden');
                } else {
                    element = document.getElementById(field.name);
                }
                if (element && !element.value) {
                    requiredFieldsValid = false;
                }
            }
        });
    }
    
    const isValid = projectId && documentType && subType && hasFiles && requiredFieldsValid;
    document.getElementById('uploadBtn').disabled = !isValid;
}

async function uploadDocuments() {
    if (selectedFiles.length === 0) {
        alert('Please select files to upload.');
        return;
    }
    
    const formData = new FormData();
    
    // Add form fields
    formData.append('project_id', document.getElementById('uploadProjectSelect').value);
    formData.append('document_type', document.getElementById('uploadDocumentType').value);
    formData.append('document_sub_type', document.getElementById('uploadDocumentSubType').value);
    formData.append('is_safe_harbor', document.getElementById('isSafeHarbor').checked ? '1' : '0');
    formData.append('description', document.getElementById('documentDescription').value);
    
    // Add dynamic field values
    const documentType = document.getElementById('uploadDocumentType').value;
    const subType = document.getElementById('uploadDocumentSubType').value;
    if (documentConfig[documentType]?.fields?.[subType]) {
        documentConfig[documentType].fields[subType].forEach(field => {
            let element;
            if (field.type === 'bol_autocomplete') {
                // For BOL autocomplete, use the hidden field value
                element = document.getElementById(field.name + '_hidden');
            } else {
                element = document.getElementById(field.name);
            }
            if (element && element.value) {
                formData.append(field.name, element.value);
            }
        });
    }
    
    // Add files
    selectedFiles.forEach(file => {
        formData.append('files[]', file);
    });
    
    // Show progress
    document.getElementById('uploadProgress').style.display = 'block';
    document.getElementById('uploadBtn').disabled = true;
    
    try {
        const response = await fetch('upload_global_document.php', {
            method: 'POST',
            body: formData
        });
        
        const result = await response.json();
        
        if (result.success) {
            alert('Documents uploaded successfully!');
            closeUploadModal();
            loadDocuments(); // Refresh the documents list
        } else {
            alert('Upload failed: ' + result.message);
        }
    } catch (error) {
        alert('Upload failed: ' + error.message);
    } finally {
        document.getElementById('uploadProgress').style.display = 'none';
        document.getElementById('uploadBtn').disabled = false;
    }
}

// Add drag and drop functionality
document.addEventListener('DOMContentLoaded', function() {
    const fileUploadArea = document.querySelector('.file-upload-area');
    
    if (fileUploadArea) {
        fileUploadArea.addEventListener('dragover', function(e) {
            e.preventDefault();
            this.classList.add('dragover');
        });
        
        fileUploadArea.addEventListener('dragleave', function(e) {
            e.preventDefault();
            this.classList.remove('dragover');
        });
        
        fileUploadArea.addEventListener('drop', function(e) {
            e.preventDefault();
            this.classList.remove('dragover');
            
            const files = Array.from(e.dataTransfer.files);
            selectedFiles = [...selectedFiles, ...files];
            updateFileList();
            validateForm();
        });
    }

    // Auto-open upload modal and pre-populate if coming from project documents
    <?php if ($auto_open_upload && $can_upload): ?>
        // Pre-populate form fields
        <?php if ($pre_selected_project > 0): ?>
            document.getElementById('uploadProjectSelect').value = '<?php echo $pre_selected_project; ?>';
        <?php endif; ?>
        
        <?php if (!empty($pre_selected_document_type)): ?>
            document.getElementById('uploadDocumentType').value = '<?php echo $pre_selected_document_type; ?>';
            updateSubTypes(); // Populate sub-types
            
            <?php if (!empty($pre_selected_document_sub_type)): ?>
                // Wait for sub-types to be populated, then select the sub-type
                setTimeout(() => {
                    document.getElementById('uploadDocumentSubType').value = '<?php echo $pre_selected_document_sub_type; ?>';
                    updateDynamicFields(); // Show relevant fields
                    validateForm(); // Check if form is now valid
                }, 100);
            <?php endif; ?>
        <?php endif; ?>
        
        // Open the upload modal
        setTimeout(() => {
            openUploadModal();
        }, 200);
    <?php endif; ?>
    
});

// BOL Autocomplete Functions
let bolSearchTimeout;
let currentBolSuggestions = {};

async function handleBolAutocomplete(input, fieldName) {
    const query = input.value.trim();
    const validationDiv = document.getElementById(fieldName + '_validation');
    const hiddenInput = document.getElementById(fieldName + '_hidden');
    
    // Clear previous timeout
    if (bolSearchTimeout) {
        clearTimeout(bolSearchTimeout);
    }
    
    // Clear hidden value when text changes
    hiddenInput.value = '';
    
    if (query.length === 0) {
        hideBolSuggestions(fieldName);
        validationDiv.style.display = 'none';
        validateForm();
        return;
    }
    
    // Show searching state
    validationDiv.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Searching...';
    validationDiv.className = 'bol-validation searching';
    validationDiv.style.display = 'flex';
    
    // Debounce the search
    bolSearchTimeout = setTimeout(async () => {
        try {
            const projectId = document.getElementById('uploadProjectSelect').value;
            const response = await fetch(`search_bol.php?project_id=${projectId}&q=${encodeURIComponent(query)}`);
            const data = await response.json();
            
            if (data.success) {
                currentBolSuggestions[fieldName] = data.deliveries;
                showBolSuggestions(fieldName, data.deliveries, query);
                
                // Check for exact match
                const exactMatch = data.deliveries.find(d => 
                    d.bol_number && d.bol_number.toLowerCase() === query.toLowerCase()
                );
                
                if (exactMatch) {
                    validationDiv.innerHTML = '<i class="fas fa-check-circle"></i> BOL found';
                    validationDiv.className = 'bol-validation valid';
                    hiddenInput.value = exactMatch.id;
                } else if (data.deliveries.length > 0) {
                    validationDiv.innerHTML = '<i class="fas fa-search"></i> Similar BOLs found - select one';
                    validationDiv.className = 'bol-validation searching';
                } else {
                    validationDiv.innerHTML = '<i class="fas fa-exclamation-circle"></i> BOL not found';
                    validationDiv.className = 'bol-validation invalid';
                }
            } else {
                validationDiv.innerHTML = '<i class="fas fa-exclamation-triangle"></i> Search error';
                validationDiv.className = 'bol-validation invalid';
            }
        } catch (error) {
            console.error('BOL search error:', error);
            validationDiv.innerHTML = '<i class="fas fa-exclamation-triangle"></i> Search error';
            validationDiv.className = 'bol-validation invalid';
        }
        
        validateForm();
    }, 300);
}

function showBolSuggestions(fieldName, suggestions = null, query = '') {
    const suggestionsDiv = document.getElementById(fieldName + '_suggestions');
    
    if (!suggestions) {
        suggestions = currentBolSuggestions[fieldName] || [];
    }
    
    if (suggestions.length === 0) {
        suggestionsDiv.style.display = 'none';
        return;
    }
    
    let html = '';
    suggestions.forEach(delivery => {
        const bolNumber = delivery.bol_number || `Delivery #${delivery.id}`;
        const details = [];
        
        if (delivery.supplier) details.push(delivery.supplier);
        if (delivery.status) details.push(delivery.status);
        if (delivery.warehouse_name) details.push(`→ ${delivery.warehouse_name}`);
        
        html += `
            <div class="bol-suggestion" onclick="selectBolSuggestion('${fieldName}', ${delivery.id}, '${bolNumber.replace(/'/g, "\\'")}')">
                <div class="bol-suggestion-main">${escapeHtml(bolNumber)}</div>
                ${details.length > 0 ? `<div class="bol-suggestion-details">${escapeHtml(details.join(' • '))}</div>` : ''}
            </div>
        `;
    });
    
    suggestionsDiv.innerHTML = html;
    suggestionsDiv.style.display = 'block';
}

function selectBolSuggestion(fieldName, deliveryId, bolNumber) {
    const input = document.getElementById(fieldName);
    const hiddenInput = document.getElementById(fieldName + '_hidden');
    const validationDiv = document.getElementById(fieldName + '_validation');
    
    input.value = bolNumber;
    hiddenInput.value = deliveryId;
    
    validationDiv.innerHTML = '<i class="fas fa-check-circle"></i> BOL selected';
    validationDiv.className = 'bol-validation valid';
    validationDiv.style.display = 'flex';
    
    hideBolSuggestions(fieldName);
    validateForm();
}

function hideBolSuggestions(fieldName) {
    // Add a small delay to allow for clicks on suggestions
    setTimeout(() => {
        const suggestionsDiv = document.getElementById(fieldName + '_suggestions');
        if (suggestionsDiv) {
            suggestionsDiv.style.display = 'none';
        }
    }, 150);
}
function updatePodsManufacturersFromDocs(documents) {
    const sel = document.getElementById('podsManufacturerSelect');
    if (!sel) return;
    const current = sel.value;
    const names = new Set();
    documents.forEach(d => {
        if (d.manufacturer_name) names.add(String(d.manufacturer_name));
    });
    sel.innerHTML = '<option value="">All Manufacturers</option>';
    Array.from(names).sort((a,b)=>a.localeCompare(b)).forEach(name => {
        const opt = document.createElement('option');
        opt.value = name;
        opt.textContent = name;
        sel.appendChild(opt);
    });
    if (current && Array.from(names).includes(current)) sel.value = current;
}
</script>
</body>
</html>
