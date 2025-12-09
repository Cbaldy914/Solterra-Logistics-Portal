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
    'shipments' => [
        'name' => 'Shipments',
        'icon' => 'fas fa-shipping-fast',
        'color' => '#8b5cf6',
        'sub_filters' => ['Arrival Notice', 'Customs Document', 'Delivery SOP', 'Project POD', 'Warehouse POD']
    ],
    'warehousing' => [
        'name' => 'Warehousing',
        'icon' => 'fas fa-warehouse',
        'color' => '#06b6d4',
        'sub_filters' => ['Warehouse POD', 'Inventory Report', 'Photos', 'Quote']
    ],
    'modules' => [
        'name' => 'Modules',
        'icon' => 'fas fa-microchip',
        'color' => '#10b981',
        'sub_filters' => ['Module Invoice', 'Flash Test Data', 'Spec Sheets']
    ],
    'photos' => [
        'name' => 'Photos',
        'icon' => 'fas fa-camera',
        'color' => '#f59e0b',
        'sub_filters' => ['Project Photo','Warehouse Photo','Damage Photo']
    ],
    'exception_reports' => [
        'name' => 'Exception Reports',
        'icon' => 'fas fa-exclamation-triangle',
        'color' => '#ef4444',
        'sub_filters' => []
    ],
    'safe_harbor_evidence' => [
        'name' => 'Safe Harbor',
        'icon' => 'fas fa-gavel',
        'color' => '#6366f1',
        'sub_filters' => []
    ],
    'other' => [
        'name' => 'Other',
        'icon' => 'fas fa-file-alt',
        'color' => '#6b7280',
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
$from_page = isset($_GET['from']) ? trim($_GET['from']) : '';

// Map folder keys to document types and sub-types
$folder_mapping = [
    'invoices' => ['document_type' => 'invoices', 'subfolders' => ['freight_invoice' => 'Freight Invoice', 'solterra_invoice' => 'Solterra Invoice', 'module_invoice' => 'Module Invoice']],
    // pods removed; handled under shipments
    'shipments' => ['document_type' => 'shipments', 'subfolders' => [
        'project_pod' => 'Project POD',
        'warehouse_pod' => 'Warehouse POD',
        'arrival_notice' => 'Arrival Notice',
        'customs_document' => 'Customs Document',
        'delivery_sop' => 'Delivery SOP'
    ]],
    'warehousing' => ['document_type' => 'warehousing', 'subfolders' => ['warehouse_pod' => 'Warehouse POD', 'inventory_report' => 'Inventory Report', 'warehouse_photo' => 'Photos', 'quote' => 'Quote']],
    'modules' => ['document_type' => 'modules', 'subfolders' => ['module_invoice' => 'Module Invoice', 'flash_test_data' => 'Flash Test Data', 'spec_sheet' => 'Spec Sheets']],
    // Virtual Photos folder routing to appropriate single-type filters
    'photos' => ['document_type' => 'photos', 'subfolders' => [
        'project_photo' => 'Project Photo',
        'warehouse_photo' => 'Warehouse Photo',
        'damage_photo' => 'Damage Photo'
    ]],
    'exception_reports' => ['document_type' => 'exception_reports', 'subfolders' => ['damage_photo' => 'Damage Photo', 'warranty_document' => 'Warranty Document', 'proof_of_completion' => 'Proof of Completion', 'safety_incident' => 'Safety Incident', 'project_pod' => 'Project POD', 'warehouse_pod' => 'Warehouse POD']],
    'safe_harbor' => ['document_type' => 'other', 'subfolders' => ['module_invoice' => 'Module Invoice', 'project_pod' => 'Project POD', 'warehouse_pod' => 'Warehouse POD', 'arrival_notice' => 'Arrival Notice', 'customs_document' => 'Customs Document', 'inventory_report' => 'Inventory Report', 'warehouse_photo' => 'Warehouse Photo', 'flash_test_data' => 'Flash Test Data']],
    'other' => ['document_type' => 'other', 'subfolders' => ['general' => 'General']]
];

$pre_selected_document_type = '';
$pre_selected_document_sub_type = '';

if (!empty($pre_selected_folder) && isset($folder_mapping[$pre_selected_folder])) {
    $pre_selected_document_type = $folder_mapping[$pre_selected_folder]['document_type'];
    
    if (!empty($pre_selected_subfolder) && isset($folder_mapping[$pre_selected_folder]['subfolders'][$pre_selected_subfolder])) {
        $sf_val = $folder_mapping[$pre_selected_folder]['subfolders'][$pre_selected_subfolder];
        if (is_array($sf_val)) {
            // Allow subfolder-specific overrides of document_type and document_sub_type
            if (!empty($sf_val['document_type'])) {
                $pre_selected_document_type = $sf_val['document_type'];
            }
            if (!empty($sf_val['document_sub_type'])) {
                $pre_selected_document_sub_type = $sf_val['document_sub_type'];
            }
        } else {
            $pre_selected_document_sub_type = $sf_val;
        }
    }
}
$is_pods_context = ($pre_selected_folder === 'pods');

// Get project name for breadcrumb if coming from project overview
$project_name = '';
if ($from_page === 'project_overview' && $pre_selected_project > 0) {
    $stmt = $conn->prepare("SELECT project_name FROM projects WHERE id = ?");
    $stmt->bind_param("i", $pre_selected_project);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        $project_name = $row['project_name'];
    }
    $stmt->close();
}
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

        /* Table header action buttons */
        .table-actions {
            display: flex;
            gap: 12px;
            align-items: center;
            margin-left: auto;
        }

        .table-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 24px;
            flex-wrap: wrap;
            gap: 16px;
        }

        .table-header-left {
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .table-upload-btn, .table-download-btn, .table-delete-btn {
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
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
        }

        .table-upload-btn {
            background: linear-gradient(135deg, #22c55e 0%, #16a34a 100%);
            color: white;
        }

        .table-upload-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(34, 197, 94, 0.3);
        }

        .table-download-btn {
            background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
            color: white;
        }

        .table-download-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(59, 130, 246, 0.3);
        }

        .table-download-btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
            transform: none;
        }

        .table-delete-btn {
            background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
            color: white;
        }

        .table-delete-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(239, 68, 68, 0.3);
        }

        .table-export-csv-btn {
            background: linear-gradient(135deg, #06b6d4 0%, #0891b2 100%);
            color: white;
            font-size: 0.9em;
            font-weight: 500;
            padding: 10px 16px;
            border-radius: 10px;
            border: none;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s ease;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
        }

        .table-export-csv-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(6, 182, 212, 0.4);
            background: linear-gradient(135deg, #0891b2 0%, #0e7490 100%);
        }

        .table-export-csv-btn:active {
            transform: translateY(0);
        }

        /* Dropdown positioning fix */
        .checkbox-menu {
            position: absolute !important;
            top: 100% !important;
            left: 0 !important;
            right: 0 !important;
            z-index: 10000;
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            padding: 8px;
            max-height: 240px;
            overflow-y: auto;
            min-width: 220px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.12);
            transform: translateY(4px);
        }

        /* Ensure parent container has relative positioning */
        .filter-group {
            position: relative;
        }


        @media (max-width: 768px) {
            .table-header {
                flex-direction: column;
                align-items: stretch;
            }

            .table-actions {
                justify-content: center;
                margin-left: 0;
            }

            .table-upload-btn, .table-download-btn, .table-delete-btn {
                font-size: 0.85em;
                padding: 8px 12px;
            }

            .checkbox-menu {
                min-width: 200px;
                max-height: 180px;
            }
        }

                 .filter-grid {
             display: grid;
             grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
             gap: 24px;
             align-items: start; /* Align all filter groups to the top */
         }

         /* Enhanced responsive grid to prevent overlap */
         @media (max-width: 1600px) {
             .filter-grid {
                 grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
             }
         }

         @media (max-width: 1400px) {
             .filter-grid {
                 grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
             }
         }

         @media (max-width: 1200px) {
             .filter-grid {
                 grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
             }
         }

         @media (max-width: 1000px) {
             .filter-grid {
                 grid-template-columns: repeat(2, 1fr);
                 gap: 20px;
             }
         }

         @media (max-width: 850px) {
             .filter-grid {
                 grid-template-columns: 1fr 1fr;
                 gap: 16px;
             }
         }

         .filter-group {
             position: relative;
             display: flex;
             flex-direction: column;
             height: fit-content;
             align-items: stretch;
             justify-content: flex-start;
         }

         /* Ensure consistent baseline alignment for all filter elements */
         .filter-group .filter-label {
             flex-shrink: 0;
             margin-bottom: 8px;
             line-height: 1.4;
             min-height: 22px;
             display: flex;
             align-items: flex-end;
         }

         .filter-group .filter-select,
         .filter-group .filter-input,
         .filter-group .date-range-group {
             flex-shrink: 0;
         }

        .filter-label {
            font-weight: 600;
            color: #293E4C;
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

         /* Fix cursor for readonly inputs (wattage dropdowns) */
         .filter-input[readonly] {
             cursor: pointer;
         }

         .filter-input[readonly]:hover {
             border-color: #488C9A;
             background-color: #f8f9fa;
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
            align-items: center;
        }

        @media (max-width: 768px) {
            .sub-filter-options {
                gap: 6px;
            }

            .sub-filter-option {
                font-size: 0.8em;
                padding: 4px 8px;
                border-radius: 16px;
            }
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
         .global-documents-page .btn-details,
         .global-documents-page .btn-ticket,
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

         .global-documents-page .btn-download,
         .global-documents-page a.btn-download {
             background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%) !important;
             color: white !important;
             border: none !important;
             text-shadow: none !important;
             box-shadow: 0 2px 8px rgba(59, 130, 246, 0.3) !important;
         }

         .global-documents-page .btn-download:hover,
         .global-documents-page a.btn-download:hover {
             background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%) !important;
             transform: translateY(-1px) !important;
             color: white !important;
             text-decoration: none !important;
             box-shadow: 0 4px 12px rgba(59, 130, 246, 0.4) !important;
         }

         /* Details button styling */
         .global-documents-page .btn-details,
         .global-documents-page a.btn-details {
             background: linear-gradient(135deg, #488C9A 0%, #3A6E7F 100%) !important;
             color: white !important;
             border: none !important;
             text-shadow: none !important;
             box-shadow: 0 2px 8px rgba(72, 140, 154, 0.3) !important;
         }

         .global-documents-page .btn-details:hover,
         .global-documents-page a.btn-details:hover {
             background: linear-gradient(135deg, #3A6E7F 0%, #293E4C 100%) !important;
             transform: translateY(-1px) !important;
             color: white !important;
             text-decoration: none !important;
             box-shadow: 0 4px 12px rgba(72, 140, 154, 0.4) !important;
         }

         /* Ticket button styling */
         .global-documents-page .btn-ticket,
         .global-documents-page a.btn-ticket {
             background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%) !important;
             color: white !important;
             border: none !important;
             text-shadow: none !important;
             box-shadow: 0 2px 8px rgba(245, 158, 11, 0.3) !important;
         }

         .global-documents-page .btn-ticket:hover,
         .global-documents-page a.btn-ticket:hover {
             background: linear-gradient(135deg, #d97706 0%, #b45309 100%) !important;
             transform: translateY(-1px) !important;
             color: white !important;
             text-decoration: none !important;
             box-shadow: 0 4px 12px rgba(245, 158, 11, 0.4) !important;
         }

         /* Action buttons container styling */
         .action-buttons {
             display: flex !important;
             gap: 8px !important;
             flex-wrap: wrap !important;
             align-items: center !important;
             justify-content: flex-start !important;
         }

         .action-buttons a {
             flex-shrink: 0 !important;
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
                grid-template-columns: 1fr !important;
                gap: 16px;
            }

            .date-range-group {
                grid-template-columns: 1fr;
                gap: 8px;
            }

            .filter-actions {
                flex-wrap: wrap;
                gap: 8px;
                justify-content: flex-start;
                width: 100%;
            }

            .filter-actions button {
                font-size: 0.85em;
                padding: 8px 12px;
                flex: 1;
                min-width: 120px;
            }

            .filter-group {
                width: 100%;
            }

            .filter-label {
                margin-bottom: 6px;
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

        @media (max-width: 480px) {
            .filter-grid {
                gap: 12px;
            }

            .date-range-group {
                grid-template-columns: 1fr;
            }

            .filter-input, .filter-select {
                font-size: 0.9em;
            }

            .table-actions {
                flex-direction: column;
                gap: 8px;
                width: 100%;
            }

            .table-upload-btn, .table-download-btn, .table-delete-btn {
                width: 100%;
                justify-content: center;
            }

            .action-buttons {
                gap: 6px !important;
            }

            .action-buttons a {
                font-size: 0.75em !important;
                padding: 4px 8px !important;
            }

            .global-documents-page .btn-download,
            .global-documents-page .btn-details,
            .global-documents-page .btn-ticket {
                font-size: 0.75em !important;
                padding: 4px 8px !important;
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
    <?php
        require_once 'components/breadcrumbs.php';
        $extra = [];
        if ($from_page === 'project_overview' && !empty($project_name) && !empty($pre_selected_project)) {
            $extra[] = ['label' => 'Project Overview ('.htmlspecialchars($project_name).')', 'url' => 'project_overview.php?project_id='.(int)$pre_selected_project];
        } else {
            $extra[] = ['label' => 'Documents', 'url' => 'documents.php'];
        }
        echo slp_render_breadcrumbs(['current_label' => 'Global Documents', 'extra' => $extra]);
    ?>

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
                Document Filters
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
                <select id="documentTypeFilter" class="filter-select" onchange="updateDocumentSubTypeFilter()">
                    <option value="">All Document Types</option>
                    <?php foreach ($document_types as $type => $info): ?>
                        <option value="<?php echo $type; ?>"><?php echo htmlspecialchars($info['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="filter-group" id="subTypeFilterGroup" style="display: none;">
                <label class="filter-label" for="documentSubTypeFilter">Document Sub-Type</label>
                <select id="documentSubTypeFilter" class="filter-select">
                    <option value="">All Sub-Types</option>
                </select>
            </div>

            <div class="filter-group" id="warehouseFilterGroup" style="display: none;">
                <label class="filter-label" for="warehouseFilterContext">Warehouse</label>
                <select id="warehouseFilterContext" class="filter-select">
                    <option value="">All Warehouses</option>
                    <!-- Populated dynamically -->
                </select>
            </div>

            <div class="filter-group">
                <label class="filter-label">
                    <i class="fas fa-calendar-upload" style="margin-right: 6px;"></i>
                    Filter by Upload Date
                </label>
                <div class="date-range-group">
                    <input type="date" id="startDate" class="filter-input" placeholder="From date">
                    <input type="date" id="endDate" class="filter-input" placeholder="To date">
                </div>
            </div>

            <div class="filter-group">
                <label class="filter-label" for="searchFilter">Search Documents</label>
                <input type="text" id="searchFilter" class="filter-input" placeholder="Search by filename, description...">
            </div>
        </div>
    </div>


    <div class="documents-container">
        <div class="table-header">
            <div class="table-header-left">
                <h3 class="table-title">
                    <i class="fas fa-table"></i>
                    Document Results
                </h3>
                <div class="results-info" id="resultsInfo">
                    Loading documents...
                </div>
            </div>
            <div class="table-actions">
                <?php if ($can_upload): ?>
                <button type="button" class="table-upload-btn" onclick="openUploadModal()">
                    <i class="fas fa-upload"></i>
                    Upload Documents
                </button>
                <?php endif; ?>
                <button type="button" class="table-download-btn" id="tableDownload" onclick="downloadSelected()" disabled>
                    <i class="fas fa-download"></i>
                    Download Selected
                </button>
                <button type="button" class="table-export-csv-btn" id="tableExportCsv" onclick="exportToCSV()">
                    <i class="fas fa-file-csv"></i>
                    Export to CSV
                </button>
                <?php if ($can_upload): ?>
                <button type="button" class="table-delete-btn" id="tableDelete" onclick="deleteSelected()" disabled>
                    <i class="fas fa-trash"></i>
                    Delete Selected
                </button>
                <?php endif; ?>
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
                            <label class="form-label" for="uploadProjectSelect">Project (Optional)</label>
                            <select id="uploadProjectSelect" name="project_id" class="form-select">
                                <option value="">No project association</option>
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
                                <option value="shipments">Shipments</option>
                                <option value="warehousing">Warehousing</option>
                                <option value="modules">Modules</option>
                                <option value="exception_reports">Exception Reports</option>
                                <option value="pictures">Photos</option>
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
     'shipments': ['Arrival Notice', 'Customs Document', 'Delivery SOP', 'Project POD', 'Warehouse POD'],
     'warehousing': ['Warehouse POD', 'Inventory Report', 'Photos', 'Quote'],
     'modules': ['Module Invoice', 'Flash Test Data', 'Spec Sheets'],
     'exception_reports': ['Damage Photo', 'Warranty Document', 'Proof of Completion', 'Safety Incident', 'Project POD', 'Warehouse POD'],
     'photos': ['Project Photo', 'Warehouse Photo', 'Damage Photo'],
     'safe_harbor_evidence': [],
     'other': []
 };

// Update document sub-type filter based on selected document type
function updateDocumentSubTypeFilter() {
    const docType = document.getElementById('documentTypeFilter').value;
    const subTypeGroup = document.getElementById('subTypeFilterGroup');
    const subTypeSelect = document.getElementById('documentSubTypeFilter');
    const warehouseGroup = document.getElementById('warehouseFilterGroup');

    // Reset sub-type select
    subTypeSelect.innerHTML = '<option value="">All Sub-Types</option>';

    // Show/hide sub-type filter based on whether there are sub-filters
    if (docType && subFilters[docType] && subFilters[docType].length > 0) {
        subFilters[docType].forEach(subType => {
            const option = document.createElement('option');
            option.value = subType;
            option.textContent = subType;
            subTypeSelect.appendChild(option);
        });
        subTypeGroup.style.display = 'block';
    } else {
        subTypeGroup.style.display = 'none';
    }

    // Show warehouse filter for warehousing or shipments (for PODs)
    if (docType === 'warehousing' || docType === 'shipments') {
        warehouseGroup.style.display = 'block';
    } else {
        warehouseGroup.style.display = 'none';
        // Reset warehouse selection when hidden
        const warehouseSelect = document.getElementById('warehouseFilterContext');
        if (warehouseSelect) warehouseSelect.value = '';
    }
}

// Load warehouses for filter
async function loadWarehouseFilter() {
    try {
        const response = await fetch('get_warehouses.php');
        const data = await response.json();
        if (data.success) {
            const select = document.getElementById('warehouseFilterContext');
            if (select) {
                data.data.forEach(warehouse => {
                    const option = document.createElement('option');
                    option.value = warehouse.id;
                    option.textContent = warehouse.name || warehouse.location_name;
                    select.appendChild(option);
                });
            }
        }
    } catch (e) {
        console.warn('Failed to load warehouses', e);
    }
}

// Initialize page
document.addEventListener('DOMContentLoaded', function() {
    // Load warehouses
    loadWarehouseFilter();
    
    // Preselect filters from query params (project, folder, subfolder)
    <?php if ($pre_selected_project > 0): ?>
      // Verify the project is in the accessible projects list
      const projectId = '<?php echo $pre_selected_project; ?>';
      const projectFilter = document.getElementById('projectFilter');
      
      // Check if the project option exists in the dropdown
      const projectOption = Array.from(projectFilter.options).find(option => option.value === projectId);
      if (projectOption) {
        projectFilter.value = projectId;
        console.log('Pre-selected project:', projectId, '- <?php echo addslashes($project_name); ?>');
        
        // Auto-apply filters when coming from project overview
        setTimeout(() => {
          console.log('Auto-applying filters for project ID:', projectId);
          applyFilters();
        }, 500);
        
        // Fallback: also trigger on window load if needed
        window.addEventListener('load', () => {
          setTimeout(() => {
            if (!filtersApplied) {
              console.log('Fallback: Auto-applying filters for project ID:', projectId);
              applyFilters();
            }
          }, 100);
        });
      } else {
        console.log('Project ID', projectId, 'not accessible to current user');
      }
    <?php endif; ?>
    <?php if (!empty($pre_selected_document_type)): ?>
      document.getElementById('documentTypeFilter').value = '<?php echo $pre_selected_document_type; ?>';
    <?php endif; ?>


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

async function loadShipFilterOptions() {
    const projectId = document.getElementById('projectFilter').value || '';
    try {
        const mRes = await fetch(`get_account_manufacturers.php${projectId ? `?project_id=${projectId}` : ''}`);
        const mData = await mRes.json();
        const sel = document.getElementById('shipManufacturerSelect');
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
        // Wattages menu is derived from documents post-load
    } catch (e) {
        console.warn('Failed to load Shipments filter options', e);
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

// Shipments: dynamic manufacturer list from docs
function updateShipManufacturersFromDocs(documents) {
    const sel = document.getElementById('shipManufacturerSelect');
    if (!sel) return;
    const current = sel.value;
    const names = new Set();
    documents.forEach(d => {
        if (d.document_type === 'shipments' && d.manufacturer_name) {
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

// Shipments: wattage checkbox menu from docs
function updateShipWattagesFromDocs(documents) {
    const menu = document.getElementById('shipWattageMenu');
    const display = document.getElementById('shipWattageDisplay');
    if (!menu || !display) return;
    const previously = Array.from(menu.querySelectorAll('input[type="checkbox"]:checked')).map(cb => cb.value);
    const set = new Set();
    documents.forEach(d => { if (d.delivery_wattage && d.document_type === 'shipments') set.add(String(d.delivery_wattage)); });
    const sorted = Array.from(set).map(v => parseInt(v, 10)).sort((a,b)=>a-b).map(n => String(n));
    menu.innerHTML = '';
    sorted.forEach(val => {
        const id = `ship_watt_${val}`;
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
            updateShipWattageDisplay();
            if (filtersApplied) loadDocuments();
        });
        const span = document.createElement('span');
        span.textContent = val;
        wrap.appendChild(cb);
        wrap.appendChild(span);
        menu.appendChild(wrap);
    });
    updateShipWattageDisplay();
}

function updateShipWattageDisplay() {
    const display = document.getElementById('shipWattageDisplay');
    const menu = document.getElementById('shipWattageMenu');
    if (!display || !menu) return;
    const selected = Array.from(menu.querySelectorAll('input[type="checkbox"]:checked')).map(cb => cb.value);
    display.value = selected.length ? selected.join(', ') : '';
}

function toggleShipWattageMenu(e) {
    e.stopPropagation();
    const menu = document.getElementById('shipWattageMenu');
    if (!menu) return;
    if (menu.style.display === 'none') {
        const input = document.getElementById('shipWattageDisplay');
        const rect = input.getBoundingClientRect();
        const menuWidth = 260;
        const left = Math.min(rect.left, window.innerWidth - menuWidth - 12);
        menu.style.left = left + 'px';
        menu.style.top = (rect.bottom + window.scrollY) + 'px';
        menu.style.display = 'block';
        document.addEventListener('click', closeShipWattMenuOnOutside);
    } else {
        menu.style.display = 'none';
        document.removeEventListener('click', closeShipWattMenuOnOutside);
    }
}

function closeShipWattMenuOnOutside(e) {
    const menu = document.getElementById('shipWattageMenu');
    const input = document.getElementById('shipWattageDisplay');
    if (!menu || !input) return;
    if (!menu.contains(e.target) && e.target !== input) {
        menu.style.display = 'none';
        document.removeEventListener('click', closeShipWattMenuOnOutside);
    }
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
    const shipStart = document.getElementById('shipClearedStart');
    const shipEnd = document.getElementById('shipClearedEnd');
    const shipCont = document.getElementById('shipContainerNumber');
    const shipMan = document.getElementById('shipManufacturerSelect');
    const whPodsStart = document.getElementById('whPodsDeliveryStart');
    const whPodsEnd = document.getElementById('whPodsDeliveryEnd');
    const whPodsBol = document.getElementById('whPodsBolNumber');
    const whPodsMan = document.getElementById('whPodsManufacturerSelect');
    const whWhSel = document.getElementById('whWarehouseSelect');
    const whManSel = document.getElementById('whManufacturerSelect');
    const modManSel = document.getElementById('modManufacturerSelect');
    const incTicket = document.getElementById('incTicketSelect');
    const incPodsStart = document.getElementById('incPodsDeliveryStart');
    const incPodsEnd = document.getElementById('incPodsDeliveryEnd');
    const incPodsBol = document.getElementById('incPodsBolNumber');
    const incPodsMan = document.getElementById('incPodsManufacturerSelect');
    [podsStart, podsEnd, podsBol, podsMan, invMin, invMax, invStart, invEnd, invBol, invMan, shipStart, shipEnd, shipCont, shipMan, whPodsStart, whPodsEnd, whPodsBol, whPodsMan, whWhSel, whManSel, modManSel, incTicket, incPodsStart, incPodsEnd, incPodsBol, incPodsMan].forEach(el => {
        if (!el) return;
        const evt = (el.tagName === 'SELECT') ? 'change' : 'input';
        el.addEventListener(evt, () => { if (filtersApplied) { currentPage = 1; loadDocuments(); } });
    });
}

function updateInvoicesSubfilterVisibility() {
    // This function is no longer used as context subfilters have been removed
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

// These functions are no longer needed as context subfilters have been removed
// Keeping empty stubs to prevent JS errors if called

async function loadIncidentFilterOptions() {
    const projectId = document.getElementById('projectFilter').value || '';
    try {
        const tRes = await fetch(`get_tickets.php${projectId ? `?project_id=${projectId}` : ''}`);
        const tData = await tRes.json();
        const sel = document.getElementById('incTicketSelect');
        if (sel && tData.success) {
            const current = sel.value;
            sel.innerHTML = '<option value="">All Tickets</option>';
            tData.data.forEach(item => {
                const opt = document.createElement('option');
                opt.value = item.id;
                opt.textContent = item.name;
                sel.appendChild(opt);
            });
            if (current) sel.value = current;
        }
    } catch (e) {
        console.warn('Failed to load tickets for Exception Reports', e);
    }
}

function updateModDynamicFiltersFromDocs(documents) {
    const sel = document.getElementById('modManufacturerSelect');
    if (sel) {
        const cur = sel.value;
        const names = new Set();
        documents.forEach(d => { if (d.manufacturer_name) names.add(String(d.manufacturer_name)); });
        sel.innerHTML = '<option value="">All Manufacturers</option>';
        Array.from(names).sort((a,b)=>a.localeCompare(b)).forEach(name => {
            const opt = document.createElement('option'); opt.value = name; opt.textContent = name; sel.appendChild(opt);
        });
        if (cur && Array.from(names).includes(cur)) sel.value = cur;
    }
    const menu = document.getElementById('modWattageMenu');
    const display = document.getElementById('modWattageDisplay');
    if (menu && display) {
        const prev = Array.from(menu.querySelectorAll('input[type="checkbox"]:checked')).map(cb => cb.value);
        const set = new Set();
        documents.forEach(d => { if (d.module_wattages_label) { d.module_wattages_label.split(',').forEach(x=>{ const v=x.trim(); if (v) set.add(v); }); } else if (d.delivery_wattage) set.add(String(d.delivery_wattage)); });
        const nums = Array.from(set).map(v=>parseInt(v,10)).filter(n=>!isNaN(n)).sort((a,b)=>a-b);
        menu.innerHTML='';
        nums.forEach(val => {
            const wrap = document.createElement('label'); wrap.style.display='flex'; wrap.style.alignItems='center'; wrap.style.gap='8px'; wrap.style.padding='4px 2px';
            const cb = document.createElement('input'); cb.type='checkbox'; cb.value=String(val); if (prev.includes(String(val))) cb.checked=true; cb.addEventListener('change',()=>{ updateModWattageDisplay(); if (filtersApplied) loadDocuments(); });
            const span = document.createElement('span'); span.textContent=String(val);
            wrap.appendChild(cb); wrap.appendChild(span); menu.appendChild(wrap);
        });
        updateModWattageDisplay();
    }
}

function updateModWattageDisplay() {
    const input = document.getElementById('modWattageDisplay');
    const menu = document.getElementById('modWattageMenu');
    if (!input || !menu) return;
    const selected = Array.from(menu.querySelectorAll('input[type="checkbox"]:checked')).map(cb => cb.value);
    input.value = selected.join(', ');
}
function toggleModWattageMenu(e) {
    e.stopPropagation();
    const menu = document.getElementById('modWattageMenu');
    if (!menu) return;
    const input = document.getElementById('modWattageDisplay');
    const rect = input.getBoundingClientRect();
    const menuWidth = 260; const left = Math.min(rect.left, window.innerWidth - menuWidth - 12);
    menu.style.left = left + 'px'; menu.style.top = (rect.bottom + window.scrollY) + 'px';
    menu.style.display = (menu.style.display==='block') ? 'none' : 'block';
    if (menu.style.display==='block') document.addEventListener('click', closeModWattMenuOnOutside);
}
function closeModWattMenuOnOutside(e) {
    const menu = document.getElementById('modWattageMenu'); const input = document.getElementById('modWattageDisplay');
    if (!menu || !input) return;
    if (!menu.contains(e.target) && e.target !== input) { menu.style.display='none'; document.removeEventListener('click', closeModWattMenuOnOutside); }
}
function updateWhDynamicFiltersFromDocs(documents) {
    // Populate warehouse select from docs
    const whSel = document.getElementById('whWarehouseSelect');
    if (whSel) {
        const cur = whSel.value;
        const items = [];
        const seen = new Set();
        documents.forEach(d => {
            if (d.warehouse_id && d.warehouse_name && !seen.has(d.warehouse_id)) {
                seen.add(d.warehouse_id);
                items.push({id: d.warehouse_id, name: d.warehouse_name});
            }
        });
        whSel.innerHTML = '<option value="">All Warehouses</option>';
        items.sort((a,b)=>a.name.localeCompare(b.name)).forEach(it => {
            const opt = document.createElement('option');
            opt.value = it.id;
            opt.textContent = it.name;
            whSel.appendChild(opt);
        });
        if (cur) whSel.value = cur;
    }
    // Manufacturers from docs or suppliers via deliveries
    const manSel = document.getElementById('whManufacturerSelect');
    if (manSel) {
        const cur = manSel.value;
        const names = new Set();
        documents.forEach(d => { if (d.manufacturer_name) names.add(String(d.manufacturer_name)); });
        manSel.innerHTML = '<option value="">All Manufacturers</option>';
        Array.from(names).sort((a,b)=>a.localeCompare(b)).forEach(name => {
            const opt = document.createElement('option');
            opt.value = name;
            opt.textContent = name;
            manSel.appendChild(opt);
        });
        if (cur && Array.from(names).includes(cur)) manSel.value = cur;
    }
    // Wattage menu from docs
    const menu = document.getElementById('whWattageMenu');
    const display = document.getElementById('whWattageDisplay');
    if (menu && display) {
        const prev = Array.from(menu.querySelectorAll('input[type="checkbox"]:checked')).map(cb => cb.value);
        const set = new Set();
        documents.forEach(d => { if (d.delivery_wattage) set.add(String(d.delivery_wattage)); });
        const sorted = Array.from(set).map(v=>parseInt(v,10)).sort((a,b)=>a-b).map(n=>String(n));
        menu.innerHTML='';
        sorted.forEach(val => {
            const wrap = document.createElement('label');
            wrap.style.display='flex';wrap.style.alignItems='center';wrap.style.gap='8px';wrap.style.padding='4px 2px';
            const cb = document.createElement('input');
            cb.type='checkbox'; cb.value=val; if (prev.includes(val)) cb.checked=true;
            cb.addEventListener('change', ()=>{ updateWhWattageDisplay(); if (filtersApplied) loadDocuments(); });
            const span = document.createElement('span'); span.textContent=val;
            wrap.appendChild(cb); wrap.appendChild(span); menu.appendChild(wrap);
        });
        updateWhWattageDisplay();
    }
    // Warehouse POD variant menus
    const pmenu = document.getElementById('whPodsWattageMenu');
    const pdisplay = document.getElementById('whPodsWattageDisplay');
    if (pmenu && pdisplay) {
        const prev = Array.from(pmenu.querySelectorAll('input[type="checkbox"]:checked')).map(cb => cb.value);
        const set = new Set();
        documents.forEach(d => { if (d.delivery_wattage) set.add(String(d.delivery_wattage)); });
        const sorted = Array.from(set).map(v=>parseInt(v,10)).sort((a,b)=>a-b).map(n=>String(n));
        pmenu.innerHTML='';
        sorted.forEach(val => {
            const wrap = document.createElement('label');
            wrap.style.display='flex';wrap.style.alignItems='center';wrap.style.gap='8px';wrap.style.padding='4px 2px';
            const cb = document.createElement('input');
            cb.type='checkbox'; cb.value=val; if (prev.includes(val)) cb.checked=true;
            cb.addEventListener('change', ()=>{ updateWhPodsWattageDisplay(); if (filtersApplied) loadDocuments(); });
            const span = document.createElement('span'); span.textContent=val;
            wrap.appendChild(cb); wrap.appendChild(span); pmenu.appendChild(wrap);
        });
        updateWhPodsWattageDisplay();
    }
}

function updateWhWattageDisplay() {
    const input = document.getElementById('whWattageDisplay');
    const menu = document.getElementById('whWattageMenu');
    if (!input || !menu) return;
    const selected = Array.from(menu.querySelectorAll('input[type="checkbox"]:checked')).map(cb => cb.value);
    input.value = selected.join(', ');
}
function updateWhPodsWattageDisplay() {
    const input = document.getElementById('whPodsWattageDisplay');
    const menu = document.getElementById('whPodsWattageMenu');
    if (!input || !menu) return;
    const selected = Array.from(menu.querySelectorAll('input[type="checkbox"]:checked')).map(cb => cb.value);
    input.value = selected.join(', ');
}
function toggleWhWattageMenu(e) {
    e.stopPropagation();
    const menu = document.getElementById('whWattageMenu');
    if (!menu) return;
    const input = document.getElementById('whWattageDisplay');
    const rect = input.getBoundingClientRect();
    const menuWidth = 260;
    const left = Math.min(rect.left, window.innerWidth - menuWidth - 12);
    menu.style.left = left + 'px';
    menu.style.top = (rect.bottom + window.scrollY) + 'px';
    menu.style.display = (menu.style.display==='block') ? 'none' : 'block';
    if (menu.style.display==='block') document.addEventListener('click', closeWhWattMenuOnOutside);
}
function closeWhWattMenuOnOutside(e) {
    const menu = document.getElementById('whWattageMenu');
    const input = document.getElementById('whWattageDisplay');
    if (!menu || !input) return;
    if (!menu.contains(e.target) && e.target !== input) {
        menu.style.display='none';
        document.removeEventListener('click', closeWhWattMenuOnOutside);
    }
}
function toggleWhPodsWattageMenu(e) {
    e.stopPropagation();
    const menu = document.getElementById('whPodsWattageMenu');
    if (!menu) return;
    const input = document.getElementById('whPodsWattageDisplay');
    const rect = input.getBoundingClientRect();
    const menuWidth = 260;
    const left = Math.min(rect.left, window.innerWidth - menuWidth - 12);
    menu.style.left = left + 'px';
    menu.style.top = (rect.bottom + window.scrollY) + 'px';
    menu.style.display = (menu.style.display==='block') ? 'none' : 'block';
    if (menu.style.display==='block') document.addEventListener('click', closeWhPodsWattMenuOnOutside);
}
function closeWhPodsWattMenuOnOutside(e) {
    const menu = document.getElementById('whPodsWattageMenu');
    const input = document.getElementById('whPodsWattageDisplay');
    if (!menu || !input) return;
    if (!menu.contains(e.target) && e.target !== input) {
        menu.style.display='none';
        document.removeEventListener('click', closeWhPodsWattMenuOnOutside);
    }
}

// Clear all filters
function clearAllFilters() {
    document.getElementById('projectFilter').value = '';
    document.getElementById('documentTypeFilter').value = '';
    document.getElementById('startDate').value = '';
    document.getElementById('endDate').value = '';
    document.getElementById('searchFilter').value = '';

    // Clear sub-type filter and hide it
    const subTypeSelect = document.getElementById('documentSubTypeFilter');
    if (subTypeSelect) {
        subTypeSelect.value = '';
        subTypeSelect.innerHTML = '<option value="">All Sub-Types</option>';
    }
    document.getElementById('subTypeFilterGroup').style.display = 'none';

    // Clear warehouse filter and hide it
    const warehouseSelect = document.getElementById('warehouseFilterContext');
    if (warehouseSelect) warehouseSelect.value = '';
    document.getElementById('warehouseFilterGroup').style.display = 'none';

    // Clear remaining filter field values (context subfilters have been removed)
    const clearIfExists = (id) => { const el = document.getElementById(id); if (el && el.type === 'checkbox') el.checked = false; else if (el) el.value = ''; };

    // Clear any leftover filter values if they exist
    ['podsDeliveryStart', 'podsDeliveryEnd', 'podsBolNumber', 'podsManufacturerSelect', 'podsWattageSelect',
     'shipClearedStart', 'shipClearedEnd', 'shipContainerNumber', 'shipManufacturerSelect',
     'whPodsDeliveryStart', 'whPodsDeliveryEnd', 'whPodsBolNumber', 'whPodsManufacturerSelect',
     'whWarehouseSelect', 'whManufacturerSelect', 'modManufacturerSelect'].forEach(clearIfExists);

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
        const warehouseFilterEl = document.getElementById('warehouseFilterContext');
        const subTypeFilterEl = document.getElementById('documentSubTypeFilter');
        const filters = {
            project_id: document.getElementById('projectFilter').value,
            document_type: document.getElementById('documentTypeFilter').value,
            document_sub_type: subTypeFilterEl ? subTypeFilterEl.value : '',
            warehouse_id: warehouseFilterEl ? warehouseFilterEl.value : '',
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

        // Shipments context filters
        const shipStart = document.getElementById('shipClearedStart');
        const shipEnd = document.getElementById('shipClearedEnd');
        const shipCont = document.getElementById('shipContainerNumber');
        const shipManSel = document.getElementById('shipManufacturerSelect');
        const shipWattMenu = document.getElementById('shipWattageMenu');
        if (shipStart && shipStart.value) filters.ship_cleared_start = shipStart.value;
        if (shipEnd && shipEnd.value) filters.ship_cleared_end = shipEnd.value;
        if (shipCont && shipCont.value) filters.ship_container = shipCont.value;
        if (shipManSel && shipManSel.value) filters.ship_supplier = shipManSel.value;
        if (shipWattMenu) {
            const watts = Array.from(shipWattMenu.querySelectorAll('input[type="checkbox"]:checked')).map(cb => cb.value);
            if (watts.length) filters.ship_wattages = watts;
        }

        // Warehousing context filters
        const selectedChips = Array.from(document.querySelectorAll('#subFilterOptions .sub-filter-option.selected')).map(el => el.textContent.trim());
        const docTypeVal = document.getElementById('documentTypeFilter').value;
        const whIsPods = (docTypeVal === 'warehousing' && selectedChips.includes('Warehouse POD'));
        const whIsBasic = (docTypeVal === 'warehousing' && (selectedChips.includes('Inventory Report') || selectedChips.includes('Photos')));
        if (whIsPods) {
            const s = document.getElementById('whPodsDeliveryStart');
            const e = document.getElementById('whPodsDeliveryEnd');
            const b = document.getElementById('whPodsBolNumber');
            const m = document.getElementById('whPodsManufacturerSelect');
            const wmenu = document.getElementById('whPodsWattageMenu');
            if (s && s.value) filters.wh_delivery_start = s.value;
            if (e && e.value) filters.wh_delivery_end = e.value;
            if (b && b.value) filters.wh_bol = b.value;
            if (m && m.value) filters.wh_supplier = m.value;
            if (wmenu) {
                const watts = Array.from(wmenu.querySelectorAll('input[type="checkbox"]:checked')).map(cb => cb.value);
                if (watts.length) filters.wh_wattages = watts;
            }
        }
        if (whIsBasic) {
            const whSel = document.getElementById('whWarehouseSelect');
            const manSel = document.getElementById('whManufacturerSelect');
            const wmenu = document.getElementById('whWattageMenu');
            if (whSel && whSel.value) filters.wh_warehouse_id = whSel.value;
            if (manSel && manSel.value) filters.wh_supplier = manSel.value;
            if (wmenu) {
                const watts = Array.from(wmenu.querySelectorAll('input[type="checkbox"]:checked')).map(cb => cb.value);
                if (watts.length) filters.wh_wattages = watts;
            }
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

        // Exception Reports context filters
        const incChips = Array.from(document.querySelectorAll('#subFilterOptions .sub-filter-option.selected')).map(el => el.textContent.trim());
        const isInc = document.getElementById('documentTypeFilter').value === 'exception_reports';
        if (isInc) {
            const t = document.getElementById('incTicketSelect');
            if (t && t.value) filters.inc_ticket_id = t.value;
            if (incChips.includes('Project POD') || incChips.includes('Warehouse POD')) {
                const s = document.getElementById('incPodsDeliveryStart');
                const e = document.getElementById('incPodsDeliveryEnd');
                const b = document.getElementById('incPodsBolNumber');
                const m = document.getElementById('incPodsManufacturerSelect');
                const wmenu = document.getElementById('incPodsWattageMenu');
                if (s && s.value) filters.inc_delivery_start = s.value;
                if (e && e.value) filters.inc_delivery_end = e.value;
                if (b && b.value) filters.inc_bol = b.value;
                if (m && m.value) filters.inc_supplier = m.value;
                if (wmenu) {
                    const watts = Array.from(wmenu.querySelectorAll('input[type=\"checkbox\"]:checked')).map(cb => cb.value);
                    if (watts.length) filters.inc_wattages = watts;
                }
            }
        }

        // Modules context filters (Flash Test Data, Spec Sheets)
        const modChips = Array.from(document.querySelectorAll('#subFilterOptions .sub-filter-option.selected')).map(el => el.textContent.trim());
        const isModules = document.getElementById('documentTypeFilter').value === 'modules';
        const modBasic = isModules && (modChips.includes('Flash Test Data') || modChips.includes('Spec Sheets'));
        const modManSel = document.getElementById('modManufacturerSelect');
        const modWattMenu = document.getElementById('modWattageMenu');
        if (modBasic) {
            if (modManSel && modManSel.value) filters.mod_supplier = modManSel.value;
            if (modWattMenu) {
                const watts = Array.from(modWattMenu.querySelectorAll('input[type="checkbox"]:checked')).map(cb => cb.value);
                if (watts.length) filters.mod_wattages = watts;
            }
        }
        
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
            } else if (filtersApplied && docType === 'shipments') {
                updateShipManufacturersFromDocs(data.documents);
                updateShipWattagesFromDocs(data.documents);
            } else if (filtersApplied && docType === 'warehousing') {
                updateWhDynamicFiltersFromDocs(data.documents);
            } else if (filtersApplied && docType === 'modules') {
                updateModDynamicFiltersFromDocs(data.documents);
            }
            // Context subfilters have been removed - no-op
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
    const showShipCols = (filtersApplied && docType === 'shipments');
    // Sub-filters have been removed, so chips array is always empty
    const chips = [];
    const showInvMan = false;
    const showModBasicCols = false;
    const showIncTicket = (filtersApplied && docType === 'exception_reports');
    const showWhPodsCols = false;
    const showWhBasicCols = false;
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
                    ${showShipCols ? '<th class="sortable" data-sort="container">Container</th>' : ''}
                    ${showShipCols ? '<th class="sortable" data-sort="manufacturer">Manufacturer</th>' : ''}
                    ${showShipCols ? '<th class="sortable" data-sort="wattage">Wattage</th>' : ''}
                    ${showShipCols ? '<th class="sortable" data-sort="cleared">Cleared Customs</th>' : ''}
                    ${showInvCols ? '<th class="sortable" data-sort="inv_amount">Invoice Total</th>' : ''}
                    ${showInvCols ? '<th class="sortable" data-sort="inv_date">Invoice Date</th>' : ''}
                    ${showInvCols ? '<th class="sortable" data-sort="inv_due">Due Date</th>' : ''}
                    ${showModBasicCols ? '<th class="sortable" data-sort="manufacturer">Manufacturer</th>' : ''}
                    ${showModBasicCols ? '<th class="sortable" data-sort="wattage">Wattage</th>' : ''}
                    ${showWhPodsCols ? '<th class="sortable" data-sort="bol">BOL</th>' : ''}
                    ${showWhPodsCols ? '<th class="sortable" data-sort="manufacturer">Manufacturer</th>' : ''}
                    ${showWhPodsCols ? '<th class="sortable" data-sort="wattage">Wattage</th>' : ''}
                    ${showWhPodsCols ? '<th class="sortable" data-sort="delivered">Delivered</th>' : ''}
                    ${showWhBasicCols ? '<th class="sortable" data-sort="warehouse">Warehouse</th>' : ''}
                    ${showWhBasicCols ? '<th class="sortable" data-sort="manufacturer">Manufacturer</th>' : ''}
                    ${showWhBasicCols ? '<th class="sortable" data-sort="wattage">Wattage</th>' : ''}
                    ${showIncTicket ? '<th class="sortable" data-sort="ticket">Ticket</th>' : ''}
                    <th class="sortable" data-sort="size">Size</th>
                    <th class="sortable" data-sort="uploaded">Uploaded</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                ${documents.map(doc => renderDocumentRow(doc, showPodsCols, showInvCols, showInvMan, showShipCols, showWhPodsCols, showWhBasicCols, showModBasicCols, undefined, showIncTicket)).join('')}
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
            case 'container': {
                const A = (a.container_number || '').toLowerCase();
                const B = (b.container_number || '').toLowerCase();
                return A > B ? dir : A < B ? -dir : 0;
            }
            case 'cleared': {
                const A = new Date(a.customs_cleared_date || 0).getTime();
                const B = new Date(b.customs_cleared_date || 0).getTime();
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
            case 'warehouse': {
                const A = (a.warehouse_name || '').toLowerCase();
                const B = (b.warehouse_name || '').toLowerCase();
                return A > B ? dir : A < B ? -dir : 0;
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
function renderDocumentRow(doc, showPodsCols = false, showInvCols = false, showInvMan = false, showShipCols = false, showWhPodsCols = false, showWhBasicCols = false, showModBasicCols = false, showIncPodsCols = false, showIncTicket = false) {
    const isSelected = selectedDocuments.has(doc.id);
    const iconStyle = `background: ${getDocumentTypeColor(doc.document_type)}; position: relative;`;
    const ticketMatch = doc.entity_context ? /incident_ticket_id:(\d+)/.exec(doc.entity_context) : null;
    const incidentTicket = ticketMatch ? ticketMatch[1] : '';
    
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
                         ${doc.is_safe_harbor ? `<span title="Safe Harbor" style="position:absolute;bottom:-6px;right:-6px;background:#6366f1;color:#fff;border-radius:50%;width:18px;height:18px;display:flex;align-items:center;justify-content:center;border:2px solid #fff;font-size:10px;"><i class='fas fa-gavel'></i></span>` : ''}
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
            ${showShipCols ? `<td>${doc.container_number ? escapeHtml(doc.container_number) : ''}</td>` : ''}
            ${showShipCols ? `<td>${doc.manufacturer_name ? escapeHtml(doc.manufacturer_name) : ''}</td>` : ''}
            ${showShipCols ? `<td>${doc.delivery_wattage ? escapeHtml(String(doc.delivery_wattage)) : ''}</td>` : ''}
            ${showShipCols ? `<td>${doc.customs_cleared_date ? formatDate(doc.customs_cleared_date) : ''}</td>` : ''}
            ${showWhPodsCols ? `<td>${doc.bol_number ? escapeHtml(doc.bol_number) : ''}</td>` : ''}
            ${showWhPodsCols ? `<td>${doc.manufacturer_name ? escapeHtml(doc.manufacturer_name) : ''}</td>` : ''}
            ${showWhPodsCols ? `<td>${doc.delivery_wattage ? escapeHtml(String(doc.delivery_wattage)) : ''}</td>` : ''}
            ${showWhPodsCols ? `<td>${doc.actual_delivery_date ? formatDate(doc.actual_delivery_date) : ''}</td>` : ''}
            ${showWhBasicCols ? `<td>${doc.warehouse_name ? escapeHtml(doc.warehouse_name) : ''}</td>` : ''}
            ${showWhBasicCols ? `<td>${doc.manufacturer_name ? escapeHtml(doc.manufacturer_name) : ''}</td>` : ''}
            ${showWhBasicCols ? `<td>${doc.delivery_wattage ? escapeHtml(String(doc.delivery_wattage)) : ''}</td>` : ''}
            ${showIncPodsCols ? `<td>${doc.bol_number ? escapeHtml(doc.bol_number) : ''}</td>` : ''}
            ${showIncPodsCols ? `<td>${doc.manufacturer_name ? escapeHtml(doc.manufacturer_name) : ''}</td>` : ''}
            ${showIncPodsCols ? `<td>${doc.delivery_wattage ? escapeHtml(String(doc.delivery_wattage)) : ''}</td>` : ''}
            ${showIncPodsCols ? `<td>${doc.actual_delivery_date ? formatDate(doc.actual_delivery_date) : ''}</td>` : ''}
            ${showIncTicket ? `<td>${incidentTicket}</td>` : ''}
            ${showModBasicCols ? `<td>${doc.manufacturer_name ? escapeHtml(doc.manufacturer_name) : ''}</td>` : ''}
            ${showModBasicCols ? `<td>${doc.module_wattages_label ? escapeHtml(doc.module_wattages_label) : (doc.delivery_wattage ? escapeHtml(String(doc.delivery_wattage)) : '')}</td>` : ''}
            <td>${doc.size}</td>
            <td>${formatDate(doc.uploaded_at)}</td>
             <td>
                 <div class="action-buttons">
                     <a href="download_document.php?id=${doc.id}" class="btn-download" target="_blank">
                         <i class="fas fa-download"></i>
                         Download
                     </a>
                     ${doc.delivery_id ? `<a href="view_project.php?project_id=${doc.project_id}&delivery_id=${doc.delivery_id}" class="btn-details" title="View delivery details">
                         <i class=\"fas fa-eye\"></i>
                         Details
                     </a>` : ''}
                     ${(doc.document_type === 'exception_reports' && (doc.entity_context||'').includes('incident_ticket_id:')) ? (()=>{ try { const m=(doc.entity_context||'').match(/incident_ticket_id:(\d+)/); return m?`<a href=\"warranty_detail.php?id=${m[1]}\" class=\"btn-ticket\" title=\"View ticket\"><i class=\"fas fa-eye\"></i> Ticket</a>`:'';} catch(e){ return ''; } })() : ''}
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
    
    // Enable/disable bulk download and delete buttons
    const bulkDownloadBtn = document.getElementById('tableDownload');
    const bulkDeleteBtn = document.getElementById('tableDelete');
    
    if (bulkDownloadBtn) {
        bulkDownloadBtn.disabled = selectedDocuments.size === 0;
    }
    
    if (bulkDeleteBtn) {
        bulkDeleteBtn.disabled = selectedDocuments.size === 0;
    }
    
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

// Export all filtered documents to CSV
async function exportToCSV() {
    try {
        // Collect current filters from the form
        const filters = {
            project: document.getElementById('projectFilter').value || '',
            document_type: document.getElementById('documentTypeFilter').value || '',
            search: document.getElementById('searchFilter').value || '',
            start_date: document.getElementById('startDate').value || '',
            end_date: document.getElementById('endDate').value || '',
            warehouse: document.getElementById('warehouseFilter')?.value || '',
            manufacturer: document.getElementById('manufacturerFilter')?.value || '',
            wattage: Array.from(document.querySelectorAll('#wattageMenu input[type="checkbox"]:checked')).map(cb => cb.value),
            delivery_date_start: document.getElementById('deliveryDateStart')?.value || '',
            delivery_date_end: document.getElementById('deliveryDateEnd')?.value || '',
            bol_number: document.getElementById('bolNumberFilter')?.value || ''
        };
        
        const response = await fetch('export_documents_csv.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify(filters)
        });
        
        if (response.ok) {
            const blob = await response.blob();
            const url = window.URL.createObjectURL(blob);
            const link = document.createElement('a');
            link.style.display = 'none';
            link.href = url;
            const dateStr = new Date().toISOString().split('T')[0];
            link.download = `documents_export_${dateStr}.csv`;
            document.body.appendChild(link);
            link.click();
            window.URL.revokeObjectURL(url);
            document.body.removeChild(link);
        } else {
            const errorData = await response.json();
            alert(errorData.message || 'Failed to export documents');
        }
    } catch (error) {
        console.error('Error exporting documents:', error);
        alert('Network error occurred while exporting documents');
    }
}

// Delete selected documents
async function deleteSelected() {
    if (selectedDocuments.size === 0) {
        alert('Please select documents to delete');
        return;
    }
    
    const count = selectedDocuments.size;
    const confirmMessage = count === 1 
        ? 'Are you sure you want to delete this document? This action cannot be undone.'
        : `Are you sure you want to delete ${count} documents? This action cannot be undone.`;
        
    if (!confirm(confirmMessage)) {
        return;
    }
    
    try {
        const documentIds = Array.from(selectedDocuments);
        const response = await fetch('delete_project_documents.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({ ids: documentIds })
        });
        
        const result = await response.json();
        
        if (result.success) {
            // Clear selection
            selectedDocuments.clear();
            
            // Show success message
            alert(count === 1 ? 'Document deleted successfully' : `${count} documents deleted successfully`);
            
            // Reload documents to reflect changes
            await loadDocuments();
        } else {
            alert(result.message || 'Failed to delete documents');
        }
    } catch (error) {
        console.error('Error deleting documents:', error);
        alert('Network error occurred while deleting documents');
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
        'sub_types': ['Arrival Notice', 'Customs Document', 'Delivery SOP', 'Project POD', 'Warehouse POD'],
        'fields': {
            'Arrival Notice': [
                {type: 'container_autocomplete', name: 'container_number', label: 'Container Number', required: true}
            ],
            'Customs Document': [
                {type: 'container_autocomplete', name: 'container_number', label: 'Container Number', required: true}
            ],
            'Delivery SOP': [
                // No additional fields required for Delivery SOP - only description
            ]
            ,
            'Project POD': [
                {type: 'bol_autocomplete', name: 'delivery_id', label: 'BOL/Delivery', required: true}
            ],
            'Warehouse POD': [
                {type: 'bol_autocomplete', name: 'delivery_id', label: 'BOL/Delivery', required: true},
                {type: 'select', name: 'warehouse_id', label: 'Warehouse', required: true, data_source: 'warehouses'}
            ]
        }
    },
    'warehousing': {
        'sub_types': ['Warehouse POD', 'Inventory Report', 'Photos', 'Quote'],
        'fields': {
            'Warehouse POD': [
                {type: 'bol_autocomplete', name: 'delivery_id', label: 'BOL/Delivery', required: true},
                {type: 'select', name: 'warehouse_id', label: 'Warehouse', required: true, data_source: 'warehouses'}
            ],
            'Inventory Report': [
                {type: 'select', name: 'warehouse_id', label: 'Warehouse', required: true, data_source: 'warehouses'}
            ],
            'Photos': [
                {type: 'select', name: 'warehouse_id', label: 'Warehouse', required: true, data_source: 'warehouses'}
            ],
            'Quote': [
                {type: 'select', name: 'warehouse_id', label: 'Warehouse', required: false, data_source: 'warehouses'}
            ]
        }
    },
    'modules': {
        'sub_types': ['Module Invoice', 'Flash Test Data', 'Spec Sheets'],
        'fields': {
            'Module Invoice': [
                {type: 'select', name: 'manufacturer_id', label: 'Manufacturer', required: true, data_source: 'manufacturers'},
                {type: 'number', name: 'invoice_total', label: 'Invoice Total ($)', required: false, step: '0.01'},
                {type: 'date', name: 'invoice_date', label: 'Invoice Date', required: false}
            ],
            'Flash Test Data': [
                {type: 'select', name: 'module_id', label: 'Module Batch #', required: true, data_source: 'module_batches'}
            ],
            'Spec Sheets': [
                {type: 'select', name: 'module_id', label: 'Module Batch #', required: true, data_source: 'module_batches'}
            ]
        }
    },
    'pictures': {
        'sub_types': ['Project Photo', 'Warehouse Photo', 'Damage Photo'],
        'fields': {
            'Project Photo': [],
            'Warehouse Photo': [
                {type: 'select', name: 'warehouse_id', label: 'Warehouse', required: true, data_source: 'warehouses'}
            ],
            'Damage Photo': [
                {type: 'select', name: 'ticket_id', label: 'Ticket Number', required: true, data_source: 'tickets'}
            ]
        }
    },
    'exception_reports': {
        'sub_types': ['Damage Photo', 'Warranty Document', 'Project POD', 'Warehouse POD'],
        'fields': {
            'Damage Photo': [
                {type: 'select', name: 'ticket_id', label: 'Ticket Number', required: true, data_source: 'tickets'}
            ],
            'Warranty Document': [
                {type: 'select', name: 'ticket_id', label: 'Ticket Number', required: true, data_source: 'tickets'}
            ],
            'Project POD': [
                {type: 'select', name: 'ticket_id', label: 'Ticket Number', required: true, data_source: 'tickets'},
                {type: 'bol_autocomplete', name: 'delivery_id', label: 'BOL/Delivery', required: true}
            ],
            'Warehouse POD': [
                {type: 'select', name: 'ticket_id', label: 'Ticket Number', required: true, data_source: 'tickets'},
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
        } else if (field.type === 'container_autocomplete') {
            html += `<div class="bol-autocomplete-container">
                <input type="text" id="${field.name}" name="${field.name}" class="form-input" 
                    ${field.required ? 'required' : ''} 
                    placeholder="Type container number..." 
                    autocomplete="off"
                    oninput="handleContainerAutocomplete(this, '${field.name}')"
                    onfocus="showContainerSuggestions('${field.name}')"
                    onblur="hideContainerSuggestions('${field.name}')">
                <div id="${field.name}_suggestions" class="bol-suggestions"></div>
                <div id="${field.name}_validation" class="bol-validation" style="display: none;"></div>
            </div>`;
        } else if (field.type === 'select' && field.data_source) {
            html += `<select id="${field.name}" name="${field.name}" class="form-select" ${field.required ? 'required' : ''} onchange="validateForm()">
                <option value="">Choose ${field.label.toLowerCase()}...</option>
            </select>`;
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

    // Populate select data sources after DOM insertion
    for (const field of fields) {
        if (field.type === 'select' && field.data_source) {
            try {
                const response = await fetch(`get_${field.data_source}.php?project_id=${document.getElementById('uploadProjectSelect').value}`);
                const data = await response.json();
                if (data.success) {
                    const selectElement = document.getElementById(field.name);
                    if (!selectElement) continue;
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
        }
    }

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
    let hasWarehouseId = false;
    if (documentType && subType && documentConfig[documentType]?.fields?.[subType]) {
        const fields = documentConfig[documentType].fields[subType];
        fields.forEach(field => {
            if (field.name === 'warehouse_id') {
                const warehouseEl = document.getElementById(field.name);
                if (warehouseEl && warehouseEl.value) {
                    hasWarehouseId = true;
                }
            }
            if (field.required) {
                let element;
                if (field.type === 'bol_autocomplete') {
                    // For BOL autocomplete, check the hidden field
                    element = document.getElementById(field.name + '_hidden');
                } else if (field.type === 'container_autocomplete') {
                    element = document.getElementById(field.name);
                } else {
                    element = document.getElementById(field.name);
                }
                if (element && !element.value) {
                    requiredFieldsValid = false;
                }
            }
        });
    }
    
    // Allow upload if either project_id OR warehouse_id is provided
    const hasValidContext = projectId || hasWarehouseId;
    const isValid = hasValidContext && documentType && subType && hasFiles && requiredFieldsValid;
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

// Container Autocomplete Functions (Shipments)
let containerSearchTimeout;
let currentContainerSuggestions = {};

async function handleContainerAutocomplete(input, fieldName) {
    const query = input.value.trim();
    const validationDiv = document.getElementById(fieldName + '_validation');
    
    if (containerSearchTimeout) clearTimeout(containerSearchTimeout);
    if (query.length === 0) {
        hideContainerSuggestions(fieldName);
        if (validationDiv) validationDiv.style.display = 'none';
        validateForm();
        return;
    }
    if (validationDiv) {
        validationDiv.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Searching...';
        validationDiv.className = 'bol-validation searching';
        validationDiv.style.display = 'flex';
    }

    containerSearchTimeout = setTimeout(async () => {
        try {
            const projectId = document.getElementById('uploadProjectSelect').value;
            const response = await fetch(`search_container.php?project_id=${projectId}&q=${encodeURIComponent(query)}`);
            const data = await response.json();
            if (data.success) {
                currentContainerSuggestions[fieldName] = data.deliveries;
                showContainerSuggestions(fieldName, data.deliveries, query);
                const exactMatch = data.deliveries.find(d => d.container_number && d.container_number.toLowerCase() === query.toLowerCase());
                if (validationDiv) {
                    if (exactMatch) {
                        validationDiv.innerHTML = '<i class="fas fa-check-circle"></i> Container found';
                        validationDiv.className = 'bol-validation valid';
                    } else if (data.deliveries.length > 0) {
                        validationDiv.innerHTML = '<i class="fas fa-search"></i> Similar containers found - select one';
                        validationDiv.className = 'bol-validation searching';
                    } else {
                        validationDiv.innerHTML = '<i class="fas fa-exclamation-circle"></i> Container not found';
                        validationDiv.className = 'bol-validation invalid';
                    }
                }
            } else {
                if (validationDiv) {
                    validationDiv.innerHTML = '<i class="fas fa-exclamation-triangle"></i> Search error';
                    validationDiv.className = 'bol-validation invalid';
                }
            }
        } catch (err) {
            console.error('Container search error:', err);
            if (validationDiv) {
                validationDiv.innerHTML = '<i class="fas fa-exclamation-triangle"></i> Search error';
                validationDiv.className = 'bol-validation invalid';
            }
        }
        validateForm();
    }, 300);
}

function showContainerSuggestions(fieldName, suggestions = null) {
    const suggestionsDiv = document.getElementById(fieldName + '_suggestions');
    if (!suggestions) suggestions = currentContainerSuggestions[fieldName] || [];
    if (!suggestionsDiv) return;
    if (suggestions.length === 0) { suggestionsDiv.style.display = 'none'; return; }
    let html = '';
    suggestions.forEach(del => {
        const label = del.container_number || `Delivery #${del.id}`;
        const details = [];
        if (del.supplier) details.push(del.supplier);
        if (del.status) details.push(del.status);
        if (del.warehouse_name) details.push(`→ ${del.warehouse_name}`);
        html += `
            <div class="bol-suggestion" onclick="selectContainerSuggestion('${fieldName}', '${(del.container_number||'').replace(/'/g, "\\'")}')">
                <div class="bol-suggestion-main">${escapeHtml(label)}</div>
                ${details.length ? `<div class=\"bol-suggestion-details\">${escapeHtml(details.join(' • '))}</div>` : ''}
            </div>
        `;
    });
    suggestionsDiv.innerHTML = html;
    suggestionsDiv.style.display = 'block';
}

function selectContainerSuggestion(fieldName, containerNumber) {
    const input = document.getElementById(fieldName);
    const validationDiv = document.getElementById(fieldName + '_validation');
    input.value = containerNumber;
    if (validationDiv) {
        validationDiv.innerHTML = '<i class="fas fa-check-circle"></i> Container selected';
        validationDiv.className = 'bol-validation valid';
        validationDiv.style.display = 'flex';
    }
    hideContainerSuggestions(fieldName);
    validateForm();
}

function hideContainerSuggestions(fieldName) {
    setTimeout(() => {
        const suggestionsDiv = document.getElementById(fieldName + '_suggestions');
        if (suggestionsDiv) suggestionsDiv.style.display = 'none';
    }, 150);
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
