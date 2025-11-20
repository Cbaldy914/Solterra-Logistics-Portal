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

require_once '../config.php';
require_once 'notification_helpers.php';
$conn = getDBConnection();
if (!$conn) {
    die("Connection failed");
}

$currentUserId = (int)$_SESSION['user_id'];
$accountFilter = [];
if ($role === 'admin') {
    $accountFilter = account_ids_for_user($currentUserId);
    if (empty($accountFilter)) {
        $error_message = "No customer account is assigned to your user yet.";
    }
}

function format_city_state(?string $location): string {
    $location = trim((string)$location);
    if ($location === '') return $location;
    $parts = array_map('trim', explode(',', $location));
    $parts = array_values(array_filter($parts, fn($p) => $p !== ''));
    $state = '';
    $city = '';
    for ($i = count($parts) - 1; $i >= 0; $i--) {
        if (preg_match('/^[A-Za-z]{2}$/', $parts[$i])) {
            $state = strtoupper($parts[$i]);
            $city = $parts[$i - 1] ?? '';
            break;
        }
    }
    if ($state !== '' && $city !== '') {
        return trim($city) . ', ' . $state;
    }
    if (count($parts) >= 2) {
        return $parts[0] . ', ' . strtoupper(substr($parts[1], 0, 2));
    }
    return $location;
}

$estimates = [];
if (empty($error_message)) {
    $baseQuery = "
        SELECT 
            wq.id,
            wq.user_id,
            wq.name,
            wq.estimate_data,
            wq.created_at,
            cau.account_id,
            ca.name AS customer_name
        FROM warehouse_quotes wq
        LEFT JOIN (
            SELECT user_id, MIN(account_id) AS account_id
            FROM customer_account_users
            GROUP BY user_id
        ) AS cau ON wq.user_id = cau.user_id
        LEFT JOIN customer_accounts ca ON ca.id = cau.account_id
    ";

    $params = [];
    $types = '';
    if ($role === 'admin' && !empty($accountFilter)) {
        $placeholders = implode(',', array_fill(0, count($accountFilter), '?'));
        $baseQuery .= " WHERE cau.account_id IN ($placeholders)";
        $types = str_repeat('i', count($accountFilter));
        $params = $accountFilter;
    }

    $baseQuery .= " ORDER BY wq.created_at DESC";
    $stmt = $conn->prepare($baseQuery);
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $row['estimate_data_array'] = json_decode($row['estimate_data'], true) ?? [];
        $estimates[] = $row;
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
    <title>Admin - Warehouse Estimates</title>
    <link rel="stylesheet" href="portal.css">
    <link rel="icon" href="pictures/favicon.png" type="image/x-icon">
    <link href="https://fonts.googleapis.com/css?family=Poppins:300,400,500,600,700&display=swap" rel="stylesheet">
    <style>
        main { padding: 24px; }
        h1 { margin: 0 0 4px 0; }
        .summary { margin-bottom: 20px; color: #556; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { padding: 12px 10px; border-bottom: 1px solid #e8ecef; text-align: left; }
        th { font-weight: 700; color: #23343f; background: #f7fafb; }
        tr:hover { background: #f5f9fb; }
        .success-message { color: #0f5132; background: #d1e7dd; border: 1px solid #badbcc; padding: 10px 12px; border-radius: 8px; }
        .error-message { color: #842029; background: #f8d7da; border: 1px solid #f5c2c7; padding: 10px 12px; border-radius: 8px; }
        .rate-chip { display: inline-flex; padding: 8px 12px; border-radius: 12px; text-decoration: none; font-weight: 600; border: 1px solid transparent; transition: all .15s ease; }
        .rate-chip.has-rate { background: #e7f6f8; color: #1c4755; border-color: #b8dde4; }
        .rate-chip.missing-rate { background: #fff6e6; color: #8a4b00; border-color: #ffd699; }
        .rate-chip:hover { transform: translateY(-1px); box-shadow: 0 8px 16px rgba(0,0,0,.06); }
        .lane { color: #23343f; font-weight: 600; }
        .meta { color: #6c7a82; font-size: 0.95em; }
        .admin-hero { background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%); border-radius: 24px; padding: 24px; margin-bottom: 18px; box-shadow: 0 8px 32px rgba(0, 0, 0, 0.06); border: 1px solid rgba(72, 140, 154, 0.08); position: relative; overflow: hidden; }
        .admin-hero::before { content: ""; position: absolute; top: 0; left: 0; right: 0; height: 4px; background: linear-gradient(90deg, #488C9A 0%, #293E4C 100%); }
        .admin-hero__content { display: flex; align-items: center; justify-content: space-between; gap: 16px; flex-wrap: wrap; }
        .hero-sub { color: #556; margin: 4px 0 0; }
        @media (max-width: 768px) {
            table, thead, tbody, th, td, tr { display: block; }
            thead { display: none; }
            tr { border: 1px solid #e8ecef; border-radius: 12px; margin-bottom: 12px; padding: 10px; }
            td { border: none; padding: 6px 0; }
            td::before { content: attr(data-label); display: block; font-weight: 700; color: #23343f; margin-bottom: 4px; }
            .rate-chip { width: 100%; justify-content: center; }
        }
    </style>
</head>
<body>
<?php include 'header.php'; ?>
    <main>
        <?php require_once "components/breadcrumbs.php"; echo slp_render_breadcrumbs(["current_label" => "Warehouse Estimates"]); ?>
        <section class="admin-hero">
            <div class="admin-hero__content">
                <div>
                    <h1>Warehouse Estimates</h1>
                    <p class="hero-sub">Monitor warehouse quote requests and quickly add rates.</p>
                </div>
            </div>
        </section>

        <?php
        if (isset($success_message)) {
            echo '<p class="success-message">' . htmlspecialchars($success_message) . '</p>';
        }
        if (isset($error_message)) {
            echo '<p class="error-message">' . htmlspecialchars($error_message) . '</p>';
        }
        ?>

        <?php if (!empty($estimates)): ?>
            <table>
                <thead>
                    <tr>
                        <th>Customer</th>
                        <th>Estimate</th>
                        <th>Location</th>
                        <th>Created</th>
                        <th>Rate</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($estimates as $estimate): 
                    $data = $estimate['estimate_data_array'];
                    $location = format_city_state($data['project_location'] ?? '');
                    $quotes = $data['quotes'] ?? [];
                    $first = $quotes[0] ?? [];
                    $monthly = $first['monthly_storage_cost_per_pallet'] ?? 0;
                    $hasRate = is_numeric($monthly) && (float)$monthly > 0;
                    $rateDisplay = $hasRate ? '$' . number_format((float)$monthly, 2) . ' / pallet' : 'Add rate';
                    $customerName = $estimate['customer_name'] ?? 'Unassigned';
                ?>
                    <tr>
                        <td data-label="Customer"><?php echo htmlspecialchars($customerName); ?></td>
                        <td data-label="Estimate">
                            <div class="lane"><?php echo htmlspecialchars($estimate['name']); ?></div>
                        </td>
                        <td data-label="Location"><span class="lane"><?php echo htmlspecialchars($location); ?></span></td>
                        <td data-label="Created" class="meta"><?php echo htmlspecialchars($estimate['created_at']); ?></td>
                        <td data-label="Rate">
                            <a class="rate-chip <?php echo $hasRate ? 'has-rate' : 'missing-rate'; ?>" href="admin_warehouse_estimate_view?id=<?php echo $estimate['id']; ?>">
                                <?php echo htmlspecialchars($rateDisplay); ?>
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <p>No warehouse estimates found.</p>
        <?php endif; ?>
    </main>
</body>
</html>
