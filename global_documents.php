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
        'sub_filters' => ['Warehouse PODs', 'Project PODs']
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
                <button type="button" class="apply-filters" onclick="loadDocuments()">
                    <i class="fas fa-search"></i>
                    Apply Filters
                </button>
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
</main>

<script>
// Global variables
let currentPage = 1;
let pageSize = 100;
let totalDocuments = 0;
let selectedDocuments = new Set();
let allDocuments = [];

// Document type sub-filters
const subFilters = {
    'invoices': ['Solterra Invoices', 'OEM Invoices', 'Warehouse Invoices'],
    'pods': ['Warehouse PODs', 'Project PODs']
};

// Initialize page
document.addEventListener('DOMContentLoaded', function() {
    loadDocuments();
    
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
    
    // Reset pagination
    currentPage = 1;
    
    // Reload documents
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
    
    const tableHTML = `
        <table class="documents-table">
            <thead>
                <tr>
                    <th>
                        <input type="checkbox" id="selectAll" onchange="toggleSelectAll(this)">
                    </th>
                    <th>Document</th>
                    <th>Type</th>
                    <th>Project</th>
                    <th>Size</th>
                    <th>Uploaded</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                ${documents.map(doc => renderDocumentRow(doc)).join('')}
            </tbody>
        </table>
    `;
    
    container.innerHTML = tableHTML;
}

// Render individual document row
function renderDocumentRow(doc) {
    const isSelected = selectedDocuments.has(doc.id);
    const iconStyle = `background: ${getDocumentTypeColor(doc.document_type)};`;
    
    return `
        <tr onclick="toggleDocumentSelection(${doc.id}, event)" data-doc-id="${doc.id}">
            <td>
                <input type="checkbox" class="document-checkbox" ${isSelected ? 'checked' : ''} 
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
                        </div>
                    </div>
                </div>
            </td>
            <td>
                <span class="document-type-badge">${getDocumentTypeName(doc.document_type)}</span>
            </td>
            <td>
                <a href="project_documents.php?project_id=${doc.project_id}" class="project-link">
                    ${escapeHtml(doc.project_name)}
                </a>
            </td>
            <td>${doc.size}</td>
            <td>${formatDate(doc.uploaded_at)}</td>
            <td>
                <div class="action-buttons">
                    <a href="download_document.php?id=${doc.id}" class="btn-download" target="_blank">
                        <i class="fas fa-download"></i>
                        Download
                    </a>
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

function showError(message) {
    document.getElementById('documentsTableContainer').innerHTML = `
        <div class="empty-state">
            <i class="fas fa-exclamation-triangle"></i>
            <h3>Error</h3>
            <p>${escapeHtml(message)}</p>
        </div>
    `;
}
</script>
</body>
</html>

<?php
$conn->close();
?>
