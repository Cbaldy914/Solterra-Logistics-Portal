<?php
session_name("logistics_session");
session_start();

// Ensure user has role global_admin or admin
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'global_admin'])) {
    header("Location: unauthorized.php");
    exit();
}

require_once '../config.php';
$conn = getDBConnection();

// Unified dashboard link
$dashboard_link = 'dashboard.php';

$manufacturers = [];
$errorMessage = '';
$successMessage = '';

// Handle delete action
if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['id'])) {
    try {
        $manufacturer_id = intval($_GET['id']);
        
        // Check if manufacturer is being used anywhere (optional - you can add checks later)
        // For now, we'll allow deletion
        
        $stmt = $conn->prepare("DELETE FROM manufacturers WHERE id = ?");
        if (!$stmt) {
            throw new Exception("Error preparing delete statement: " . $conn->error);
        }
        
        $stmt->bind_param("i", $manufacturer_id);
        if ($stmt->execute()) {
            $successMessage = "Manufacturer deleted successfully.";
        } else {
            throw new Exception("Error deleting manufacturer: " . $stmt->error);
        }
        $stmt->close();
        
    } catch (Exception $e) {
        $errorMessage = $e->getMessage();
    }
}

try {
    // Fetch all manufacturers
    $sql = "SELECT 
                id, 
                name, 
                short_name,
                contact_person,
                phone,
                email,
                website,
                address,
                is_active,
                created_at
            FROM manufacturers 
            ORDER BY is_active DESC, name ASC";
            
    $result = $conn->query($sql);

    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $manufacturers[] = $row;
        }
    } else {
        throw new Exception("Error fetching manufacturers: " . $conn->error);
    }

} catch (Exception $e) {
    $errorMessage = $e->getMessage();
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Manufacturers</title>
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
        .status-badge {
            padding: 3px 8px;
            border-radius: 3px;
            font-size: 0.85em;
            font-weight: 500;
        }
        .status-active {
            background-color: #d4edda;
            color: #155724;
        }
        .status-inactive {
            background-color: #f8d7da;
            color: #721c24;
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
        .contact-info {
            font-size: 0.9em;
            color: #666;
        }
        .website-link {
            color: #488C9A;
            text-decoration: none;
        }
        .website-link:hover {
            text-decoration: underline;
        }
        
        /* Dropdown menu styling */
        .dropdown {
            position: relative;
            display: inline-block;
        }
        .dropdown-toggle {
            background-color: #6c757d;
            color: white;
            padding: 4px 8px;
            border: none;
            border-radius: 3px;
            font-size: 0.9em;
            cursor: pointer;
            font-weight: bold;
        }
        .dropdown-toggle:hover {
            background-color: #545b62;
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
    </style>
    <script>
        function confirmDelete(manufacturerName, manufacturerId) {
            if (confirm(`Are you sure you want to delete the manufacturer "${manufacturerName}"? This action cannot be undone.`)) {
                window.location.href = `manufacturers.php?action=delete&id=${manufacturerId}`;
            }
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
    </script>
</head>
<body>
<?php include 'header.php'; ?>
<main>
    <!-- Breadcrumb navigation -->
    <div class="breadcrumb" style="margin: 10px 20px;">
        <a href="<?php echo htmlspecialchars($dashboard_link); ?>" style="color: #488C9A; text-decoration: none;">Dashboard</a>
        <span class="separator" style="margin: 0 8px; color: #6c757d;">&raquo;</span>
        <span>Manage Manufacturers</span>
    </div>
    
    <div class="header-container">
        <h1>Manage Manufacturers</h1>
        <a href="add_manufacturer.php" class="action-buttons add-new">Add New Manufacturer</a>
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
                    <th>Manufacturer</th>
                    <th>Contact Information</th>
                    <th>Address</th>
                    <th>Website</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($manufacturers)): ?>
                    <?php foreach ($manufacturers as $manufacturer): ?>
                        <tr>
                            <td>
                                <strong><?php echo htmlspecialchars($manufacturer['name']); ?></strong>
                                <?php if (!empty($manufacturer['short_name'])): ?>
                                    <br><small style="color: #666;">(<?php echo htmlspecialchars($manufacturer['short_name']); ?>)</small>
                                <?php endif; ?>
                            </td>
                            <td class="contact-info">
                                <?php if (!empty($manufacturer['contact_person'])): ?>
                                    <strong><?php echo htmlspecialchars($manufacturer['contact_person']); ?></strong><br>
                                <?php endif; ?>
                                <?php if (!empty($manufacturer['phone'])): ?>
                                    📞 <?php echo htmlspecialchars($manufacturer['phone']); ?><br>
                                <?php endif; ?>
                                <?php if (!empty($manufacturer['email'])): ?>
                                    ✉️ <?php echo htmlspecialchars($manufacturer['email']); ?>
                                <?php endif; ?>
                            </td>
                            <td><?php echo htmlspecialchars($manufacturer['address'] ?? 'Not specified'); ?></td>
                            <td>
                                <?php if (!empty($manufacturer['website'])): ?>
                                    <a href="<?php echo htmlspecialchars($manufacturer['website']); ?>" target="_blank" class="website-link">
                                        🌐 Visit Website
                                    </a>
                                <?php else: ?>
                                    <span style="color: #999;">Not specified</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="status-badge <?php echo $manufacturer['is_active'] ? 'status-active' : 'status-inactive'; ?>">
                                    <?php echo $manufacturer['is_active'] ? 'Active' : 'Inactive'; ?>
                                </span>
                            </td>
                            <td>
                                <div class="dropdown">
                                    <button class="dropdown-toggle" onclick="toggleDropdown(event, 'dropdown-menu-<?php echo $manufacturer['id']; ?>')">
                                        Actions
                                    </button>
                                    <div id="dropdown-menu-<?php echo $manufacturer['id']; ?>" class="dropdown-menu">
                                        <a href="edit_manufacturer.php?id=<?php echo $manufacturer['id']; ?>" class="dropdown-item edit">Edit</a>
                                        <a href="javascript:void(0);" onclick="confirmDelete('<?php echo htmlspecialchars($manufacturer['name'], ENT_QUOTES); ?>', <?php echo $manufacturer['id']; ?>)" class="dropdown-item delete">Delete</a>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="6" style="text-align: center; padding: 20px; color: #666;">
                            No manufacturers found. <a href="add_manufacturer.php">Add the first manufacturer</a>
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</main>
</body>
</html> 