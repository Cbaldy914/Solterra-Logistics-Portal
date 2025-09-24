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

// --- Fetch Projects --- 
$sqlProjects        = "";
$paramTypesProjects = "";
$paramsProjects     = [];
$account_id_for_admin = null; // Define it here for potential reuse

// If the user is global_admin, they can see all projects
if ($role === 'global_admin') {
    $sqlProjects = "
        SELECT p.id, p.project_name, c.name AS account_name, p.estimated_completion_date,
               SUM(pwo.wattage * pwo.total_order) AS project_size
          FROM projects p
          JOIN customer_accounts c ON p.account_id = c.id
          LEFT JOIN project_wattage_orders pwo ON p.id = pwo.project_id
         GROUP BY p.id, p.project_name, c.name, p.estimated_completion_date
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
            SELECT p.id, p.project_name, c.name AS account_name, p.estimated_completion_date,
                   SUM(pwo.wattage * pwo.total_order) AS project_size
              FROM projects p
              JOIN customer_accounts c ON p.account_id = c.id
              LEFT JOIN project_wattage_orders pwo ON p.id = pwo.project_id
             WHERE p.account_id = ?
             GROUP BY p.id, p.project_name, c.name, p.estimated_completion_date
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
        $projects[] = $project;
    }
}
$stmtProjects->close();

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
        .header-container {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid #e0e0e0;
        }
        .action-buttons.add-new {
            display: inline-block;
            padding: 8px 15px;
            background-color: #488C9A;
            color: white;
            text-decoration: none;
            border: none;
            border-radius: 4px;
            cursor: pointer;
        }
        .action-buttons.add-new:hover {
            background-color: #293E4C;
        }
        .action-buttons.edit {
            background-color: #488C9A;
            color: white;
            padding: 4px 8px;
            text-decoration: none;
            border-radius: 3px;
            font-size: 0.9em;
            margin-right: 5px;
        }
        .action-buttons.edit:hover {
            background-color: #293E4C;
        }
        .action-buttons.delete {
            background-color: #dc3545;
            color: white;
            padding: 4px 8px;
            text-decoration: none;
            border-radius: 3px;
            font-size: 0.9em;
        }
        .action-buttons.delete:hover {
            background-color: #c82333;
        }
        .action-buttons.view {
            background-color: #488C9A;
            color: white;
            padding: 4px 8px;
            text-decoration: none;
            border-radius: 3px;
            font-size: 0.9em;
            margin-right: 5px;
        }
        .action-buttons.view:hover {
            background-color: #293E4C;
        }
        .action-buttons.warehouse {
            background-color: #488C9A;
            color: white;
            padding: 4px 8px;
            text-decoration: none;
            border-radius: 3px;
            font-size: 0.9em;
            margin-right: 5px;
        }
        .action-buttons.warehouse:hover {
            background-color: #293E4C;
        }
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
        .project-info {
            font-size: 0.9em;
            color: #666;
        }
        .project-size {
            font-weight: 500;
            color: #488C9A;
        }
        .completion-date {
            font-size: 0.85em;
            color: #666;
        }
        .completion-date.overdue {
            color: #dc3545;
            font-weight: 500;
        }
        .completion-date.upcoming {
            color: #ffc107;
            font-weight: 500;
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
            right: 0;
            top: 100%;
            background-color: white;
            min-width: 120px;
            box-shadow: 0px 8px 16px 0px rgba(0,0,0,0.2);
            border-radius: 4px;
            z-index: 1000;
            border: 1px solid #ddd;
        }
        .dropdown-menu.show {
            display: block;
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
        
        /* Actions cell styling */
        .actions-cell {
            position: relative;
        }
        .actions-cell .dropdown {
            position: absolute;
            top: 4px;
            right: 4px;
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
        
        // Dropdown functionality
        function toggleDropdown(event, dropdownId) {
            event.stopPropagation();
            
            // Close all other dropdowns
            document.querySelectorAll('.dropdown-menu').forEach(menu => {
                if (menu.id !== dropdownId) {
                    menu.classList.remove('show');
                }
            });
            
            // Toggle the clicked dropdown
            const dropdown = document.getElementById(dropdownId);
            dropdown.classList.toggle('show');
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
    
    <div class="header-container">
        <h1>Manage Projects</h1>
        <a href="add_project.php" class="action-buttons add-new">Add New Project</a>
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

    <div class="table-responsive">
        <table>
            <thead>
                <tr>
                    <th>Project</th>
                    <th>Project Size</th>
                    <th>Completion Date</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($projects)): ?>
                    <?php foreach ($projects as $project): ?>
                        <tr>
                            <td>
                                <strong><?php echo htmlspecialchars($project['project_name']); ?></strong>
                                <div class="project-info"><?php echo htmlspecialchars($project['account_name']); ?></div>
                            </td>
                            <td>
                                <div class="project-size">
                                    <?php
                                        $project_size    = (float)($project['project_size'] ?? 0);
                                        $project_size_mw = $project_size / 1_000_000; // convert watts to MW
                                        echo number_format($project_size_mw, 2) . ' MW';
                                    ?>
                                </div>
                            </td>
                            <td>
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
                                        📅 <?php echo $completion_date->format('M j, Y'); ?>
                                        <?php if ($days_until < 0): ?>
                                            <br><small>(<?php echo abs($days_until); ?> days overdue)</small>
                                        <?php elseif ($days_until <= 30): ?>
                                            <br><small>(<?php echo $days_until; ?> days remaining)</small>
                                        <?php endif; ?>
                                    </div>
                                <?php else: ?>
                                    <span style="color: #999;">Not set</span>
                                <?php endif; ?>
                            </td>
                            <td class="actions-cell">
                                <a href="manage_deliveries.php?project_id=<?php echo $project['id']; ?>" class="action-buttons view">Deliveries</a>
                                <a href="warehouse_info.php?project_id=<?php echo $project['id']; ?>" class="action-buttons warehouse">Warehouse</a>
                                <div class="dropdown">
                                    <button class="dropdown-toggle" onclick="toggleDropdown(event, 'dropdown-menu-<?php echo $project['id']; ?>')" title="More actions">✏️</button>
                                    <div id="dropdown-menu-<?php echo $project['id']; ?>" class="dropdown-menu">
                                        <a href="edit_project.php?project_id=<?php echo $project['id']; ?>" class="dropdown-item edit">Edit</a>
                                        <a href="javascript:void(0);" onclick="confirmDelete('<?php echo htmlspecialchars($project['project_name'], ENT_QUOTES); ?>', <?php echo $project['id']; ?>)" class="dropdown-item delete">Delete</a>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="4" style="text-align: center; padding: 20px; color: #666;">
                            No projects found. <a href="add_project.php">Add the first project</a>
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
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
