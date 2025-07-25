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

// Get manufacturer ID
if (!isset($_GET['manufacturer_id'])) {
    header("Location: manufacturers.php");
    exit();
}
$manufacturer_id = intval($_GET['manufacturer_id']);

// Unified dashboard link
$dashboard_link = 'dashboard.php';

$manufacturer = null;
$locations = [];
$errorMessage = '';
$successMessage = '';

// Handle delete action
if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['location_id'])) {
    try {
        $location_id = intval($_GET['location_id']);
        
        // Check if this is the primary location
        $stmt = $conn->prepare("SELECT is_primary FROM manufacturer_locations WHERE id = ? AND manufacturer_id = ?");
        $stmt->bind_param("ii", $location_id, $manufacturer_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $location = $result->fetch_assoc();
            if ($location['is_primary']) {
                throw new Exception("Cannot delete the primary location. Please set another location as primary first.");
            }
        }
        $stmt->close();
        
        // Delete the location
        $stmt = $conn->prepare("DELETE FROM manufacturer_locations WHERE id = ? AND manufacturer_id = ?");
        if (!$stmt) {
            throw new Exception("Error preparing delete statement: " . $conn->error);
        }
        
        $stmt->bind_param("ii", $location_id, $manufacturer_id);
        if ($stmt->execute()) {
            $successMessage = "Location deleted successfully.";
        } else {
            throw new Exception("Error deleting location: " . $stmt->error);
        }
        $stmt->close();
        
    } catch (Exception $e) {
        $errorMessage = $e->getMessage();
    }
}

// Handle set primary action
if (isset($_GET['action']) && $_GET['action'] === 'set_primary' && isset($_GET['location_id'])) {
    try {
        $location_id = intval($_GET['location_id']);
        
        // Set this location as primary (triggers will handle setting others to false)
        $stmt = $conn->prepare("UPDATE manufacturer_locations SET is_primary = TRUE WHERE id = ? AND manufacturer_id = ?");
        if (!$stmt) {
            throw new Exception("Error preparing update statement: " . $conn->error);
        }
        
        $stmt->bind_param("ii", $location_id, $manufacturer_id);
        if ($stmt->execute()) {
            $successMessage = "Primary location updated successfully.";
        } else {
            throw new Exception("Error updating primary location: " . $stmt->error);
        }
        $stmt->close();
        
    } catch (Exception $e) {
        $errorMessage = $e->getMessage();
    }
}

try {
    // Fetch manufacturer info
    $stmt = $conn->prepare("SELECT name, short_name FROM manufacturers WHERE id = ?");
    $stmt->bind_param("i", $manufacturer_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        throw new Exception("Manufacturer not found.");
    }
    
    $manufacturer = $result->fetch_assoc();
    $stmt->close();
    
    // Fetch all locations for this manufacturer
    $sql = "SELECT 
                id, 
                location_name,
                street_address,
                city,
                state,
                zip_code,
                country,
                is_primary,
                is_active,
                notes,
                created_at
            FROM manufacturer_locations 
            WHERE manufacturer_id = ?
            ORDER BY is_primary DESC, location_name ASC";
            
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $manufacturer_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $locations[] = $row;
        }
    }
    $stmt->close();

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
    <title>Manage Locations - <?php echo htmlspecialchars($manufacturer['name'] ?? 'Manufacturer'); ?></title>
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
        .status-primary {
            background-color: #cce5ff;
            color: #0056b3;
            font-weight: bold;
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
            min-width: 160px;
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
        .dropdown-item.primary {
            color: #0056b3;
        }
        .dropdown-item.delete {
            color: #dc3545;
        }
        .manufacturer-info {
            background-color: #f8f9fa;
            padding: 15px;
            border-radius: 4px;
            margin-bottom: 20px;
        }
        .address-display {
            font-size: 0.9em;
            line-height: 1.4;
        }
    </style>
    <script>
        function confirmDelete(locationName, locationId) {
            if (confirm(`Are you sure you want to delete the location "${locationName}"? This action cannot be undone.`)) {
                window.location.href = `manufacturer_locations.php?manufacturer_id=<?php echo $manufacturer_id; ?>&action=delete&location_id=${locationId}`;
            }
        }
        
        function confirmSetPrimary(locationName, locationId) {
            if (confirm(`Set "${locationName}" as the primary location for this manufacturer?`)) {
                window.location.href = `manufacturer_locations.php?manufacturer_id=<?php echo $manufacturer_id; ?>&action=set_primary&location_id=${locationId}`;
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
        <a href="manufacturers.php" style="color: #488C9A; text-decoration: none;">Manage Manufacturers</a>
        <span class="separator" style="margin: 0 8px; color: #6c757d;">&raquo;</span>
        <span>Manage Locations</span>
    </div>
    
    <!-- Manufacturer Info -->
    <div class="manufacturer-info">
        <h2 style="margin: 0 0 10px 0;">
            <?php echo htmlspecialchars($manufacturer['name']); ?>
            <?php if (!empty($manufacturer['short_name'])): ?>
                <small style="color: #666;">(<?php echo htmlspecialchars($manufacturer['short_name']); ?>)</small>
            <?php endif; ?>
        </h2>
        <p style="margin: 0; color: #666;">Managing locations for this manufacturer</p>
    </div>
    
    <div class="header-container">
        <h1>Locations</h1>
        <a href="add_manufacturer_location.php?manufacturer_id=<?php echo $manufacturer_id; ?>" class="action-buttons add-new">Add New Location</a>
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
                    <th>Location Name</th>
                    <th>Address</th>
                    <th>Type</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($locations)): ?>
                    <?php foreach ($locations as $location): ?>
                        <tr>
                            <td>
                                <strong><?php echo htmlspecialchars($location['location_name']); ?></strong>
                                <?php if ($location['is_primary']): ?>
                                    <span class="status-badge status-primary">PRIMARY</span>
                                <?php endif; ?>
                            </td>
                            <td class="address-display">
                                <?php
                                $address_parts = [];
                                if (!empty($location['street_address'])) $address_parts[] = $location['street_address'];
                                if (!empty($location['city'])) $address_parts[] = $location['city'];
                                if (!empty($location['state'])) $address_parts[] = $location['state'];
                                if (!empty($location['zip_code'])) $address_parts[] = $location['zip_code'];
                                if (!empty($location['country']) && $location['country'] !== 'USA') $address_parts[] = $location['country'];
                                
                                if (!empty($address_parts)) {
                                    echo htmlspecialchars(implode(', ', $address_parts));
                                } else {
                                    echo '<span style="color: #999;">No address specified</span>';
                                }
                                ?>
                            </td>
                            <td>
                                <?php if ($location['is_primary']): ?>
                                    <span class="status-badge status-primary">Primary</span>
                                <?php else: ?>
                                    <span style="color: #666;">Secondary</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="status-badge <?php echo $location['is_active'] ? 'status-active' : 'status-inactive'; ?>">
                                    <?php echo $location['is_active'] ? 'Active' : 'Inactive'; ?>
                                </span>
                            </td>
                            <td>
                                <div class="dropdown">
                                    <button class="dropdown-toggle" onclick="toggleDropdown(event, 'dropdown-menu-<?php echo $location['id']; ?>')">
                                        Actions
                                    </button>
                                    <div id="dropdown-menu-<?php echo $location['id']; ?>" class="dropdown-menu">
                                        <a href="edit_manufacturer_location.php?manufacturer_id=<?php echo $manufacturer_id; ?>&id=<?php echo $location['id']; ?>" class="dropdown-item edit">Edit</a>
                                        <?php if (!$location['is_primary']): ?>
                                            <a href="javascript:void(0);" onclick="confirmSetPrimary('<?php echo htmlspecialchars($location['location_name'], ENT_QUOTES); ?>', <?php echo $location['id']; ?>)" class="dropdown-item primary">Set as Primary</a>
                                        <?php endif; ?>
                                        <?php if (!$location['is_primary']): ?>
                                            <a href="javascript:void(0);" onclick="confirmDelete('<?php echo htmlspecialchars($location['location_name'], ENT_QUOTES); ?>', <?php echo $location['id']; ?>)" class="dropdown-item delete">Delete</a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="5" style="text-align: center; padding: 20px; color: #666;">
                            No locations found. <a href="add_manufacturer_location.php?manufacturer_id=<?php echo $manufacturer_id; ?>">Add the first location</a>
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</main>
</body>
</html> 