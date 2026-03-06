<?php
session_name("logistics_session");
session_start();

// Check if the user is logged in and is an admin
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
$adminAccounts = ($role === 'admin') ? account_ids_for_user($currentUserId) : [];

// Fetch estimate data with account info
$stmt = $conn->prepare("
    SELECT 
        fe.user_id, 
        fe.name, 
        fe.estimate_data, 
        fe.created_at,
        cau.account_id,
        ca.name AS account_name,
        u.username
    FROM freight_estimates fe
    LEFT JOIN (
        SELECT user_id, MIN(account_id) AS account_id
        FROM customer_account_users
        GROUP BY user_id
    ) AS cau ON fe.user_id = cau.user_id
    LEFT JOIN customer_accounts ca ON ca.id = cau.account_id
    LEFT JOIN users u ON u.id = fe.user_id
    WHERE fe.id = ?
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

// Enforce account scoping for admins
if ($role === 'admin') {
    $ownerAccounts = account_ids_for_user((int)$estimateRow['user_id']);
    if (empty(array_intersect($adminAccounts, $ownerAccounts))) {
        $conn->close();
        header("Location: unauthorized");
        exit();
    }
}

// Handle form submission for updating the estimate
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_estimate'])) {
    $cost_per_truck = floatval($_POST['cost_per_truck'] ?? 0);
    $total_accessorial_cost = floatval($_POST['total_accessorial_cost'] ?? 0);

    // Safely retrieve numeric fields, default to 0 if missing
    $number_of_trucks_raw = $estimate_data['estimated_number_of_trucks'] ?? 0;
    $number_of_trucks = max(1, floatval($number_of_trucks_raw));

    // Perform calculations
    $total_freight_cost = $cost_per_truck * $number_of_trucks;
    $grand_total = $total_freight_cost + $total_accessorial_cost;

    // Update estimate data with admin inputs
    $estimate_data['cost_per_truck'] = $cost_per_truck;
    $estimate_data['total_accessorial_cost'] = $total_accessorial_cost;
    $estimate_data['total_freight_cost'] = $total_freight_cost;
    $estimate_data['grand_total'] = $grand_total;

    // Save updated estimate data
    $estimate_data_json = json_encode($estimate_data);
    $stmt = $conn->prepare("UPDATE freight_estimates SET estimate_data = ? WHERE id = ?");
    $stmt->bind_param("si", $estimate_data_json, $estimate_id);

    if ($stmt->execute()) {
        $success_message = "Estimate updated successfully!";

        $ownerAccounts = account_ids_for_user((int)$estimateRow['user_id']);
        $title = "Freight rate added: " . ($estimateRow['name'] ?? 'Estimate');
        $message = "Rates were added by " . ($_SESSION['username'] ?? 'an admin') . " for " . ($estimate_data['origin'] ?? 'origin') . " to " . ($estimate_data['destination'] ?? 'destination') . ".";
        $link = 'view_freight_estimate?id=' . $estimate_id;

        if ($notify_user_on_rate_update) {
            notify_user((int)$estimateRow['user_id'], 'freight_estimate_rated', $title, $message, $link);
        }
        if ($notify_account_admins_on_rate_update) {
            notify_account_admins($ownerAccounts, 'freight_estimate_rated', $title, $message, $link);
        }
        if ($notify_global_admins_on_rate_update) {
            notify_global_admins('freight_estimate_rated', $title, $message, $link);
        }
    } else {
        $error_message = "Error updating estimate: " . $stmt->error;
    }
    $stmt->close();
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin - Freight Estimate View</title>
    <link rel="stylesheet" href="portal.css">
    <link rel="icon" href="pictures/favicon.png" type="image/x-icon">
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css?family=Poppins:300,400,500,600,700&display=swap" rel="stylesheet">

    <style>
        /* Mimicking the style approach from admin_warehouse_estimate_view.php */
        label {
            display: block;
            margin-top: 15px;
            font-weight: bold;
        }
        input {
            width: 95%;
            padding: 8px;
            margin-top: 5px;
        }
        button {
            background-color: #488C9A; /* Secondary blue color */
            color: white;
            padding: 10px 20px;
            margin: 10px 0;
            border: none;
            border-radius: 4px;
            font-size: 1em;
            cursor: pointer;
            font-weight: bold;
        }
        button:hover {
            background-color: #293E4C; /* Darker shade on hover */
        }
        .success-message {
            color: green;
            margin-top: 15px;
        }
        .error-message {
            color: red;
            margin-top: 15px;
        }
        table {
            width: 100%;
            margin-top: 20px;
            border-collapse: collapse;
        }
        th, td {
            padding: 8px;
            text-align: left;
            border-bottom: 1px solid #ddd;
        }
        h1, h2 {
            margin-top: 20px;
        }
        .admin-hero {
            background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%);
            border-radius: 24px;
            padding: 24px;
            margin-bottom: 18px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.06);
            border: 1px solid rgba(72, 140, 154, 0.08);
            position: relative;
            overflow: hidden;
        }
        .admin-hero::before { content: ""; position: absolute; top: 0; left: 0; right: 0; height: 4px; background: linear-gradient(90deg, #488C9A 0%, #293E4C 100%); }
        .admin-hero__content { display: flex; align-items: center; justify-content: space-between; gap: 16px; flex-wrap: wrap; }
        .hero-sub { color: #556; margin: 4px 0 0; }
    </style>
</head>
<body>
<?php include 'header.php'; ?>
<main>
    <?php require_once 'components/breadcrumbs.php'; echo slp_render_breadcrumbs(['current_label' => 'Freight Estimate']); ?>
    <section class="admin-hero">
        <div class="admin-hero__content">
            <div>
                <h1>Freight Estimate</h1>
                <p class="hero-sub"><?php echo htmlspecialchars($estimateRow['name'] ?? ''); ?></p>
            </div>
        </div>
    </section>

    <?php
    // Display success or error messages
    if (!empty($success_message)) {
        echo '<p class="success-message">' . htmlspecialchars($success_message) . '</p>';
    }
    if (!empty($error_message)) {
        echo '<p class="error-message">' . htmlspecialchars($error_message) . '</p>';
    }
    ?>

    <!-- Basic Info -->
    <ul>
        <li><strong>Customer:</strong> <?php echo htmlspecialchars($estimateRow['account_name'] ?? 'Unassigned'); ?></li>
        <li><strong>Created At:</strong> <?php echo htmlspecialchars($estimateRow['created_at'] ?? ''); ?></li>
    </ul>

    <h2>Estimate Details</h2>
    <ul>
        <li><strong>Origin:</strong> <?php echo htmlspecialchars($estimate_data['origin'] ?? ''); ?></li>
        <li><strong>Destination:</strong> <?php echo htmlspecialchars($estimate_data['destination'] ?? ''); ?></li>
        <li><strong>Distance:</strong> <?php echo htmlspecialchars($estimate_data['distance'] ?? ''); ?> miles</li>
        <li><strong>Project Size:</strong> <?php echo htmlspecialchars($estimate_data['project_size'] ?? ''); ?> MW</li>
        <li><strong>Estimated Start Date:</strong> <?php echo htmlspecialchars($estimate_data['estimated_start_date'] ?? ''); ?></li>
        <li><strong>Estimated Number of Trucks:</strong> <?php echo htmlspecialchars($estimate_data['estimated_number_of_trucks'] ?? ''); ?></li>
        <li><strong>Estimated Modules Per Truck:</strong> <?php echo htmlspecialchars($estimate_data['estimated_modules_per_truck'] ?? ''); ?></li>
    </ul>

    <!-- Admin input for costs -->
    <h2>Update Costs</h2>
    <form method="POST" action="">
        <input type="hidden" name="update_estimate" value="1">

        <label for="cost_per_truck">Cost per Truck:</label>
        <input
            type="number"
            step="0.01"
            name="cost_per_truck"
            required
            value="<?php echo htmlspecialchars($estimate_data['cost_per_truck'] ?? ''); ?>"
        >

        <label for="total_accessorial_cost">Total Accessorial Cost:</label>
        <input
            type="number"
            step="0.01"
            name="total_accessorial_cost"
            required
            value="<?php echo htmlspecialchars($estimate_data['total_accessorial_cost'] ?? ''); ?>"
        >

        <button type="submit">Update Estimate</button>
    </form>

    <!-- Display updated totals -->
    <?php if (!empty($estimate_data['grand_total'])): ?>
        <h2>Updated Totals</h2>
        <table>
            <tr>
                <th>Total Freight Cost</th>
                <td>$<?php echo number_format($estimate_data['total_freight_cost'] ?? 0, 2); ?></td>
            </tr>
            <tr>
                <th>Total Accessorial Cost</th>
                <td>$<?php echo number_format($estimate_data['total_accessorial_cost'] ?? 0, 2); ?></td>
            </tr>
            <tr>
                <th>Grand Total</th>
                <td>$<?php echo number_format($estimate_data['grand_total'] ?? 0, 2); ?></td>
            </tr>
        </table>
    <?php endif; ?>
</main>
</body>
</html>
