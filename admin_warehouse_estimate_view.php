<?php
session_name("logistics_session");
session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: login");
    exit();
}

$role = $_SESSION['role'] ?? '';
if ($role !== 'global_admin' && $role !== 'admin') {
    header("Location: unauthorized");
    exit();
}

$estimate_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if ($estimate_id <= 0) {
    die("Estimate ID not specified.");
}

require_once '../config.php';
require_once 'notification_helpers.php';

// Notification toggles
$notify_user_on_rate_update = true;
$notify_account_admins_on_rate_update = false;
$notify_global_admins_on_rate_update = false;

$conn = getDBConnection();
if (!$conn) {
    die("Connection failed");
}

$currentUserId = (int)$_SESSION['user_id'];
$adminAccounts = $role === 'admin' ? account_ids_for_user($currentUserId) : [];

// Fetch estimate data with account info
$stmt = $conn->prepare("
    SELECT 
        wq.user_id,
        wq.name,
        wq.estimate_data,
        wq.created_at,
        cau.account_id,
        ca.name AS account_name,
        u.username
    FROM warehouse_quotes wq
    LEFT JOIN (
        SELECT user_id, MIN(account_id) AS account_id
        FROM customer_account_users
        GROUP BY user_id
    ) AS cau ON wq.user_id = cau.user_id
    LEFT JOIN customer_accounts ca ON ca.id = cau.account_id
    LEFT JOIN users u ON u.id = wq.user_id
    WHERE wq.id = ?
    LIMIT 1
");
$stmt->bind_param("i", $estimate_id);
$stmt->execute();
$result = $stmt->get_result();
$estimateRow = $result->fetch_assoc();
$stmt->close();

if (!$estimateRow) {
    $conn->close();
    die("Estimate not found.");
}

$estimate_data = json_decode($estimateRow['estimate_data'], true) ?? [];

// Admin scoping
if ($role === 'admin') {
    $ownerAccounts = account_ids_for_user((int)$estimateRow['user_id']);
    if (empty(array_intersect($adminAccounts, $ownerAccounts))) {
        $conn->close();
        header("Location: unauthorized");
        exit();
    }
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['add_quote'])) {
        $warehouse_location = trim($_POST['warehouse_location'] ?? '');
        $in_fee = floatval($_POST['in_fee'] ?? 0);
        $out_fee = floatval($_POST['out_fee'] ?? 0);
        $monthly_storage_fee = floatval($_POST['monthly_storage_fee'] ?? 0);

        if (empty($warehouse_location) || $in_fee < 0 || $out_fee < 0 || $monthly_storage_fee < 0) {
            $error_message = "Please fill in all required fields with valid values.";
        } else {
            $new_quote = [
                'warehouse_location' => $warehouse_location,
                'in_fee_per_pallet' => $in_fee,
                'out_fee_per_pallet' => $out_fee,
                'monthly_storage_cost_per_pallet' => $monthly_storage_fee
            ];
            if (!isset($estimate_data['quotes'])) {
                $estimate_data['quotes'] = [];
            }
            $estimate_data['quotes'][] = $new_quote;

            $updated_estimate_data_json = json_encode($estimate_data);
            $up = $conn->prepare("UPDATE warehouse_quotes SET estimate_data = ? WHERE id = ?");
            $up->bind_param("si", $updated_estimate_data_json, $estimate_id);
            if ($up->execute()) {
                $success_message = "Quote added successfully!";

                $ownerAccounts = account_ids_for_user((int)$estimateRow['user_id']);
                $title = "Warehouse rate added: " . ($estimateRow['name'] ?? 'Estimate');
                $message = "Rates were added by " . ($_SESSION['username'] ?? 'an admin') . " for " . ($estimate_data['project_location'] ?? 'location') . ".";
                $link = 'view_warehouse_estimate?id=' . $estimate_id;

                if ($notify_user_on_rate_update) {
                    notify_user((int)$estimateRow['user_id'], 'warehouse_estimate_rated', $title, $message, $link);
                }
                if ($notify_account_admins_on_rate_update) {
                    notify_account_admins($ownerAccounts, 'warehouse_estimate_rated', $title, $message, $link);
                }
                if ($notify_global_admins_on_rate_update) {
                    notify_global_admins('warehouse_estimate_rated', $title, $message, $link);
                }
            } else {
                $error_message = "Error updating estimate: " . $up->error;
            }
            $up->close();
        }
    } elseif (isset($_POST['delete_quote'])) {
        $quote_index = intval($_POST['quote_index'] ?? -1);
        if (isset($estimate_data['quotes'][$quote_index])) {
            array_splice($estimate_data['quotes'], $quote_index, 1);
            $updated_estimate_data_json = json_encode($estimate_data);
            $up = $conn->prepare("UPDATE warehouse_quotes SET estimate_data = ? WHERE id = ?");
            $up->bind_param("si", $updated_estimate_data_json, $estimate_id);
            if ($up->execute()) {
                $success_message = "Quote deleted successfully!";
            } else {
                $error_message = "Error updating estimate: " . $up->error;
            }
            $up->close();
        } else {
            $error_message = "Quote not found.";
        }
    }
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin - Warehouse Estimate View</title>
    <link rel="stylesheet" href="portal.css">
    <link rel="icon" href="pictures/favicon.png" type="image/x-icon">
    <link href="https://fonts.googleapis.com/css?family=Poppins:300,400,500,600,700&display=swap" rel="stylesheet">
    <style>
        label { display: block; margin-top: 15px; font-weight: bold; }
        input { width: 95%; padding: 8px; margin-top: 5px; }
        button { background-color: #488C9A; color: white; padding: 10px 20px; margin: 10px 0; border: none; border-radius: 4px; font-size: 1em; cursor: pointer; font-weight: bold; }
        button:hover { background-color: #293E4C; }
        .success-message { color: #0f5132; background: #d1e7dd; border: 1px solid #badbcc; padding: 10px 12px; border-radius: 8px; margin-top: 15px; }
        .error-message { color: #842029; background: #f8d7da; border: 1px solid #f5c2c7; padding: 10px 12px; border-radius: 8px; margin-top: 15px; }
        table { width: 100%; margin-top: 20px; border-collapse: collapse; }
        th, td { padding: 8px; text-align: left; border-bottom: 1px solid #ddd; }
        h1, h2 { margin-top: 20px; }
        .admin-hero { background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%); border-radius: 24px; padding: 24px; margin-bottom: 18px; box-shadow: 0 8px 32px rgba(0, 0, 0, 0.06); border: 1px solid rgba(72, 140, 154, 0.08); position: relative; overflow: hidden; }
        .admin-hero::before { content: ""; position: absolute; top: 0; left: 0; right: 0; height: 4px; background: linear-gradient(90deg, #488C9A 0%, #293E4C 100%); }
        .admin-hero__content { display: flex; align-items: center; justify-content: space-between; gap: 16px; flex-wrap: wrap; }
        .hero-sub { color: #556; margin: 4px 0 0; }
    </style>
</head>
<body>
<?php include 'header.php'; ?>
<main>
    <?php require_once 'components/breadcrumbs.php'; echo slp_render_breadcrumbs(['current_label' => 'Warehouse Estimate']); ?>
    <section class="admin-hero">
        <div class="admin-hero__content">
            <div>
                <h1>Warehouse Estimate</h1>
                <p class="hero-sub"><?php echo htmlspecialchars($estimateRow['name'] ?? ''); ?></p>
            </div>
        </div>
    </section>

    <?php
    if (!empty($success_message)) {
        echo '<p class="success-message">' . htmlspecialchars($success_message) . '</p>';
    }
    if (!empty($error_message)) {
        echo '<p class="error-message">' . htmlspecialchars($error_message) . '</p>';
    }
    ?>

    <ul>
        <li><strong>Customer:</strong> <?php echo htmlspecialchars($estimateRow['account_name'] ?? 'Unassigned'); ?></li>
        <li><strong>Created At:</strong> <?php echo htmlspecialchars($estimateRow['created_at'] ?? ''); ?></li>
    </ul>

    <h2>Estimate Details</h2>
    <ul>
        <li><strong>Project Location:</strong> <?php echo htmlspecialchars($estimate_data['project_location'] ?? ''); ?></li>
        <li><strong>Estimated Storage Start:</strong> <?php echo htmlspecialchars($estimate_data['estimated_storage_start'] ?? ''); ?></li>
        <li><strong>Estimated Number of Pallets:</strong> <?php echo htmlspecialchars($estimate_data['estimated_number_of_pallets'] ?? ''); ?></li>
        <li><strong>Pallet Dimensions (L x W x H in inches):</strong> <?php echo htmlspecialchars($estimate_data['pallet_length'] ?? '') . ' x ' . htmlspecialchars($estimate_data['pallet_width'] ?? '') . ' x ' . htmlspecialchars($estimate_data['pallet_height'] ?? ''); ?></li>
        <li><strong>Stackable:</strong> <?php echo !empty($estimate_data['stackable']) ? 'Yes' : 'No'; ?></li>
        <li><strong>Calculated Square Feet:</strong> <?php echo number_format($estimate_data['square_feet'] ?? 0, 2); ?> sq ft</li>
    </ul>

    <h2>Add Warehouse Rate</h2>
    <form method="POST" action="">
        <input type="hidden" name="add_quote" value="1">

        <label for="warehouse_location">Warehouse Location</label>
        <input type="text" name="warehouse_location" required>

        <label for="in_fee">In Fee (per pallet)</label>
        <input type="number" step="0.01" name="in_fee" required>

        <label for="out_fee">Out Fee (per pallet)</label>
        <input type="number" step="0.01" name="out_fee" required>

        <label for="monthly_storage_fee">Monthly Storage Fee (per pallet)</label>
        <input type="number" step="0.01" name="monthly_storage_fee" required>

        <button type="submit">Add Rate</button>
    </form>

    <h2>Existing Rates</h2>
    <?php if (!empty($estimate_data['quotes'])): ?>
        <table>
            <tr>
                <th>Warehouse Location</th>
                <th>In Fee (per pallet)</th>
                <th>Out Fee (per pallet)</th>
                <th>Monthly Storage Fee (per pallet)</th>
                <th>Actions</th>
            </tr>
            <?php foreach ($estimate_data['quotes'] as $index => $quote): ?>
                <tr>
                    <td><?php echo htmlspecialchars($quote['warehouse_location']); ?></td>
                    <td>$<?php echo number_format($quote['in_fee_per_pallet'], 2); ?></td>
                    <td>$<?php echo number_format($quote['out_fee_per_pallet'], 2); ?></td>
                    <td>$<?php echo number_format($quote['monthly_storage_cost_per_pallet'], 2); ?></td>
                    <td>
                        <form method="POST" action="" class="delete-form">
                            <input type="hidden" name="delete_quote" value="1">
                            <input type="hidden" name="quote_index" value="<?php echo $index; ?>">
                            <button type="submit">Delete</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        </table>
    <?php else: ?>
        <p>No rates added yet.</p>
    <?php endif; ?>
</main>
</body>
</html>
