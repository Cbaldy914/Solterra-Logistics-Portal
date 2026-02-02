<?php
session_name("logistics_session");
session_start();

// Ensure user has role global_admin, admin, or customer_admin
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'global_admin', 'customer_admin'])) {
    header("Location: unauthorized.php");
    exit();
}

require_once '../config.php';
$conn = getDBConnection();

// Unified dashboard link
$dashboard_link = 'dashboard.php';

$role = $_SESSION['role'] ?? '';
$user_id = $_SESSION['user_id'] ?? 0;
$account_id = null;

if ($role !== 'global_admin') {
    $stmtAccount = $conn->prepare("SELECT account_id FROM customer_account_users WHERE user_id = ? AND role IN ('admin', 'customer_admin') LIMIT 1");
    if ($stmtAccount) {
        $stmtAccount->bind_param("i", $user_id);
        $stmtAccount->execute();
        $stmtAccount->bind_result($account_id);
        $stmtAccount->fetch();
        $stmtAccount->close();
    }
    if (!$account_id) {
        die("No valid account found for this user.");
    }
}

$manufacturers = [];
$manufacturer_requests = [];
$location_requests = [];
$errorMessage = '';
$successMessage = '';

// Handle delete action
if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['id'])) {
    try {
        if (!in_array($role, ['admin', 'global_admin'], true)) {
            throw new Exception('You do not have permission to delete manufacturers.');
        }
        $manufacturer_id = intval($_GET['id']);
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
            
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new Exception("Error preparing manufacturers query: " . $conn->error);
    }
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $manufacturers[] = $row;
        }
        $stmt->close();
    } else {
        throw new Exception("Error fetching manufacturers: " . $conn->error);
    }

} catch (Exception $e) {
    $errorMessage = $e->getMessage();
}

try {
    $request_where = "WHERE mr.status = 'pending'";
    $request_params = [];
    $request_types = '';
    if ($role === 'admin') {
        $request_where .= " AND mr.account_id = ?";
        $request_params[] = $account_id;
        $request_types = 'i';
    } elseif ($role === 'customer_admin') {
        $request_where = "WHERE mr.account_id = ?";
        $request_params[] = $account_id;
        $request_types = 'i';
    }

    $request_sql = "SELECT mr.id, mr.name, mr.status, mr.created_at, mr.rejection_reason,
                           ca.name AS account_name,
                           u.username, u.first_name, u.last_name
                    FROM manufacturer_requests mr
                    JOIN customer_accounts ca ON ca.id = mr.account_id
                    JOIN users u ON u.id = mr.requested_by
                    $request_where
                    ORDER BY mr.created_at DESC";
    $stmtReq = $conn->prepare($request_sql);
    if ($stmtReq) {
        if (!empty($request_params)) {
            $stmtReq->bind_param($request_types, ...$request_params);
        }
        $stmtReq->execute();
        $resultReq = $stmtReq->get_result();
        while ($row = $resultReq->fetch_assoc()) {
            $manufacturer_requests[] = $row;
        }
        $stmtReq->close();
    }

    $location_where = "WHERE mlr.status = 'pending'";
    $location_params = [];
    $location_types = '';
    if ($role === 'admin') {
        $location_where .= " AND mlr.account_id = ?";
        $location_params[] = $account_id;
        $location_types = 'i';
    } elseif ($role === 'customer_admin') {
        $location_where = "WHERE mlr.account_id = ?";
        $location_params[] = $account_id;
        $location_types = 'i';
    }

    $location_sql = "SELECT mlr.id, mlr.location_name, mlr.status, mlr.created_at, mlr.rejection_reason,
                            mlr.street_address, mlr.city, mlr.state, mlr.zip_code, mlr.country,
                            m.name AS manufacturer_name,
                            ca.name AS account_name,
                            u.username, u.first_name, u.last_name
                     FROM manufacturer_location_requests mlr
                     JOIN manufacturers m ON m.id = mlr.manufacturer_id
                     JOIN customer_accounts ca ON ca.id = mlr.account_id
                     JOIN users u ON u.id = mlr.requested_by
                     $location_where
                     ORDER BY mlr.created_at DESC";
    $stmtLoc = $conn->prepare($location_sql);
    if ($stmtLoc) {
        if (!empty($location_params)) {
            $stmtLoc->bind_param($location_types, ...$location_params);
        }
        $stmtLoc->execute();
        $resultLoc = $stmtLoc->get_result();
        while ($row = $resultLoc->fetch_assoc()) {
            $location_requests[] = $row;
        }
        $stmtLoc->close();
    }
} catch (Exception $e) {
    if ($errorMessage === '') {
        $errorMessage = $e->getMessage();
    }
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
        .status-pending {
            background-color: #fff3cd;
            color: #856404;
        }
        .section-title {
            margin: 30px 0 15px;
            font-size: 1.2rem;
            font-weight: 600;
            color: #333;
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
    <?php require_once 'components/breadcrumbs.php'; echo slp_render_breadcrumbs(['current_label' => 'Manage Manufacturers']); ?>
    
    <div class="header-container">
        <h1>Manage Manufacturers</h1>
        <?php $primary_action_label = ($role === 'customer_admin') ? 'Request Manufacturer' : 'Add New Manufacturer'; ?>
        <a href="add_manufacturer.php" class="action-buttons add-new"><?php echo htmlspecialchars($primary_action_label); ?></a>
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

    <?php if (!empty($manufacturer_requests)): ?>
        <div class="section-title">Manufacturer Requests</div>
        <div class="table-responsive">
            <table>
                <thead>
                    <tr>
                        <th>Manufacturer</th>
                        <?php if ($role === 'global_admin'): ?>
                            <th>Account</th>
                        <?php endif; ?>
                        <th>Requested By</th>
                        <th>Submitted</th>
                        <th>Status</th>
                        <?php if (in_array($role, ['admin', 'global_admin'], true)): ?>
                            <th>Actions</th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($manufacturer_requests as $request): ?>
                        <?php
                            $requested_by = trim(($request['first_name'] ?? '') . ' ' . ($request['last_name'] ?? ''));
                            if ($requested_by === '') {
                                $requested_by = $request['username'] ?? 'Unknown';
                            }
                            $status_class = $request['status'] === 'approved' ? 'status-active' : ($request['status'] === 'rejected' ? 'status-inactive' : 'status-pending');
                        ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($request['name']); ?></strong></td>
                            <?php if ($role === 'global_admin'): ?>
                                <td><?php echo htmlspecialchars($request['account_name']); ?></td>
                            <?php endif; ?>
                            <td><?php echo htmlspecialchars($requested_by); ?></td>
                            <td><?php echo htmlspecialchars(date('M j, Y', strtotime($request['created_at']))); ?></td>
                            <td>
                                <span class="status-badge <?php echo $status_class; ?>">
                                    <?php echo htmlspecialchars(ucfirst($request['status'])); ?>
                                </span>
                                <?php if ($role === 'customer_admin' && $request['status'] === 'rejected' && !empty($request['rejection_reason'])): ?>
                                    <br><small style="color: #666;">Reason: <?php echo htmlspecialchars($request['rejection_reason']); ?></small>
                                <?php endif; ?>
                            </td>
                            <?php if (in_array($role, ['admin', 'global_admin'], true)): ?>
                                <td>
                                    <a href="add_manufacturer.php?request_id=<?php echo (int)$request['id']; ?>" class="action-buttons edit">Review</a>
                                </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <?php if (!empty($location_requests)): ?>
        <div class="section-title">Location Requests</div>
        <div class="table-responsive">
            <table>
                <thead>
                    <tr>
                        <th>Manufacturer</th>
                        <th>Location</th>
                        <?php if ($role === 'global_admin'): ?>
                            <th>Account</th>
                        <?php endif; ?>
                        <th>Requested By</th>
                        <th>Submitted</th>
                        <th>Status</th>
                        <?php if (in_array($role, ['admin', 'global_admin'], true)): ?>
                            <th>Actions</th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($location_requests as $request): ?>
                        <?php
                            $requested_by = trim(($request['first_name'] ?? '') . ' ' . ($request['last_name'] ?? ''));
                            if ($requested_by === '') {
                                $requested_by = $request['username'] ?? 'Unknown';
                            }
                            $status_class = $request['status'] === 'approved' ? 'status-active' : ($request['status'] === 'rejected' ? 'status-inactive' : 'status-pending');
                            $address_parts = array_filter([
                                $request['street_address'] ?? '',
                                $request['city'] ?? '',
                                $request['state'] ?? '',
                                $request['zip_code'] ?? ''
                            ]);
                            $formatted_address = $address_parts ? implode(', ', $address_parts) : 'Address not provided';
                        ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($request['manufacturer_name']); ?></strong></td>
                            <td>
                                <?php echo htmlspecialchars($request['location_name']); ?><br>
                                <small style="color: #666;"><?php echo htmlspecialchars($formatted_address); ?></small>
                            </td>
                            <?php if ($role === 'global_admin'): ?>
                                <td><?php echo htmlspecialchars($request['account_name']); ?></td>
                            <?php endif; ?>
                            <td><?php echo htmlspecialchars($requested_by); ?></td>
                            <td><?php echo htmlspecialchars(date('M j, Y', strtotime($request['created_at']))); ?></td>
                            <td>
                                <span class="status-badge <?php echo $status_class; ?>">
                                    <?php echo htmlspecialchars(ucfirst($request['status'])); ?>
                                </span>
                                <?php if ($role === 'customer_admin' && $request['status'] === 'rejected' && !empty($request['rejection_reason'])): ?>
                                    <br><small style="color: #666;">Reason: <?php echo htmlspecialchars($request['rejection_reason']); ?></small>
                                <?php endif; ?>
                            </td>
                            <?php if (in_array($role, ['admin', 'global_admin'], true)): ?>
                                <td>
                                    <a href="add_manufacturer_location.php?request_id=<?php echo (int)$request['id']; ?>" class="action-buttons edit">Review</a>
                                </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
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
                                <?php if (in_array($role, ['admin', 'global_admin'], true)): ?>
                                    <div class="dropdown">
                                        <button class="dropdown-toggle" onclick="toggleDropdown(event, 'dropdown-menu-<?php echo $manufacturer['id']; ?>')">
                                            Actions
                                        </button>
                                        <div id="dropdown-menu-<?php echo $manufacturer['id']; ?>" class="dropdown-menu">
                                            <a href="edit_manufacturer.php?id=<?php echo $manufacturer['id']; ?>" class="dropdown-item edit">Edit</a>
                                            <a href="manufacturer_locations.php?manufacturer_id=<?php echo $manufacturer['id']; ?>" class="dropdown-item">Manage Locations</a>
                                            <a href="javascript:void(0);" onclick="confirmDelete('<?php echo htmlspecialchars($manufacturer['name'], ENT_QUOTES); ?>', <?php echo $manufacturer['id']; ?>)" class="dropdown-item delete">Delete</a>
                                        </div>
                                    </div>
                                <?php else: ?>
                                    <a href="manufacturer_locations.php?manufacturer_id=<?php echo $manufacturer['id']; ?>" class="action-buttons edit">View Locations</a>
                                <?php endif; ?>
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
