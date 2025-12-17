<?php
session_name("logistics_session");
session_start();

// Only global admin can access this page
if ($_SESSION['role'] !== 'global_admin') {
    header("Location: unauthorized");
    exit();
}

require_once '../config.php';
$conn = getDBConnection();
if (!$conn) {
    die("Connection failed");
}

// Determine active tab from URL parameter
$activeTab = isset($_GET['tab']) ? $_GET['tab'] : 'users';
if (!in_array($activeTab, ['users', 'accounts', 'invitations'])) {
    $activeTab = 'users';
}

// Fetch all users with their account/role info
$users = [];
$sql = "
    SELECT
        u.id AS user_id,
        u.username,
        u.email,
        u.first_name,
        u.last_name,
        u.created_at,
        cau.role AS account_role,
        cau.account_id,
        ca.name AS account_name
    FROM users u
    LEFT JOIN customer_account_users cau ON u.id = cau.user_id
    LEFT JOIN customer_accounts ca ON cau.account_id = ca.id
    ORDER BY u.id ASC
";
$result = $conn->query($sql);
if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $users[] = $row;
    }
}
if ($result) $result->close();

// Fetch all accounts with user count
$accounts = [];
$sql = "
    SELECT
        ca.id,
        ca.name,
        ca.created_at,
        COUNT(cau.user_id) AS user_count
    FROM customer_accounts ca
    LEFT JOIN customer_account_users cau ON ca.id = cau.account_id
    GROUP BY ca.id
    ORDER BY ca.name ASC
";
$result = $conn->query($sql);
if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $accounts[] = $row;
    }
}
if ($result) $result->close();

// Check if user_invitations table exists and fetch pending invitations
$invitations = [];
$invitationsTableExists = false;
$checkTable = $conn->query("SHOW TABLES LIKE 'user_invitations'");
if ($checkTable && $checkTable->num_rows > 0) {
    $invitationsTableExists = true;
    $sql = "
        SELECT
            ui.id,
            ui.email,
            ui.token,
            ui.account_id,
            ui.role,
            ui.invited_by,
            ui.created_at,
            ui.expires_at,
            ca.name AS account_name,
            inv_user.username AS invited_by_username
        FROM user_invitations ui
        LEFT JOIN customer_accounts ca ON ui.account_id = ca.id
        LEFT JOIN users inv_user ON ui.invited_by = inv_user.id
        WHERE ui.used_at IS NULL AND ui.expires_at > NOW()
        ORDER BY ui.created_at DESC
    ";
    $result = $conn->query($sql);
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $invitations[] = $row;
        }
    }
    if ($result) $result->close();
}
if ($checkTable) $checkTable->close();

// Fetch all accounts for dropdowns
$accountOptions = [];
$stmtAcc = $conn->prepare("SELECT id, name FROM customer_accounts ORDER BY name ASC");
$stmtAcc->execute();
$resultAcc = $stmtAcc->get_result();
while ($row = $resultAcc->fetch_assoc()) {
    $accountOptions[] = $row;
}
$stmtAcc->close();

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Management</title>
    <link rel="stylesheet" href="portal.css">
    <link rel="icon" href="pictures/favicon.png" type="image/x-icon">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        .container { padding: 20px; }

        /* Modern Page Header */
        .page-header {
            background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%);
            border-radius: 24px;
            padding: 32px;
            margin-bottom: 24px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.06);
            border: 1px solid rgba(72, 140, 154, 0.08);
            position: relative;
            overflow: hidden;
        }
        .page-header::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, #488C9A 0%, #293E4C 100%);
        }
        .page-header h1 {
            font-size: 2.2em;
            font-weight: 700;
            background: linear-gradient(135deg, #293E4C 0%, #488C9A 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            margin: 0 0 8px 0;
            line-height: 1.2;
        }
        .page-header p {
            color: #6c757d;
            margin: 0;
            font-size: 1rem;
        }

        /* Tabs */
        .tabs-nav {
            display: flex;
            gap: 8px;
            margin-bottom: 20px;
            border-bottom: 2px solid #e9ecef;
            padding-bottom: 0;
        }
        .tab-btn {
            padding: 14px 24px;
            border: none;
            background: transparent;
            color: #6c757d;
            font-weight: 600;
            font-size: 15px;
            cursor: pointer;
            border-bottom: 3px solid transparent;
            margin-bottom: -2px;
            transition: all 0.2s ease;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .tab-btn:hover {
            color: #488C9A;
            background: rgba(72, 140, 154, 0.05);
        }
        .tab-btn.active {
            color: #488C9A;
            border-bottom-color: #488C9A;
        }
        .tab-btn .badge {
            background: #e9ecef;
            color: #6c757d;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 700;
        }
        .tab-btn.active .badge {
            background: #488C9A;
            color: white;
        }

        /* Tab Content */
        .tab-content { display: none; }
        .tab-content.active { display: block; }

        /* Cards */
        .card {
            background: #fff;
            border: 1px solid #e8edf2;
            border-radius: 14px;
            box-shadow: 0 12px 36px rgba(0, 0, 0, 0.06);
            overflow: hidden;
        }
        .card-header {
            padding: 16px 20px;
            border-bottom: 1px solid #eef2f6;
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: linear-gradient(180deg, #ffffff, #fbfdfe);
        }
        .card-header h3 {
            margin: 0;
            font-size: 1.1rem;
            font-weight: 700;
            color: #293E4C;
        }
        .card-body { padding: 20px; }

        /* Tables */
        .data-table {
            width: 100%;
            border-collapse: collapse;
        }
        .data-table th,
        .data-table td {
            padding: 14px 16px;
            text-align: left;
            border-bottom: 1px solid #e9ecef;
        }
        .data-table th {
            background: #f8f9fa;
            color: #293E4C;
            font-weight: 600;
            font-size: 13px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .data-table tbody tr:hover {
            background: #f8f9fa;
        }
        .data-table tbody tr:last-child td {
            border-bottom: none;
        }

        /* Role Badges */
        .role-badge {
            display: inline-flex;
            align-items: center;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }
        .role-global_admin { background: #293E4C; color: #fff; }
        .role-admin { background: #488C9A; color: #fff; }
        .role-customer_admin { background: #3498db; color: #fff; }
        .role-user { background: #e9ecef; color: #495057; }
        .role-DDPm { background: #e67e22; color: #fff; }

        /* Action Buttons */
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 16px;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
            text-decoration: none;
            border: none;
        }
        .btn-primary {
            background: linear-gradient(135deg, #488C9A, #3A6E7F);
            color: #fff;
            box-shadow: 0 4px 12px rgba(72, 140, 154, 0.25);
        }
        .btn-primary:hover {
            transform: translateY(-1px);
            box-shadow: 0 6px 16px rgba(72, 140, 154, 0.35);
        }
        .btn-secondary {
            background: #f8f9fa;
            border: 1px solid #e9ecef;
            color: #495057;
        }
        .btn-secondary:hover {
            background: #e9ecef;
        }
        .btn-danger {
            background: #dc3545;
            color: #fff;
        }
        .btn-danger:hover {
            background: #c82333;
        }
        .btn-sm {
            padding: 6px 12px;
            font-size: 13px;
        }

        /* Action buttons in table */
        .action-btns {
            display: flex;
            gap: 8px;
        }

        /* Search Box */
        .search-box {
            display: flex;
            align-items: center;
            gap: 8px;
            background: #f8f9fa;
            border: 1px solid #e9ecef;
            border-radius: 8px;
            padding: 8px 12px;
            width: 280px;
        }
        .search-box input {
            border: none;
            background: transparent;
            outline: none;
            flex: 1;
            font-size: 14px;
        }
        .search-box svg {
            color: #6c757d;
            width: 18px;
            height: 18px;
        }

        /* Modal Overlay */
        .modal-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            z-index: 9998;
            opacity: 0;
            transition: opacity 0.3s ease;
        }
        .modal-overlay.active {
            display: block;
            opacity: 1;
        }

        /* Modal - Override portal.css styles */
        .modal {
            display: none;
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%) scale(0.9);
            background: #fff;
            border-radius: 16px;
            box-shadow: 0 24px 64px rgba(0, 0, 0, 0.2);
            z-index: 9999;
            width: 90%;
            max-width: 500px;
            max-height: 90vh;
            overflow-y: auto;
            opacity: 0;
            transition: all 0.3s ease;
            /* Override portal.css modal styles */
            height: auto !important;
            margin: 0 !important;
            padding: 0 !important;
        }
        .modal.active {
            display: block;
            opacity: 1;
            transform: translate(-50%, -50%) scale(1);
        }
        .modal-header {
            padding: 20px 24px;
            border-bottom: 1px solid #e9ecef;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .modal-header h3 {
            margin: 0;
            font-size: 1.25rem;
            font-weight: 700;
            color: #293E4C;
        }
        .modal-close {
            background: none;
            border: none;
            font-size: 24px;
            color: #6c757d;
            cursor: pointer;
            padding: 4px;
            line-height: 1;
        }
        .modal-close:hover {
            color: #293E4C;
        }
        .modal-body {
            padding: 24px;
        }
        .modal-footer {
            padding: 16px 24px;
            border-top: 1px solid #e9ecef;
            display: flex;
            justify-content: flex-end;
            gap: 12px;
        }

        /* Form Styles */
        .form-group {
            margin-bottom: 20px;
        }
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #293E4C;
            font-size: 14px;
        }
        .form-group .required {
            color: #dc3545;
        }
        .form-control {
            width: 100%;
            padding: 10px 14px;
            border: 1px solid #e2e9ef;
            border-radius: 8px;
            font-size: 15px;
            transition: border-color 0.2s, box-shadow 0.2s;
            box-sizing: border-box; /* Prevents input from extending past container */
        }
        .form-control:focus {
            border-color: #488C9A;
            box-shadow: 0 0 0 3px rgba(72, 140, 154, 0.15);
            outline: none;
        }
        .form-hint {
            color: #6c757d;
            font-size: 12px;
            margin-top: 4px;
        }

        /* Alert Messages */
        .alert {
            padding: 14px 18px;
            border-radius: 10px;
            margin-bottom: 20px;
            font-weight: 500;
        }
        .alert-success {
            background: #d4edda;
            color: #155724;
            border-left: 4px solid #28a745;
        }
        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border-left: 4px solid #dc3545;
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #6c757d;
        }
        .empty-state svg {
            width: 64px;
            height: 64px;
            color: #dee2e6;
            margin-bottom: 16px;
        }
        .empty-state p {
            margin: 0 0 20px 0;
            font-size: 16px;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .card-header {
                flex-direction: column;
                gap: 12px;
                align-items: flex-start;
            }
            .search-box {
                width: 100%;
            }
            .data-table {
                display: block;
                overflow-x: auto;
            }
            .tabs-nav {
                overflow-x: auto;
            }
        }
    </style>
</head>
<body>
<?php include 'header.php'; ?>

<main class="container">
    <?php
        require_once 'components/breadcrumbs.php';
        echo slp_render_breadcrumbs(['current_label' => 'Admin Management']);
    ?>

    <div class="page-header">
        <h1>User & Account Management</h1>
        <p>Manage users, accounts, and invitations across your organization</p>
    </div>

    <!-- Alert Messages (populated via JavaScript) -->
    <div id="alertContainer"></div>

    <!-- Tabs Navigation -->
    <div class="tabs-nav">
        <button class="tab-btn <?php echo $activeTab === 'users' ? 'active' : ''; ?>" data-tab="users">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path>
                <circle cx="9" cy="7" r="4"></circle>
                <path d="M23 21v-2a4 4 0 0 0-3-3.87"></path>
                <path d="M16 3.13a4 4 0 0 1 0 7.75"></path>
            </svg>
            Users
            <span class="badge"><?php echo count($users); ?></span>
        </button>
        <button class="tab-btn <?php echo $activeTab === 'accounts' ? 'active' : ''; ?>" data-tab="accounts">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path>
                <polyline points="9,22 9,12 15,12 15,22"></polyline>
            </svg>
            Accounts
            <span class="badge"><?php echo count($accounts); ?></span>
        </button>
        <button class="tab-btn <?php echo $activeTab === 'invitations' ? 'active' : ''; ?>" data-tab="invitations">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"></path>
                <polyline points="22,6 12,13 2,6"></polyline>
            </svg>
            Invitations
        </button>
    </div>

    <!-- Users Tab -->
    <div class="tab-content <?php echo $activeTab === 'users' ? 'active' : ''; ?>" id="tab-users">
        <div class="card">
            <div class="card-header">
                <h3>All Users</h3>
                <div style="display: flex; gap: 12px; align-items: center;">
                    <div class="search-box">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <circle cx="11" cy="11" r="8"></circle>
                            <path d="M21 21l-4.35-4.35"></path>
                        </svg>
                        <input type="text" id="userSearch" placeholder="Search users...">
                    </div>
                    <button class="btn btn-primary" onclick="openModal('addUserModal')">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <line x1="12" y1="5" x2="12" y2="19"></line>
                            <line x1="5" y1="12" x2="19" y2="12"></line>
                        </svg>
                        Add User
                    </button>
                </div>
            </div>
            <div class="card-body" style="padding: 0;">
                <?php if (empty($users)): ?>
                    <div class="empty-state">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                            <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path>
                            <circle cx="9" cy="7" r="4"></circle>
                        </svg>
                        <p>No users found</p>
                        <button class="btn btn-primary" onclick="openModal('addUserModal')">Add Your First User</button>
                    </div>
                <?php else: ?>
                    <table class="data-table" id="usersTable">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Username</th>
                                <th>Name</th>
                                <th>Email</th>
                                <th>Account</th>
                                <th>Role</th>
                                <th>Created</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($users as $user): ?>
                            <tr data-user-id="<?php echo $user['user_id']; ?>">
                                <td><?php echo htmlspecialchars($user['user_id']); ?></td>
                                <td><strong><?php echo htmlspecialchars($user['username']); ?></strong></td>
                                <td><?php echo htmlspecialchars(trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')) ?: '-'); ?></td>
                                <td><?php echo htmlspecialchars($user['email'] ?? '-'); ?></td>
                                <td><?php echo htmlspecialchars($user['account_name'] ?? '-'); ?></td>
                                <td>
                                    <?php if ($user['account_role']): ?>
                                        <span class="role-badge role-<?php echo htmlspecialchars($user['account_role']); ?>">
                                            <?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $user['account_role']))); ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="role-badge role-user">-</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo htmlspecialchars(date('M j, Y', strtotime($user['created_at']))); ?></td>
                                <td>
                                    <div class="action-btns">
                                        <button class="btn btn-secondary btn-sm" onclick="editUser(<?php echo htmlspecialchars(json_encode($user)); ?>)">Edit</button>
                                        <?php if ($user['user_id'] != $_SESSION['user_id']): ?>
                                            <button class="btn btn-danger btn-sm" onclick="confirmDeleteUser(<?php echo $user['user_id']; ?>, '<?php echo htmlspecialchars($user['username']); ?>')">Delete</button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Accounts Tab -->
    <div class="tab-content <?php echo $activeTab === 'accounts' ? 'active' : ''; ?>" id="tab-accounts">
        <div class="card">
            <div class="card-header">
                <h3>All Accounts</h3>
                <div style="display: flex; gap: 12px; align-items: center;">
                    <div class="search-box">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <circle cx="11" cy="11" r="8"></circle>
                            <path d="M21 21l-4.35-4.35"></path>
                        </svg>
                        <input type="text" id="accountSearch" placeholder="Search accounts...">
                    </div>
                    <button class="btn btn-primary" onclick="openModal('addAccountModal')">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <line x1="12" y1="5" x2="12" y2="19"></line>
                            <line x1="5" y1="12" x2="19" y2="12"></line>
                        </svg>
                        Add Account
                    </button>
                </div>
            </div>
            <div class="card-body" style="padding: 0;">
                <?php if (empty($accounts)): ?>
                    <div class="empty-state">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                            <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path>
                        </svg>
                        <p>No accounts found</p>
                        <button class="btn btn-primary" onclick="openModal('addAccountModal')">Add Your First Account</button>
                    </div>
                <?php else: ?>
                    <table class="data-table" id="accountsTable">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Account Name</th>
                                <th>Users</th>
                                <th>Created</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($accounts as $account): ?>
                            <tr data-account-id="<?php echo $account['id']; ?>">
                                <td><?php echo htmlspecialchars($account['id']); ?></td>
                                <td><strong><?php echo htmlspecialchars($account['name']); ?></strong></td>
                                <td>
                                    <span class="role-badge role-user"><?php echo $account['user_count']; ?> user<?php echo $account['user_count'] != 1 ? 's' : ''; ?></span>
                                </td>
                                <td><?php echo htmlspecialchars(date('M j, Y', strtotime($account['created_at']))); ?></td>
                                <td>
                                    <div class="action-btns">
                                        <button class="btn btn-secondary btn-sm" onclick="editAccount(<?php echo htmlspecialchars(json_encode($account)); ?>)">Edit</button>
                                        <button class="btn btn-danger btn-sm" onclick="confirmDeleteAccount(<?php echo $account['id']; ?>, '<?php echo htmlspecialchars($account['name']); ?>')">Delete</button>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Invitations Tab -->
    <div class="tab-content <?php echo $activeTab === 'invitations' ? 'active' : ''; ?>" id="tab-invitations">
        <div class="card">
            <div class="card-header">
                <h3>User Invitations</h3>
                <a href="invite_user.php" class="btn btn-primary">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"></path>
                        <polyline points="22,6 12,13 2,6"></polyline>
                    </svg>
                    Invite User
                </a>
            </div>
            <div class="card-body">
                <div class="empty-state">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                        <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"></path>
                        <polyline points="22,6 12,13 2,6"></polyline>
                    </svg>
                    <p>Use the Invite User page to send email invitations to new users.</p>
                    <p style="color: #6c757d; font-size: 14px; margin-top: 8px;">
                        Invitations create a new user account and send them a password setup link via email.
                    </p>
                    <a href="invite_user.php" class="btn btn-primary" style="margin-top: 16px;">Go to Invite User</a>
                </div>
            </div>
        </div>
    </div>
</main>

<!-- Modal Overlay -->
<div class="modal-overlay" id="modalOverlay" onclick="closeAllModals()"></div>

<!-- Add User Modal -->
<div class="modal" id="addUserModal">
    <div class="modal-header">
        <h3>Add New User</h3>
        <button class="modal-close" onclick="closeModal('addUserModal')">&times;</button>
    </div>
    <form id="addUserForm" onsubmit="submitAddUser(event)">
        <div class="modal-body">
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
                <div class="form-group" style="margin-bottom: 16px;">
                    <label>First Name</label>
                    <input type="text" class="form-control" name="first_name">
                </div>
                <div class="form-group" style="margin-bottom: 16px;">
                    <label>Last Name</label>
                    <input type="text" class="form-control" name="last_name">
                </div>
            </div>
            <div class="form-group">
                <label>Email</label>
                <input type="email" class="form-control" name="email" placeholder="user@example.com">
            </div>
            <div class="form-group">
                <label>Username <span class="required">*</span></label>
                <input type="text" class="form-control" name="username" required>
            </div>
            <div class="form-group">
                <label>Password <span class="required">*</span></label>
                <input type="password" class="form-control" name="password" required>
                <div class="form-hint">Password should be at least 8 characters long.</div>
            </div>
            <div class="form-group">
                <label>Account <span class="required">*</span></label>
                <select class="form-control" name="account_id" required>
                    <option value="">-- Select Account --</option>
                    <?php foreach ($accountOptions as $acc): ?>
                        <option value="<?php echo $acc['id']; ?>"><?php echo htmlspecialchars($acc['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Role <span class="required">*</span></label>
                <select class="form-control" name="role" required>
                    <option value="user">User</option>
                    <option value="customer_admin">Customer Admin</option>
                    <option value="admin">Admin</option>
                    <option value="DDPm">DDPm</option>
                    <option value="global_admin">Global Admin</option>
                </select>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" onclick="closeModal('addUserModal')">Cancel</button>
            <button type="submit" class="btn btn-primary">Add User</button>
        </div>
    </form>
</div>

<!-- Edit User Modal -->
<div class="modal" id="editUserModal">
    <div class="modal-header">
        <h3>Edit User</h3>
        <button class="modal-close" onclick="closeModal('editUserModal')">&times;</button>
    </div>
    <form id="editUserForm" onsubmit="submitEditUser(event)">
        <input type="hidden" name="user_id" id="editUserId">
        <div class="modal-body">
            <div class="form-group">
                <label>Username</label>
                <input type="text" class="form-control" id="editUsername" disabled>
                <div class="form-hint">Username cannot be changed.</div>
            </div>
            <div class="form-group">
                <label>New Password</label>
                <input type="password" class="form-control" name="password">
                <div class="form-hint">Leave blank to keep current password.</div>
            </div>
            <div class="form-group">
                <label>Account <span class="required">*</span></label>
                <select class="form-control" name="account_id" id="editAccountId" required>
                    <option value="">-- Select Account --</option>
                    <?php foreach ($accountOptions as $acc): ?>
                        <option value="<?php echo $acc['id']; ?>"><?php echo htmlspecialchars($acc['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Role <span class="required">*</span></label>
                <select class="form-control" name="role" id="editRole" required>
                    <option value="user">User</option>
                    <option value="customer_admin">Customer Admin</option>
                    <option value="admin">Admin</option>
                    <option value="DDPm">DDPm</option>
                    <option value="global_admin">Global Admin</option>
                </select>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" onclick="closeModal('editUserModal')">Cancel</button>
            <button type="submit" class="btn btn-primary">Save Changes</button>
        </div>
    </form>
</div>

<!-- Add Account Modal -->
<div class="modal" id="addAccountModal">
    <div class="modal-header">
        <h3>Add New Account</h3>
        <button class="modal-close" onclick="closeModal('addAccountModal')">&times;</button>
    </div>
    <form id="addAccountForm" onsubmit="submitAddAccount(event)">
        <div class="modal-body">
            <div class="form-group">
                <label>Account Name <span class="required">*</span></label>
                <input type="text" class="form-control" name="name" required>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" onclick="closeModal('addAccountModal')">Cancel</button>
            <button type="submit" class="btn btn-primary">Add Account</button>
        </div>
    </form>
</div>

<!-- Edit Account Modal -->
<div class="modal" id="editAccountModal">
    <div class="modal-header">
        <h3>Edit Account</h3>
        <button class="modal-close" onclick="closeModal('editAccountModal')">&times;</button>
    </div>
    <form id="editAccountForm" onsubmit="submitEditAccount(event)">
        <input type="hidden" name="account_id" id="editAccountIdField">
        <div class="modal-body">
            <div class="form-group">
                <label>Account Name <span class="required">*</span></label>
                <input type="text" class="form-control" name="name" id="editAccountName" required>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" onclick="closeModal('editAccountModal')">Cancel</button>
            <button type="submit" class="btn btn-primary">Save Changes</button>
        </div>
    </form>
</div>

<!-- Delete Confirmation Modal -->
<div class="modal" id="deleteConfirmModal">
    <div class="modal-header">
        <h3>Confirm Delete</h3>
        <button class="modal-close" onclick="closeModal('deleteConfirmModal')">&times;</button>
    </div>
    <div class="modal-body">
        <p id="deleteConfirmMessage">Are you sure you want to delete this item?</p>
    </div>
    <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="closeModal('deleteConfirmModal')">Cancel</button>
        <button type="button" class="btn btn-danger" id="deleteConfirmBtn">Delete</button>
    </div>
</div>

<script>
// Tab switching
document.querySelectorAll('.tab-btn').forEach(btn => {
    btn.addEventListener('click', function() {
        const tab = this.dataset.tab;

        // Update URL without reload
        const url = new URL(window.location);
        url.searchParams.set('tab', tab);
        window.history.pushState({}, '', url);

        // Update active states
        document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
        document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));

        this.classList.add('active');
        document.getElementById('tab-' + tab).classList.add('active');
    });
});

// Modal functions
function openModal(modalId) {
    document.getElementById('modalOverlay').classList.add('active');
    document.getElementById(modalId).classList.add('active');
}

function closeModal(modalId) {
    document.getElementById('modalOverlay').classList.remove('active');
    document.getElementById(modalId).classList.remove('active');
}

function closeAllModals() {
    document.getElementById('modalOverlay').classList.remove('active');
    document.querySelectorAll('.modal').forEach(m => m.classList.remove('active'));
}

// Show alert
function showAlert(type, message) {
    const container = document.getElementById('alertContainer');
    container.innerHTML = `<div class="alert alert-${type}">${message}</div>`;
    setTimeout(() => {
        container.innerHTML = '';
    }, 5000);
}

// Search functionality
document.getElementById('userSearch').addEventListener('input', function() {
    filterTable('usersTable', this.value);
});

document.getElementById('accountSearch').addEventListener('input', function() {
    filterTable('accountsTable', this.value);
});

function filterTable(tableId, searchTerm) {
    const table = document.getElementById(tableId);
    if (!table) return;

    const rows = table.querySelectorAll('tbody tr');
    const term = searchTerm.toLowerCase();

    rows.forEach(row => {
        const text = row.textContent.toLowerCase();
        row.style.display = text.includes(term) ? '' : 'none';
    });
}

// User CRUD
function submitAddUser(event) {
    event.preventDefault();
    const formData = new FormData(event.target);
    formData.append('action', 'add_user');

    fetch('api/admin_management.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showAlert('success', data.message);
            closeModal('addUserModal');
            setTimeout(() => location.reload(), 1000);
        } else {
            showAlert('error', data.message);
        }
    })
    .catch(err => showAlert('error', 'An error occurred. Please try again.'));
}

function editUser(user) {
    document.getElementById('editUserId').value = user.user_id;
    document.getElementById('editUsername').value = user.username;
    document.getElementById('editAccountId').value = user.account_id || '';
    document.getElementById('editRole').value = user.account_role || 'user';
    openModal('editUserModal');
}

function submitEditUser(event) {
    event.preventDefault();
    const formData = new FormData(event.target);
    formData.append('action', 'update_user');

    fetch('api/admin_management.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showAlert('success', data.message);
            closeModal('editUserModal');
            setTimeout(() => location.reload(), 1000);
        } else {
            showAlert('error', data.message);
        }
    })
    .catch(err => showAlert('error', 'An error occurred. Please try again.'));
}

function confirmDeleteUser(userId, username) {
    document.getElementById('deleteConfirmMessage').textContent =
        `Are you sure you want to delete user "${username}"? This action cannot be undone.`;
    document.getElementById('deleteConfirmBtn').onclick = () => deleteUser(userId);
    openModal('deleteConfirmModal');
}

function deleteUser(userId) {
    const formData = new FormData();
    formData.append('action', 'delete_user');
    formData.append('user_id', userId);

    fetch('api/admin_management.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showAlert('success', data.message);
            closeModal('deleteConfirmModal');
            document.querySelector(`tr[data-user-id="${userId}"]`).remove();
        } else {
            showAlert('error', data.message);
        }
    })
    .catch(err => showAlert('error', 'An error occurred. Please try again.'));
}

// Account CRUD
function submitAddAccount(event) {
    event.preventDefault();
    const formData = new FormData(event.target);
    formData.append('action', 'add_account');

    fetch('api/admin_management.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showAlert('success', data.message);
            closeModal('addAccountModal');
            setTimeout(() => location.reload(), 1000);
        } else {
            showAlert('error', data.message);
        }
    })
    .catch(err => showAlert('error', 'An error occurred. Please try again.'));
}

function editAccount(account) {
    document.getElementById('editAccountIdField').value = account.id;
    document.getElementById('editAccountName').value = account.name;
    openModal('editAccountModal');
}

function submitEditAccount(event) {
    event.preventDefault();
    const formData = new FormData(event.target);
    formData.append('action', 'update_account');

    fetch('api/admin_management.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showAlert('success', data.message);
            closeModal('editAccountModal');
            setTimeout(() => location.reload(), 1000);
        } else {
            showAlert('error', data.message);
        }
    })
    .catch(err => showAlert('error', 'An error occurred. Please try again.'));
}

function confirmDeleteAccount(accountId, accountName) {
    document.getElementById('deleteConfirmMessage').textContent =
        `Are you sure you want to delete account "${accountName}"? This will also remove all user associations.`;
    document.getElementById('deleteConfirmBtn').onclick = () => deleteAccount(accountId);
    openModal('deleteConfirmModal');
}

function deleteAccount(accountId) {
    const formData = new FormData();
    formData.append('action', 'delete_account');
    formData.append('account_id', accountId);

    fetch('api/admin_management.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showAlert('success', data.message);
            closeModal('deleteConfirmModal');
            document.querySelector(`tr[data-account-id="${accountId}"]`).remove();
        } else {
            showAlert('error', data.message);
        }
    })
    .catch(err => showAlert('error', 'An error occurred. Please try again.'));
}


// Close modals on Escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeAllModals();
    }
});
</script>

</body>
</html>
