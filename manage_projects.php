<?php
session_name("logistics_session");
session_start();

// Ensure the user is either an admin or global_admin
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'global_admin'])) {
    header("Location: unauthorized");
    exit();
}

// Database connection
require_once '../config.php';
$conn = getDBConnection();
if (!$conn) {
    die("Connection failed");
}

$user_id = $_SESSION['user_id'];
$role    = $_SESSION['role'];

$projects = [];
$errorMessage = '';
$successMessage = '';

// Note: Delete handling is now done via AJAX endpoints for better user experience

// --- Fetch Projects with Enhanced Data --- 
$sqlProjects        = "";
$paramTypesProjects = "";
$paramsProjects     = [];
$account_id_for_admin = null; // Define it here for potential reuse

// If the user is global_admin, they can see all projects
if ($role === 'global_admin') {
    $sqlProjects = "
        SELECT 
            p.id,
            p.project_name,
            c.name AS account_name,
            p.estimated_completion_date,
            p.project_address,
            p.street_address,
            p.city,
            p.state,
            p.zip_code,
            COALESCE((
                SELECT SUM(d2.wattage * d2.quantity)
                FROM deliveries d2
                WHERE d2.project_id = p.id
                  AND (d2.status_of_delivery IS NULL OR d2.status_of_delivery <> 'Canceled')
            ), 0) AS project_size,
            COALESCE((
                SELECT COUNT(*)
                FROM deliveries d3
                WHERE d3.project_id = p.id
                  AND (d3.status_of_delivery IS NULL OR d3.status_of_delivery <> 'Canceled')
            ), 0) AS delivery_count,
            (
                SELECT COUNT(*) FROM inventory_pallets ip 
                LEFT JOIN unassigned_module_items umi ON ip.unassigned_module_item_id = umi.id
                LEFT JOIN modules m ON umi.unassigned_module_id = m.id
                WHERE (m.project_id = p.id OR ip.assigned_project_id = p.id OR ip.current_project_id = p.id)
                  AND ip.status = 'Delivered to Project'
            ) AS delivered_pallets,
            (
                SELECT COUNT(*) FROM inventory_pallets ip 
                LEFT JOIN unassigned_module_items umi ON ip.unassigned_module_item_id = umi.id
                LEFT JOIN modules m ON umi.unassigned_module_id = m.id
                WHERE (m.project_id = p.id OR ip.assigned_project_id = p.id OR ip.current_project_id = p.id)
            ) AS total_pallets
        FROM projects p
        JOIN customer_accounts c ON p.account_id = c.id
        ORDER BY c.name ASC, p.project_name ASC
    ";
} elseif ($role === 'admin') {
    // Look up the admin's single account_id
    $sqlOne = "SELECT account_id FROM customer_account_users WHERE user_id = ? AND role = 'admin' LIMIT 1";
    $stmtOne = $conn->prepare($sqlOne);
    if (!$stmtOne) die("Error preparing account lookup: " . $conn->error);
    $stmtOne->bind_param("i", $user_id);
    $stmtOne->execute();
    $stmtOne->bind_result($account_id_for_admin);
    $stmtOne->fetch();
    $stmtOne->close();

    if (empty($account_id_for_admin)) {
        // Handle case where admin has no assigned account - maybe show message or redirect
        // For now, we'll let the project query return empty results.
         $sqlProjects = "SELECT NULL LIMIT 0"; // No projects if no account
    } else {
        $sqlProjects = "
            SELECT 
                p.id,
                p.project_name,
                c.name AS account_name,
                p.estimated_completion_date,
                p.project_address,
                p.street_address,
                p.city,
                p.state,
                p.zip_code,
                COALESCE((
                    SELECT SUM(d2.wattage * d2.quantity)
                    FROM deliveries d2
                    WHERE d2.project_id = p.id
                      AND (d2.status_of_delivery IS NULL OR d2.status_of_delivery <> 'Canceled')
                ), 0) AS project_size,
                COALESCE((
                    SELECT COUNT(*)
                    FROM deliveries d3
                    WHERE d3.project_id = p.id
                      AND (d3.status_of_delivery IS NULL OR d3.status_of_delivery <> 'Canceled')
                ), 0) AS delivery_count,
                (
                    SELECT COUNT(*) FROM inventory_pallets ip 
                    LEFT JOIN unassigned_module_items umi ON ip.unassigned_module_item_id = umi.id
                    LEFT JOIN modules m ON umi.unassigned_module_id = m.id
                    WHERE (m.project_id = p.id OR ip.assigned_project_id = p.id OR ip.current_project_id = p.id)
                      AND ip.status = 'Delivered to Project'
                ) AS delivered_pallets,
                (
                    SELECT COUNT(*) FROM inventory_pallets ip 
                    LEFT JOIN unassigned_module_items umi ON ip.unassigned_module_item_id = umi.id
                    LEFT JOIN modules m ON umi.unassigned_module_id = m.id
                    WHERE (m.project_id = p.id OR ip.assigned_project_id = p.id OR ip.current_project_id = p.id)
                ) AS total_pallets
            FROM projects p
            JOIN customer_accounts c ON p.account_id = c.id
            WHERE p.account_id = ?
            ORDER BY p.project_name ASC
        ";
        $paramTypesProjects = "i";
        $paramsProjects     = [$account_id_for_admin];
    }
} else {
    header("Location: unauthorized");
    exit();
}

// Prepare and execute the projects query
$stmtProjects = $conn->prepare($sqlProjects);
if (!$stmtProjects) die("Error preparing projects query: " . $conn->error);
if (!empty($paramTypesProjects)) {
    $stmtProjects->bind_param($paramTypesProjects, ...$paramsProjects);
}
$stmtProjects->execute();
$resultProjects = $stmtProjects->get_result();

if ($resultProjects) {
    while ($project = $resultProjects->fetch_assoc()) {
        // Calculate project status and progress
        $delivered_pallets = (int)($project['delivered_pallets'] ?? 0);
        $total_pallets = (int)($project['total_pallets'] ?? 0);
        $delivery_progress = $total_pallets > 0 ? ($delivered_pallets / $total_pallets) * 100 : 0;
        
        // Determine project status
        $project_status = 'Planning';
        if ($total_pallets > 0) {
            if ($delivery_progress >= 100) {
                $project_status = 'Completed';
            } elseif ($delivery_progress > 0) {
                $project_status = 'In Progress';
            } else {
                $project_status = 'Ready to Ship';
            }
        }
        
        $project['delivery_progress'] = $delivery_progress;
        $project['project_status'] = $project_status;
        $projects[] = $project;
    }
}
$stmtProjects->close();

// Calculate summary statistics
$total_projects = count($projects);
$completed_projects = count(array_filter($projects, fn($p) => $p['project_status'] === 'Completed'));
$in_progress_projects = count(array_filter($projects, fn($p) => $p['project_status'] === 'In Progress'));
$total_mw = array_sum(array_map(fn($p) => (float)($p['project_size'] ?? 0) / 1_000_000, $projects));

$conn->close(); // Close connection after fetching project data
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Manage Projects</title>
    <link rel="stylesheet" href="portal.css">
    <link rel="icon" href="pictures/favicon.png" type="image/x-icon">
    <link href="https://fonts.googleapis.com/css?family=Poppins:300,400,500,600,700&display=swap" rel="stylesheet">
    <style>
        /* Header Section - Exact Match to Global Documents */
        .projects-header {
            background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%);
            border-radius: 24px;
            padding: 32px;
            margin-bottom: 40px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.06);
            border: 1px solid rgba(72, 140, 154, 0.08);
            position: relative;
            overflow: hidden;
        }

        .projects-header::before {
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
        
        .add-project-btn {
            background: #488C9A;
            color: white;
            border: none;
            padding: 12px 20px;
            border-radius: 6px;
            text-decoration: none;
            font-weight: 500;
            font-size: 0.9rem;
            transition: all 0.2s ease;
        }
        
        .add-project-btn:hover {
            background: #3A6E7F;
            transform: translateY(-1px);
            box-shadow: 0 4px 8px rgba(72, 140, 154, 0.2);
        }
        
        /* Responsive Header */
        @media (max-width: 768px) {
            .header-content {
                flex-direction: column;
                text-align: center;
                gap: 20px;
            }
            
            .header-stats {
                flex-wrap: wrap;
                justify-content: center;
                gap: 15px;
            }
            
            .stat-item {
                min-width: 100px;
                padding: 12px 16px;
            }
            
            .header-info h1 {
                font-size: 2rem;
            }
            
            .header-subtitle {
                font-size: 1rem;
            }
        }
        /* Project Cards */
        .projects-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(400px, 1fr));
            gap: 25px;
            margin-top: 30px;
        }
        
        .project-card {
            background: white;
            border-radius: 16px;
            padding: 25px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
            border: 1px solid #f0f0f0;
            transition: all 0.3s ease;
            position: relative;
            /* Allow dropdowns to be fully visible outside the card */
            overflow: visible;
        }
        
        .project-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 8px 32px rgba(72, 140, 154, 0.15);
            border-color: #488C9A;
        }
        
        .project-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(135deg, #488C9A 0%, #3A6E7F 100%);
        }
        
        .project-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 20px;
        }
        
        .project-title {
            font-size: 1.4rem;
            font-weight: 700;
            color: #293E4C;
            margin: 0 0 5px 0;
            line-height: 1.3;
        }
        
        .project-account {
            font-size: 0.95rem;
            color: #666;
            margin: 0;
            font-weight: 500;
        }
        
        .project-status {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .status-planning {
            background: #fff3cd;
            color: #856404;
        }
        
        .status-ready {
            background: #d1ecf1;
            color: #0c5460;
        }
        
        .status-progress {
            background: #d4edda;
            color: #155724;
        }
        
        .status-completed {
            background: #e2e3e5;
            color: #383d41;
        }
        
        .project-metrics {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
            margin: 20px 0;
        }
        
        .metric-item {
            text-align: center;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 10px;
        }
        
        .metric-value {
            font-size: 1.3rem;
            font-weight: 700;
            color: #488C9A;
            margin: 0 0 5px 0;
        }
        
        .metric-label {
            font-size: 0.85rem;
            color: #666;
            margin: 0;
            font-weight: 500;
        }
        
        .progress-section {
            margin: 20px 0;
        }
        
        .progress-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 8px;
        }
        
        .progress-label {
            font-size: 0.9rem;
            font-weight: 600;
            color: #293E4C;
        }
        
        .progress-percentage {
            font-size: 0.85rem;
            font-weight: 600;
            color: #488C9A;
        }
        
        .progress-bar {
            width: 100%;
            height: 8px;
            background: #e9ecef;
            border-radius: 4px;
            overflow: hidden;
        }
        
        .progress-fill {
            height: 100%;
            background: linear-gradient(135deg, #488C9A 0%, #3A6E7F 100%);
            border-radius: 4px;
            transition: width 0.6s ease;
        }
        
        .project-actions {
            display: flex;
            gap: 10px;
            margin-top: 20px;
            padding-top: 20px;
            border-top: 1px solid #f0f0f0;
        }
        
        .btn-details {
            flex: 1;
            background: linear-gradient(135deg, #488C9A 0%, #3A6E7F 100%);
            color: white;
            padding: 12px 20px;
            border: none;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 600;
            text-align: center;
            transition: all 0.3s ease;
        }
        
        .btn-details:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(72, 140, 154, 0.3);
        }
        
        .completion-date {
            font-size: 0.9rem;
            color: #666;
            margin-top: 15px;
            padding: 10px;
            background: #f8f9fa;
            border-radius: 8px;
            text-align: center;
        }
        
        .completion-date.overdue {
            background: #f8d7da;
            color: #721c24;
        }
        
        .completion-date.upcoming {
            background: #fff3cd;
            color: #856404;
        }
        
        /* Legacy table styles for fallback */
        .table-responsive {
            display: none;
        }
        /* Clean up old styles - replaced with modern card design */
        .error-message {
            color: #721c24;
            background-color: #f8d7da;
            padding: 15px;
            border: 1px solid #f5c6cb;
            border-radius: 4px;
            margin-bottom: 20px;
        }
        .success-message {
            color: #155724;
            background-color: #d4edda;
            padding: 15px;
            border: 1px solid #c3e6cb;
            border-radius: 4px;
            margin-bottom: 20px;
        }
        /* Enhanced responsive design */
        @media (max-width: 1200px) {
            .projects-grid {
                grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            }
        }
        
        @media (max-width: 768px) {
            .projects-grid {
                grid-template-columns: 1fr;
                gap: 20px;
            }
            
            .project-card {
                padding: 20px;
            }
            
            .project-metrics {
                grid-template-columns: 1fr;
                gap: 10px;
            }
        }
        
        /* Dropdown menu styling */
        .dropdown {
            position: relative;
            display: inline-block;
        }
        .dropdown-toggle {
            background: none;
            border: none;
            color: #488C9A;
            padding: 4px;
            cursor: pointer;
            font-size: 1.1em;
            border-radius: 3px;
            transition: all 0.2s ease;
        }
        .dropdown-toggle:hover {
            background-color: #f8f9fa;
            color: #293E4C;
        }
        .dropdown-menu {
            display: none;
            position: absolute;
            top: calc(100% + 6px);
            bottom: auto;
            background-color: white;
            min-width: 140px;
            box-shadow: 0px 8px 16px 0px rgba(0,0,0,0.2);
            border-radius: 4px;
            z-index: 1000;
            border: 1px solid #ddd;
            right: 0;
            left: auto;
            /* Animation setup */
            opacity: 0;
            transform: translateY(6px) scale(0.98);
            transform-origin: top right;
            transition: opacity 140ms ease, transform 140ms ease;
            will-change: opacity, transform;
        }
        
        /* Alternative positioning for dropdowns that would overflow */
        .dropdown-menu.dropdown-left {
            right: auto;
            left: 0;
        }

        /* Show above the toggle button */
        .dropdown-menu.dropdown-up {
            top: auto;
            bottom: calc(100% + 6px);
            transform-origin: bottom right;
            /* initial offset for animation when hidden */
            transform: translateY(-6px) scale(0.98);
        }

        /* Show to the right of the toggle */
        .dropdown-menu.dropdown-side-right {
            top: 0;
            bottom: auto;
            left: calc(100% + 6px);
            right: auto;
            transform-origin: top left;
            transform: translateX(6px) scale(0.98);
        }

        /* Show to the left of the toggle */
        .dropdown-menu.dropdown-side-left {
            top: 0;
            bottom: auto;
            right: calc(100% + 6px);
            left: auto;
            transform-origin: top right;
            transform: translateX(-6px) scale(0.98);
        }
        
        /* For very small screens, make dropdown smaller */
        @media (max-width: 768px) {
            .dropdown-menu {
                min-width: 120px;
            }
        }
        .dropdown-menu.show {
            display: block;
            opacity: 1;
            transform: translate(0, 0) scale(1);
        }
        .dropdown-item {
            display: block;
            width: 100%;
            padding: 8px 12px;
            text-decoration: none;
            color: #333;
            border: none;
            background: none;
            text-align: left;
            cursor: pointer;
            font-size: 0.9em;
        }
        .dropdown-item:hover {
            background-color: #f8f9fa;
        }
        .dropdown-item.edit {
            color: #488C9A;
        }
        .dropdown-item.delete {
            color: #dc3545;
        }
        .dropdown-item:first-child {
            border-radius: 4px 4px 0 0;
        }
        .dropdown-item:last-child {
            border-radius: 0 0 4px 4px;
        }
        
        /* Enhanced dropdown styling for cards */
        .project-actions .dropdown {
            position: relative;
        }
        
        .project-actions .dropdown-toggle {
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            color: #495057;
            padding: 8px 12px;
            cursor: pointer;
            font-size: 1rem;
            border-radius: 6px;
            transition: all 0.2s ease;
        }
        
        .project-actions .dropdown-toggle:hover {
            background: #e9ecef;
            border-color: #adb5bd;
        }
        
        /* Delete modal styling */
        .delete-modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            overflow: auto;
            background-color: rgba(0,0,0,0.5);
        }
        
        .delete-modal-content {
            background-color: #fefefe;
            margin: 10% auto;
            padding: 30px;
            border: 1px solid #888;
            width: 80%;
            max-width: 600px;
            border-radius: 8px;
            position: relative;
        }
        
        .delete-modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid #e0e0e0;
        }
        
        .delete-modal-close {
            color: #aaa;
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
            line-height: 1;
        }
        
        .delete-modal-close:hover {
            color: #000;
        }
        
        .delete-warning {
            background-color: #fff3cd;
            border: 1px solid #ffeaa7;
            border-radius: 6px;
            padding: 15px;
            margin-bottom: 20px;
            color: #856404;
        }
        
        .delete-warning h4 {
            margin-top: 0;
            color: #dc3545;
        }
        
        .deletion-summary {
            background-color: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 6px;
            padding: 20px;
            margin: 20px 0;
        }
        
        .deletion-summary h4 {
            margin-top: 0;
            color: #293E4C;
        }
        
        .delete-item {
            padding: 8px 0;
            border-bottom: 1px solid #eee;
            font-size: 1.1em;
        }
        
        .delete-item:last-child {
            border-bottom: none;
        }
        
        .delete-sub-item {
            padding: 4px 0;
            color: #666;
            font-size: 0.95em;
        }
        
        .delete-modal-actions {
            display: flex;
            justify-content: flex-end;
            gap: 15px;
            margin-top: 25px;
            padding-top: 20px;
            border-top: 1px solid #e0e0e0;
        }
        
        .btn-cancel {
            background-color: #6c757d;
            color: white;
            padding: 10px 20px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 1em;
        }
        
        .btn-cancel:hover {
            background-color: #5a6268;
        }
        
        .btn-delete-confirm {
            background-color: #dc3545;
            color: white;
            padding: 10px 20px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 1em;
            font-weight: 600;
        }
        
        .btn-delete-confirm:hover {
            background-color: #c82333;
        }
        
        .btn-delete-confirm:disabled {
            background-color: #6c757d;
            cursor: not-allowed;
        }
    </style>
    <script>
        // Enhanced delete confirmation with detailed information
        function confirmDelete(projectName, projectId) {
            // First get deletion info via AJAX
            fetch(`get_project_delete_info.php?project_id=${projectId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.error) {
                        alert('Error: ' + data.error);
                        return;
                    }
                    
                    // Show detailed confirmation modal
                    showDeleteModal(data);
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Error fetching project deletion information');
                });
        }
        
        function showDeleteModal(data) {
            const modal = document.getElementById('deleteModal');
            const project = data.project;
            const counts = data.counts;
            
            // Update modal content
            document.getElementById('modalProjectName').textContent = project.name;
            document.getElementById('modalAccountName').textContent = project.account_name;
            
            // Build deletion summary
            let summaryHtml = '';
            
            if (counts.deliveries > 0) {
                summaryHtml += `<div class="delete-item">📦 <strong>${counts.deliveries}</strong> delivery record${counts.deliveries !== 1 ? 's' : ''}</div>`;
            }
            
            if (counts.module_batches > 0) {
                summaryHtml += `<div class="delete-item">📋 <strong>${counts.module_batches}</strong> module batch${counts.module_batches !== 1 ? 'es' : ''}</div>`;
                if (counts.total_modules > 0) {
                    summaryHtml += `<div class="delete-sub-item">    → ${counts.total_modules.toLocaleString()} total modules</div>`;
                }
            }
            
            if (counts.pallets > 0) {
                summaryHtml += `<div class="delete-item">📦 <strong>${counts.pallets}</strong> pallet record${counts.pallets !== 1 ? 's' : ''}</div>`;
            }
            
            if (counts.delivery_pallets > 0) {
                summaryHtml += `<div class="delete-item">🔗 <strong>${counts.delivery_pallets}</strong> delivery-pallet link${counts.delivery_pallets !== 1 ? 's' : ''}</div>`;
            }
            
            if (summaryHtml === '') {
                summaryHtml = '<div class="delete-item">✅ No associated data to delete</div>';
            }
            
            document.getElementById('deletionSummary').innerHTML = summaryHtml;
            
            // Store project ID for actual deletion
            document.getElementById('confirmDeleteBtn').setAttribute('data-project-id', project.id);
            document.getElementById('confirmDeleteBtn').setAttribute('data-project-name', project.name);
            
            // Show modal
            modal.style.display = 'block';
        }
        
        function closeDeleteModal() {
            document.getElementById('deleteModal').style.display = 'none';
        }
        
        function executeDelete() {
            const btn = document.getElementById('confirmDeleteBtn');
            const projectId = btn.getAttribute('data-project-id');
            const projectName = btn.getAttribute('data-project-name');
            
            // Disable button and show loading
            btn.disabled = true;
            btn.textContent = 'Deleting...';
            
            // Execute deletion
            const formData = new FormData();
            formData.append('project_id', projectId);
            
            fetch('delete_project_cascade.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Show success message and reload page
                    alert(`Success: ${data.message}`);
                    window.location.reload();
                } else {
                    alert('Error: ' + data.error);
                    // Re-enable button
                    btn.disabled = false;
                    btn.textContent = 'Yes, Delete Everything';
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Error deleting project');
                // Re-enable button
                btn.disabled = false;
                btn.textContent = 'Yes, Delete Everything';
            });
        }
        
        // Dropdown functionality with smart positioning
        function toggleDropdown(event, dropdownId) {
            event.stopPropagation();

            const toggle = event.currentTarget;

            // Close all other dropdowns
            document.querySelectorAll('.dropdown-menu').forEach(menu => {
                if (menu.id !== dropdownId) {
                    menu.classList.remove('show', 'dropdown-left', 'dropdown-up', 'dropdown-side-right', 'dropdown-side-left');
                }
            });

            // Toggle the clicked dropdown
            const dropdown = document.getElementById(dropdownId);
            const isShowing = dropdown.classList.contains('show');

            if (isShowing) {
                dropdown.classList.remove('show');
                return;
            }

            // Reset positioning classes
            dropdown.classList.remove('dropdown-left', 'dropdown-up', 'dropdown-side-right', 'dropdown-side-left');

            // Measure size without triggering animation or visual flicker
            const prevDisplay = dropdown.style.display;
            const prevVisibility = dropdown.style.visibility;
            dropdown.style.display = 'block';
            dropdown.style.visibility = 'hidden';

            // Check menu size and available space
            const menuRect = dropdown.getBoundingClientRect();
            const toggleRect = toggle.getBoundingClientRect();
            const windowWidth = window.innerWidth;
            const windowHeight = window.innerHeight;

            const spaceBelow = windowHeight - toggleRect.bottom;
            const spaceAbove = toggleRect.top;
            const spaceRight = windowWidth - toggleRect.right;
            const spaceLeft = toggleRect.left;

            // Decide vertical or side placement
            if (spaceBelow >= menuRect.height + 8) {
                // default: below (no class needed)
            } else if (spaceAbove >= menuRect.height + 8) {
                dropdown.classList.add('dropdown-up');
            } else if (spaceRight >= menuRect.width + 8) {
                dropdown.classList.add('dropdown-side-right');
            } else {
                dropdown.classList.add('dropdown-side-left');
            }
            
            // Restore styles and show with animation
            dropdown.style.display = prevDisplay;
            dropdown.style.visibility = prevVisibility;
            dropdown.classList.add('show');

            // For below/above placements, ensure it doesn't overflow off the right edge
            if (!dropdown.classList.contains('dropdown-side-right') && !dropdown.classList.contains('dropdown-side-left')) {
                const rectAfter = dropdown.getBoundingClientRect();
                if (rectAfter.right > windowWidth - 8) {
                    dropdown.classList.add('dropdown-left');
                }
            }
        }
        
        // Close dropdowns when clicking outside
        document.addEventListener('click', function() {
            document.querySelectorAll('.dropdown-menu').forEach(menu => {
                menu.classList.remove('show');
            });
        });
        
        // Close modal when clicking outside of it
        window.onclick = function(event) {
            const modal = document.getElementById('deleteModal');
            if (event.target === modal) {
                closeDeleteModal();
            }
        }
    </script>
</head>
<body>
<?php include 'header.php'; ?>
<main>
    <?php require_once 'components/breadcrumbs.php'; echo slp_render_breadcrumbs(['current_label' => 'Manage Projects']); ?>
    
    <div class="projects-header">
        <div class="header-content">
            <div class="header-left">
                <div class="header-info">
                    <h1>Manage Projects</h1>
                    <p class="header-subtitle">Comprehensive project management and tracking dashboard</p>
                </div>
            </div>
            <div class="header-stats">
                <div class="stat-item">
                    <p class="stat-number"><?php echo $total_projects; ?></p>
                    <p class="stat-label">Projects</p>
                </div>
                <div class="stat-item">
                    <p class="stat-number"><?php echo number_format($total_mw, 1); ?></p>
                    <p class="stat-label">Total MW</p>
                </div>
                <div class="stat-item">
                    <p class="stat-number"><?php echo $in_progress_projects; ?></p>
                    <p class="stat-label">In Progress</p>
                </div>
                <div class="stat-item">
                    <p class="stat-number"><?php echo $completed_projects; ?></p>
                    <p class="stat-label">Completed</p>
                </div>
            </div>
        </div>
    </div>
    
    <div style="display: flex; justify-content: flex-end; margin-bottom: 20px;">
        <a href="add_project.php" class="add-project-btn">+ Add New Project</a>
    </div>

    <?php if (!empty($errorMessage)): ?>
        <div class="error-message">
            <strong>Error:</strong> <?php echo htmlspecialchars($errorMessage); ?>
        </div>
    <?php endif; ?>

    <?php if (!empty($successMessage)): ?>
        <div class="success-message">
            <strong><?php echo htmlspecialchars($successMessage); ?></strong>
        </div>
    <?php endif; ?>

    <div class="projects-grid">
        <?php if (!empty($projects)): ?>
            <?php foreach ($projects as $project): ?>
                <?php
                    $project_size = (float)($project['project_size'] ?? 0);
                    $project_size_mw = $project_size / 1_000_000; // convert watts to MW
                    $delivery_progress = $project['delivery_progress'];
                    $project_status = $project['project_status'];
                    
                    // Format address
                    $address_parts = array_filter([
                        $project['street_address'], 
                        $project['city'], 
                        $project['state'], 
                        $project['zip_code']
                    ]);
                    $formatted_address = implode(', ', $address_parts);
                    
                    // Status class mapping
                    $status_classes = [
                        'Planning' => 'status-planning',
                        'Ready to Ship' => 'status-ready',
                        'In Progress' => 'status-progress',
                        'Completed' => 'status-completed'
                    ];
                    $status_class = $status_classes[$project_status] ?? 'status-planning';
                ?>
                <div class="project-card">
                    <div class="project-header">
                        <div>
                            <h3 class="project-title"><?php echo htmlspecialchars($project['project_name']); ?></h3>
                            <p class="project-account"><?php echo htmlspecialchars($project['account_name']); ?></p>
                        </div>
                        <div class="project-status <?php echo $status_class; ?>">
                            <?php echo $project_status; ?>
                        </div>
                    </div>
                    
                    <?php if (!empty($formatted_address)): ?>
                    <div style="margin-bottom: 15px; color: #666; font-size: 0.9rem;">
                        📍 <?php echo htmlspecialchars($formatted_address); ?>
                    </div>
                    <?php endif; ?>
                    
                    <div class="project-metrics">
                        <div class="metric-item">
                            <div class="metric-value"><?php echo number_format($project_size_mw, 2); ?></div>
                            <div class="metric-label">MW Size</div>
                        </div>
                        <div class="metric-item">
                            <div class="metric-value"><?php echo (int)($project['delivery_count'] ?? 0); ?></div>
                            <div class="metric-label">Deliveries</div>
                        </div>
                    </div>
                    
                    <?php if ($project['total_pallets'] > 0): ?>
                    <div class="progress-section">
                        <div class="progress-header">
                            <span class="progress-label">Delivery Progress</span>
                            <span class="progress-percentage"><?php echo number_format($delivery_progress, 1); ?>%</span>
                        </div>
                        <div class="progress-bar">
                            <div class="progress-fill" style="width: <?php echo min(100, $delivery_progress); ?>%;"></div>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <?php if (!empty($project['estimated_completion_date'])): ?>
                        <?php 
                            $completion_date = new DateTime($project['estimated_completion_date']);
                            $today = new DateTime();
                            $interval = $today->diff($completion_date);
                            $days_until = (int)$interval->format('%R%a');
                            
                            $date_class = 'completion-date';
                            if ($days_until < 0) $date_class .= ' overdue';
                            elseif ($days_until <= 30) $date_class .= ' upcoming';
                        ?>
                        <div class="<?php echo $date_class; ?>">
                            📅 Target: <?php echo $completion_date->format('M j, Y'); ?>
                            <?php if ($days_until < 0): ?>
                                <br><small><?php echo abs($days_until); ?> days overdue</small>
                            <?php elseif ($days_until <= 30): ?>
                                <br><small><?php echo $days_until; ?> days remaining</small>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                    
                    <div class="project-actions">
                        <a href="project_overview.php?project_id=<?php echo $project['id']; ?>" class="btn-details">
                            View Details
                        </a>
                        <div class="dropdown">
                            <button class="dropdown-toggle" onclick="toggleDropdown(event, 'dropdown-menu-<?php echo $project['id']; ?>')" title="More actions">⚙️</button>
                            <div id="dropdown-menu-<?php echo $project['id']; ?>" class="dropdown-menu">
                                <a href="edit_project.php?project_id=<?php echo $project['id']; ?>" class="dropdown-item edit">Edit Project</a>
                                <a href="javascript:void(0);" onclick="confirmDelete('<?php echo htmlspecialchars($project['project_name'], ENT_QUOTES); ?>', <?php echo $project['id']; ?>)" class="dropdown-item delete">Delete Project</a>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div style="grid-column: 1 / -1; text-align: center; padding: 60px 20px; color: #666;">
                <div style="font-size: 3rem; margin-bottom: 20px; opacity: 0.3;">📋</div>
                <h3 style="margin-bottom: 10px; color: #293E4C;">No Projects Found</h3>
                <p style="margin-bottom: 30px;">Get started by creating your first project</p>
                <a href="add_project.php" class="btn-details" style="display: inline-block; max-width: 200px;">Add First Project</a>
            </div>
        <?php endif; ?>
    </div>

    <!-- Delete Confirmation Modal -->
    <div id="deleteModal" class="delete-modal">
        <div class="delete-modal-content">
            <div class="delete-modal-header">
                <h2>⚠️ Confirm Project Deletion</h2>
                <span class="delete-modal-close" onclick="closeDeleteModal()">&times;</span>
            </div>
            
            <div class="delete-warning">
                <h4>Warning: This action cannot be undone!</h4>
                <p>You are about to permanently delete the project <strong id="modalProjectName"></strong> (Account: <span id="modalAccountName"></span>)</p>
            </div>
            
            <div class="deletion-summary">
                <h4>The following data will also be permanently deleted:</h4>
                <div id="deletionSummary">
                    <!-- Dynamically populated -->
                </div>
            </div>
            
            <div class="delete-modal-actions">
                <button type="button" class="btn-cancel" onclick="closeDeleteModal()">Cancel</button>
                <button type="button" id="confirmDeleteBtn" class="btn-delete-confirm" onclick="executeDelete()">Yes, Delete Everything</button>
            </div>
        </div>
    </div>

</main>
</body>
</html>
