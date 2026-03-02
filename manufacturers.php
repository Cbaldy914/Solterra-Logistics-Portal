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
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* Page Header Card */
        .page-header-card {
            background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%);
            border-radius: 24px;
            padding: 32px;
            margin-bottom: 32px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.06);
            border: 1px solid rgba(72, 140, 154, 0.08);
            position: relative;
            overflow: hidden;
        }
        .page-header-card::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0;
            height: 4px;
            background: linear-gradient(90deg, #488C9A 0%, #293E4C 100%);
        }
        .page-header-content {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 20px;
        }
        .page-header-card h1 {
            font-size: 2.2em;
            font-weight: 700;
            background: linear-gradient(135deg, #293E4C 0%, #488C9A 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            margin: 0 0 8px 0;
            line-height: 1.2;
        }
        .page-header-card .subtitle {
            color: #6c757d;
            font-size: 1.05em;
            font-weight: 500;
            margin: 0;
        }
        .btn-add-new {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 12px 24px;
            background: linear-gradient(135deg, #488C9A 0%, #3a7a87 100%);
            color: white;
            text-decoration: none;
            border: none;
            border-radius: 12px;
            cursor: pointer;
            font-weight: 600;
            font-size: 0.95rem;
            transition: all 0.3s ease;
            box-shadow: 0 4px 12px rgba(72, 140, 154, 0.3);
        }
        .btn-add-new:hover {
            transform: translateY(-1px);
            box-shadow: 0 6px 20px rgba(72, 140, 154, 0.4);
            color: white;
        }

        /* Table Card */
        .table-card {
            background: #fff;
            border-radius: 16px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.06);
            overflow: hidden;
            border: 1px solid #e9ecef;
            margin-bottom: 24px;
        }
        .table-card-header {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 20px 24px;
            background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%);
            border-bottom: 1px solid #e9ecef;
        }
        .table-card-header .icon-badge {
            background: linear-gradient(135deg, #488C9A 0%, #3a7a87 100%);
            color: #fff;
            width: 36px; height: 36px;
            border-radius: 10px;
            display: flex; align-items: center; justify-content: center;
            font-size: 1rem;
        }
        .table-card-header h2 {
            margin: 0;
            font-size: 1.15rem;
            font-weight: 600;
            color: #293E4C;
        }
        .table-card .table-responsive { padding: 0; }
        .table-card table { margin: 0; border-radius: 0; }

        /* Badges */
        .status-badge {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.8em;
            font-weight: 600;
            display: inline-block;
        }
        .status-active {
            background: linear-gradient(135deg, #d4edda, #c3e6cb);
            color: #155724;
        }
        .status-inactive {
            background: linear-gradient(135deg, #f8d7da, #f5c6cb);
            color: #721c24;
        }
        .status-pending {
            background: linear-gradient(135deg, #fff3cd, #ffeeba);
            color: #856404;
        }

        /* Action Buttons */
        .action-buttons.edit {
            background: linear-gradient(135deg, #488C9A, #3a7a87);
            color: white;
            padding: 6px 14px;
            text-decoration: none;
            border-radius: 8px;
            font-size: 0.85em;
            font-weight: 600;
            transition: all 0.2s ease;
        }
        .action-buttons.edit:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(72, 140, 154, 0.3);
        }
        .actions-cell { position: relative; text-align: center; width: 60px; }
        .dropdown { position: relative; display: inline-block; }
        .kebab-trigger {
            background: none;
            border: 1px solid transparent;
            color: #6c757d;
            padding: 6px 8px;
            cursor: pointer;
            font-size: 1.3em;
            border-radius: 8px;
            transition: all 0.2s ease;
            line-height: 1;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }
        .kebab-trigger:hover {
            background-color: #e9ecef;
            color: #293E4C;
            border-color: #dee2e6;
        }
        .dropdown-menu {
            display: none;
            position: fixed;
            background: white;
            min-width: 160px;
            box-shadow: 0 12px 28px rgba(0,0,0,0.12), 0 4px 10px rgba(0,0,0,0.08);
            border-radius: 10px;
            z-index: 9999;
            border: 1px solid rgba(0,0,0,0.06);
            padding: 4px;
            backdrop-filter: blur(8px);
        }
        .dropdown-menu.show {
            display: block;
            animation: dropdownFadeIn 0.15s ease-out;
        }
        @keyframes dropdownFadeIn {
            from { opacity: 0; transform: translateY(-4px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .dropdown-item {
            display: flex;
            align-items: center;
            gap: 8px;
            width: 100%;
            padding: 10px 14px;
            text-decoration: none;
            color: #333;
            border: none;
            background: none;
            text-align: left;
            cursor: pointer;
            font-size: 0.9em;
            border-radius: 6px;
            transition: background-color 0.15s ease;
        }
        .dropdown-item:hover { background-color: #f0f7f8; }
        .dropdown-item.edit { color: #293E4C; font-weight: 500; }
        .dropdown-item.delete { color: #dc3545; font-weight: 500; }
        .dropdown-item.delete:hover { background-color: #fdf0f0; }

        /* Alert Messages */
        .error-message, .success-message {
            padding: 16px 20px;
            border-radius: 12px;
            margin-bottom: 20px;
            font-weight: 500;
        }
        .error-message {
            color: #721c24;
            background: linear-gradient(135deg, #f8d7da, #f5c6cb);
            border: 1px solid #f1b0b7;
        }
        .success-message {
            color: #155724;
            background: linear-gradient(135deg, #d4edda, #c3e6cb);
            border: 1px solid #b1dfbb;
        }
        .contact-info { font-size: 0.9em; color: #666; }
        .website-link {
            color: #488C9A;
            text-decoration: none;
            font-weight: 500;
        }
        .website-link:hover { text-decoration: underline; }

        @media (max-width: 768px) {
            .page-header-card { padding: 24px; }
            .page-header-card h1 { font-size: 1.6em; }
            .page-header-content { flex-direction: column; align-items: flex-start; }
        }
    </style>
    <script>
        function confirmDelete(manufacturerName, manufacturerId) {
            if (confirm(`Are you sure you want to delete the manufacturer "${manufacturerName}"? This action cannot be undone.`)) {
                window.location.href = `manufacturers.php?action=delete&id=${manufacturerId}`;
            }
        }

        function toggleDropdown(event, dropdownId) {
            event.stopPropagation();
            document.querySelectorAll('.dropdown-menu.show').forEach(menu => {
                if (menu.id !== dropdownId) menu.classList.remove('show');
            });
            const dropdown = document.getElementById(dropdownId);
            const isOpen = dropdown.classList.contains('show');
            if (isOpen) { dropdown.classList.remove('show'); return; }

            const trigger = event.currentTarget;
            const rect = trigger.getBoundingClientRect();
            dropdown.style.visibility = 'hidden';
            dropdown.classList.add('show');
            const menuHeight = dropdown.offsetHeight;
            const menuWidth = dropdown.offsetWidth;
            dropdown.style.visibility = '';

            const spaceBelow = window.innerHeight - rect.bottom;
            if (spaceBelow < menuHeight + 8 && rect.top > menuHeight + 8) {
                dropdown.style.top = (rect.top - menuHeight - 4) + 'px';
            } else {
                dropdown.style.top = (rect.bottom + 4) + 'px';
            }
            dropdown.style.left = Math.max(8, rect.right - menuWidth) + 'px';
        }

        document.addEventListener('click', function() {
            document.querySelectorAll('.dropdown-menu.show').forEach(menu => menu.classList.remove('show'));
        });
        window.addEventListener('scroll', function() {
            document.querySelectorAll('.dropdown-menu.show').forEach(menu => menu.classList.remove('show'));
        }, true);
    </script>
</head>
<body>
<?php include 'header.php'; ?>
<main>
    <?php require_once 'components/breadcrumbs.php'; echo slp_render_breadcrumbs(['current_label' => 'Manage Manufacturers']); ?>

    <div class="page-header-card">
        <div class="page-header-content">
            <div>
                <h1>Manage Manufacturers</h1>
                <p class="subtitle">View, add, and manage your manufacturing partners.</p>
            </div>
            <?php $primary_action_label = ($role === 'customer_admin') ? 'Request Manufacturer' : 'Add New Manufacturer'; ?>
            <a href="add_manufacturer.php" class="btn-add-new"><i class="fas fa-plus-circle"></i> <?php echo htmlspecialchars($primary_action_label); ?></a>
        </div>
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
        <div class="table-card">
        <div class="table-card-header">
            <div class="icon-badge"><i class="fas fa-clipboard-list"></i></div>
            <h2>Manufacturer Requests</h2>
        </div>
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
        </div>
    <?php endif; ?>

    <?php if (!empty($location_requests)): ?>
        <div class="table-card">
        <div class="table-card-header">
            <div class="icon-badge"><i class="fas fa-map-pin"></i></div>
            <h2>Location Requests</h2>
        </div>
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
        </div>
    <?php endif; ?>

    <div class="table-card">
    <div class="table-card-header">
        <div class="icon-badge"><i class="fas fa-industry"></i></div>
        <h2>All Manufacturers</h2>
    </div>
    <div class="table-responsive">
        <table>
            <thead>
                <tr>
                    <th>Manufacturer</th>
                    <th>Contact Information</th>
                    <th>Address</th>
                    <th>Website</th>
                    <th>Status</th>
                    <th style="width:60px;"></th>
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
                            <td class="actions-cell" onclick="event.stopPropagation();">
                                <div class="dropdown">
                                    <button class="kebab-trigger" onclick="toggleDropdown(event, 'dropdown-menu-<?php echo $manufacturer['id']; ?>')" title="More actions">&#8942;</button>
                                    <div id="dropdown-menu-<?php echo $manufacturer['id']; ?>" class="dropdown-menu">
                                        <a href="manufacturer_locations.php?manufacturer_id=<?php echo $manufacturer['id']; ?>" class="dropdown-item">Manage Locations</a>
                                        <?php if (in_array($role, ['admin', 'global_admin'], true)): ?>
                                        <a href="edit_manufacturer.php?id=<?php echo $manufacturer['id']; ?>" class="dropdown-item edit">Edit</a>
                                        <a href="javascript:void(0);" onclick="confirmDelete('<?php echo htmlspecialchars($manufacturer['name'], ENT_QUOTES); ?>', <?php echo $manufacturer['id']; ?>)" class="dropdown-item delete">Delete</a>
                                        <?php endif; ?>
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
    </div>
</main>
</body>
</html> 
