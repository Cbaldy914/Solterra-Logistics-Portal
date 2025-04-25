<?php
session_name("logistics_session");
session_start();

// Ensure user has role admin or global_admin
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin','global_admin'])) {
    die("Unauthorized access.");
}

// Database connection
require_once '../config.php';
$conn = getDBConnection();
if (!$conn) {
    die("Database connection failed.");
}

$role     = $_SESSION['role'];
$user_id  = $_SESSION['user_id'];
$batch_id = isset($_GET['batch_id']) ? intval($_GET['batch_id']) : 0;

if ($batch_id <= 0) {
    die("Invalid Batch ID provided.");
}

// --- Data Fetching --- 
$batch_data = null;
$batch_items = [];
$pallets = [];
$summary_stats = [
    'total_ordered' => 0,
    'pallets_created' => 0,
    'pallets_quantity' => 0,
    'status_counts' => [],
]; 
$account_id_for_admin = null;
$errorMessage = '';

try {
    // Fetch main batch data and account name
    $stmtBatch = $conn->prepare("
        SELECT um.*, c.name as account_name 
        FROM unassigned_modules um 
        JOIN customer_accounts c ON um.account_id = c.id
        WHERE um.id = ?
    ");
    if (!$stmtBatch) throw new Exception("Prepare batch fetch failed: " . $conn->error);
    $stmtBatch->bind_param("i", $batch_id);
    $stmtBatch->execute();
    $resultBatch = $stmtBatch->get_result();
    if ($resultBatch->num_rows === 0) {
        throw new Exception("Unassigned module batch not found.");
    }
    $batch_data = $resultBatch->fetch_assoc();
    $stmtBatch->close();

    // Access Control for Admin role
    if ($role === 'admin') {
        $sqlAdminAcc = "SELECT account_id FROM customer_account_users WHERE user_id = ? AND role = 'admin' LIMIT 1";
        $stmtAdminAcc = $conn->prepare($sqlAdminAcc);
        if ($stmtAdminAcc) {
            $stmtAdminAcc->bind_param("i", $user_id);
            $stmtAdminAcc->execute();
            $stmtAdminAcc->bind_result($account_id_for_admin);
            $stmtAdminAcc->fetch();
            $stmtAdminAcc->close();
        }
        if (!$account_id_for_admin || $batch_data['account_id'] != $account_id_for_admin) {
            throw new Exception("Access Denied: You do not have permission to view this batch.");
        }
    }

    // Fetch batch items (original order)
    $stmtItems = $conn->prepare("SELECT id, wattage, quantity FROM unassigned_module_items WHERE unassigned_module_id = ? ORDER BY wattage ASC");
    if (!$stmtItems) throw new Exception("Prepare items fetch failed: " . $conn->error);
    $stmtItems->bind_param("i", $batch_id);
    $stmtItems->execute();
    $resultItems = $stmtItems->get_result();
    $item_ids = [];
    while ($item = $resultItems->fetch_assoc()) {
        $batch_items[] = $item;
        $summary_stats['total_ordered'] += $item['quantity'];
        $item_ids[] = $item['id']; // Collect item IDs to fetch pallets
    }
    $stmtItems->close();

    // Fetch associated pallets if item IDs were found
    if (!empty($item_ids)) {
        $placeholders = implode(',', array_fill(0, count($item_ids), '?'));
        $types = str_repeat('i', count($item_ids));
        
        // Fetch pallets and associated location names
        $sqlPallets = "
            SELECT 
                ip.*, 
                w.name as warehouse_name, 
                p.project_name 
            FROM inventory_pallets ip
            LEFT JOIN warehouses w ON ip.current_warehouse_id = w.id
            LEFT JOIN projects p ON ip.current_project_id = p.id
            WHERE ip.unassigned_module_item_id IN ($placeholders)
            ORDER BY ip.id ASC
        ";
        $stmtPallets = $conn->prepare($sqlPallets);
        if (!$stmtPallets) throw new Exception("Prepare pallets fetch failed: " . $conn->error);
        $stmtPallets->bind_param($types, ...$item_ids);
        $stmtPallets->execute();
        $resultPallets = $stmtPallets->get_result();
        $pallet_ids = [];
        while ($pallet = $resultPallets->fetch_assoc()) {
            // Determine display location
            if ($pallet['status'] === 'In Warehouse' && $pallet['current_warehouse_id']) {
                $pallet['display_location'] = 'Warehouse: ' . htmlspecialchars($pallet['warehouse_name'] ?? 'Unknown');
            } elseif ($pallet['status'] === 'Delivered to Project' && $pallet['current_project_id']) {
                 $pallet['display_location'] = 'Project: ' . htmlspecialchars($pallet['project_name'] ?? 'Unknown');
            } elseif ($pallet['status'] === 'Allocated to Project' && $pallet['current_project_id']){
                 $pallet['display_location'] = 'Project: ' . htmlspecialchars($pallet['project_name'] ?? 'Unknown') . ' (Allocated)';
            } else {
                $pallet['display_location'] = $pallet['status']; // Default to status if no specific location applies
            }
            $pallets[] = $pallet;
            $pallet_ids[] = $pallet['id'];
            $summary_stats['pallets_created']++;
            $summary_stats['pallets_quantity'] += $pallet['quantity'];
            $status = $pallet['status'];
            $summary_stats['status_counts'][$status] = ($summary_stats['status_counts'][$status] ?? 0) + 1;
        }
        $stmtPallets->close();
    }

} catch (Exception $e) {
    $errorMessage = "Error loading data: " . $e->getMessage();
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Unassigned Module Overview</title>
    <link rel="stylesheet" href="portal.css">
    <link rel="icon" href="pictures/favicon.png" type="image/x-icon">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        .overview-header {
            position: relative; /* Needed for absolute positioning of child */
            background-color: #f9f9f9;
            padding: 15px;
            margin-bottom: 20px;
            border: 1px solid #e0e0e0;
            border-radius: 5px;
        }
        .overview-header .edit-button {
            position: absolute;
            top: 15px;
            right: 15px;
            /* Inherit action-button styles or define new ones */
            background-color: #488C9A;
            color: #fff;
            text-decoration: none;
            border-radius: 5px;
            padding: 5px 10px;
            border: none;
            cursor: pointer;
        }
        .overview-header h1 {
            margin-top: 0;
            margin-bottom: 10px;
            font-size: 1.6em;
            color: #293E4C;
        }
        .overview-header p, .summary-section p {
            margin: 5px 0;
            line-height: 1.5;
        }
        .section-title {
            font-size: 1.3em;
            margin-bottom: 15px;
            color: #488C9A;
            border-bottom: 2px solid #488C9A;
            padding-bottom: 5px;
        }
        .action-buttons {
            margin-bottom: 20px;
            text-align: right;
        }
        .action-buttons .action-button {
             margin-left: 10px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
        }
        th, td {
            border: 1px solid #ccc;
            padding: 8px 12px;
            text-align: left;
        }
        th {
            background-color: #e9ecef;
        }
        tr:nth-child(even) {
            background-color: #f8f9fa;
        }
        .status-counts li {
            margin-bottom: 5px;
        }
    </style>
</head>
<body>
<?php include 'header.php'; ?>
<main>
    <?php if (!empty($errorMessage)): ?>
        <div class="error-message"><strong>Error:</strong> <?php echo htmlspecialchars($errorMessage); ?></div>
    <?php elseif ($batch_data): ?>
        
        <div class="overview-header">
            <h1>Unassigned Module Batch: <?php echo htmlspecialchars($batch_data['vendor_name']); ?></h1>
            <p><strong>Account:</strong> <?php echo htmlspecialchars($batch_data['account_name']); ?></p>
            <p><strong>Initial Location:</strong> <?php echo htmlspecialchars($batch_data['initial_location']); ?></p>
            <p><strong>Batch ID:</strong> <?php echo $batch_data['id']; ?></p>
            <p><strong>Date Added:</strong> <?php echo date('Y-m-d H:i', strtotime($batch_data['created_at'])); ?></p>
            <button class="edit-button" onclick="window.location.href='edit_unassigned_module.php?batch_id=<?php echo $batch_id; ?>'">Edit Batch Details</button>
        </div>

        <div class="action-buttons">
             <a href="manage_projects.php" class="action-button">Back to Manage List</a>
        </div>

        <div class="summary-section">
            <h2 class="section-title">Summary</h2>
            <p><strong>Total Modules Ordered:</strong> <?php echo number_format($summary_stats['total_ordered']); ?></p>
            <p><strong>Total Pallets Created:</strong> <?php echo number_format($summary_stats['pallets_created']); ?></p>
            <p><strong>Total Modules on Pallets:</strong> <?php echo number_format($summary_stats['pallets_quantity']); ?> 
                <?php if ($summary_stats['pallets_quantity'] != $summary_stats['total_ordered']) echo "<span style='color:orange;'>(Mismatch with ordered quantity!)</span>"; ?>
            </p>
            <h3>Pallet Status Breakdown:</h3>
            <?php if (!empty($summary_stats['status_counts'])): ?>
                <ul class="status-counts">
                    <?php foreach ($summary_stats['status_counts'] as $status => $count): ?>
                        <li><?php echo htmlspecialchars($status); ?>: <?php echo $count; ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php else: ?>
                <p>No pallets have been created/recorded for this batch yet.</p>
            <?php endif; ?>
        </div>

        <div class="pallets-section">
            <h2 class="section-title">Inventory Pallets</h2>
            <?php if (!empty($pallets)): ?>
                <table>
                    <thead>
                        <tr>
                            <th>Pallet ID</th>
                            <th>Identifier</th>
                            <th>Wattage</th>
                            <th>Quantity</th>
                            <th>Status</th>
                            <th>Current Location</th>
                            <th>Arrival Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($pallets as $pallet): ?>
                            <tr>
                                <td><?php echo $pallet['id']; ?></td>
                                <td><?php echo htmlspecialchars($pallet['pallet_identifier'] ?? 'N/A'); ?></td>
                                <td><?php echo $pallet['wattage']; ?>W</td>
                                <td><?php echo number_format($pallet['quantity']); ?></td>
                                <td><?php echo htmlspecialchars($pallet['status']); ?></td>
                                <td><?php echo htmlspecialchars($pallet['display_location']); ?></td>
                                <td><?php echo $pallet['arrival_date'] ? date('Y-m-d', strtotime($pallet['arrival_date'])) : 'N/A'; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p>No pallets created or recorded for this batch yet.</p>
            <?php endif; ?>
        </div>

    <?php else: ?>
         <p>Batch data could not be loaded.</p>
         <a href="manage_projects.php">Back to Manage List</a>
    <?php endif; ?>
</main>
</body>
</html> 