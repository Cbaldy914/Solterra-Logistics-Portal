<?php
session_name("logistics_session");
session_start();

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'global_admin', 'customer_admin', 'user'], true)) {
    header("Location: unauthorized.php");
    exit();
}

require_once '../config.php';
$conn = getDBConnection();
if (!$conn) {
    die("Database connection failed.");
}

$role = $_SESSION['role'];
$user_id = (int)$_SESSION['user_id'];
$selected_project_id = isset($_GET['project_id']) ? (int)$_GET['project_id'] : 0;

$account_id_for_admin = null;
$user_account_ids = [];
$available_projects = [];
$containers = [];
$errorMessage = '';

try {
    if (in_array($role, ['admin', 'customer_admin'], true)) {
        $stmtAdminAcc = $conn->prepare("SELECT account_id FROM customer_account_users WHERE user_id = ? AND role IN ('admin', 'customer_admin') LIMIT 1");
        if ($stmtAdminAcc) {
            $stmtAdminAcc->bind_param("i", $user_id);
            $stmtAdminAcc->execute();
            $stmtAdminAcc->bind_result($account_id_for_admin);
            $stmtAdminAcc->fetch();
            $stmtAdminAcc->close();
        }
        if (!$account_id_for_admin) {
            throw new Exception('Unable to determine account scope for this user.');
        }
    } elseif ($role === 'user') {
        $stmtUserAcc = $conn->prepare("SELECT account_id FROM customer_account_users WHERE user_id = ?");
        if ($stmtUserAcc) {
            $stmtUserAcc->bind_param("i", $user_id);
            $stmtUserAcc->execute();
            $resultUserAcc = $stmtUserAcc->get_result();
            while ($row = $resultUserAcc->fetch_assoc()) {
                $user_account_ids[] = (int)$row['account_id'];
            }
            $stmtUserAcc->close();
        }
        if (empty($user_account_ids)) {
            throw new Exception('No account access found for this user.');
        }
    }

    if ($role === 'global_admin') {
        $stmtProjects = $conn->prepare("
            SELECT p.id, p.project_name
            FROM projects p
            WHERE (p.status IS NULL OR p.status = 'active')
            ORDER BY p.project_name ASC
        ");
    } elseif (in_array($role, ['admin', 'customer_admin'], true)) {
        $stmtProjects = $conn->prepare("
            SELECT p.id, p.project_name
            FROM projects p
            WHERE p.account_id = ? AND (p.status IS NULL OR p.status = 'active')
            ORDER BY p.project_name ASC
        ");
        $stmtProjects->bind_param("i", $account_id_for_admin);
    } else {
        $placeholders = implode(',', array_fill(0, count($user_account_ids), '?'));
        $types = str_repeat('i', count($user_account_ids));
        $stmtProjects = $conn->prepare("
            SELECT p.id, p.project_name
            FROM projects p
            WHERE p.account_id IN ($placeholders) AND (p.status IS NULL OR p.status = 'active')
            ORDER BY p.project_name ASC
        ");
        $stmtProjects->bind_param($types, ...$user_account_ids);
    }

    if ($stmtProjects) {
        $stmtProjects->execute();
        $resultProjects = $stmtProjects->get_result();
        while ($project = $resultProjects->fetch_assoc()) {
            $available_projects[] = $project;
        }
        $stmtProjects->close();
    }

    if ($selected_project_id > 0) {
        $canAccessProject = false;
        foreach ($available_projects as $project) {
            if ((int)$project['id'] === $selected_project_id) {
                $canAccessProject = true;
                break;
            }
        }
        if (!$canAccessProject) {
            throw new Exception('Access denied for selected project.');
        }
    }

    $innerWhere = ["d1.container_number IS NOT NULL", "TRIM(d1.container_number) <> ''"];
    $innerParams = [];
    $innerTypes = '';

    if ($role === 'global_admin') {
        // No additional account filter.
    } elseif (in_array($role, ['admin', 'customer_admin'], true)) {
        $innerWhere[] = "p1.account_id = ?";
        $innerParams[] = $account_id_for_admin;
        $innerTypes .= 'i';
    } else {
        $placeholders = implode(',', array_fill(0, count($user_account_ids), '?'));
        $innerWhere[] = "p1.account_id IN ($placeholders)";
        foreach ($user_account_ids as $account_id) {
            $innerParams[] = $account_id;
            $innerTypes .= 'i';
        }
    }

    if ($selected_project_id > 0) {
        $innerWhere[] = "d1.project_id = ?";
        $innerParams[] = $selected_project_id;
        $innerTypes .= 'i';
    }

    $innerWhereSql = implode(' AND ', $innerWhere);

    $containersSql = "
        SELECT
            d.container_number,
            d.status_of_delivery,
            d.anticipated_delivery_date AS eta_date,
            d.left_warehouse_date AS departed_date,
            d.created_at,
            d.project_id,
            p.project_name,
            COALESCE(w.name, 'Unknown Port') AS destination_port_name,
            (
                SELECT COUNT(DISTINCT dp2.inventory_pallet_id)
                FROM deliveries d2
                LEFT JOIN delivery_pallets dp2 ON dp2.delivery_id = d2.id
                WHERE d2.container_number = d.container_number
                  AND d2.project_id = d.project_id
            ) AS pallet_count
        FROM deliveries d
        JOIN (
            SELECT d1.container_number, MAX(d1.id) AS latest_delivery_id
            FROM deliveries d1
            JOIN projects p1 ON d1.project_id = p1.id
            WHERE $innerWhereSql
            GROUP BY d1.container_number
        ) latest ON latest.latest_delivery_id = d.id
        JOIN projects p ON d.project_id = p.id
        LEFT JOIN warehouses w ON d.warehouse_id = w.id
        ORDER BY (d.anticipated_delivery_date IS NULL), d.anticipated_delivery_date ASC, d.container_number ASC
    ";

    $stmtContainers = $conn->prepare($containersSql);
    if ($stmtContainers) {
        if ($innerTypes !== '') {
            $stmtContainers->bind_param($innerTypes, ...$innerParams);
        }
        $stmtContainers->execute();
        $resultContainers = $stmtContainers->get_result();
        while ($row = $resultContainers->fetch_assoc()) {
            $days_to_eta = null;
            $eta_raw = trim((string)($row['eta_date'] ?? ''));
            if ($eta_raw !== '' && $eta_raw !== '0000-00-00') {
                try {
                    $today = new DateTime('today');
                    $etaObj = new DateTime($eta_raw);
                    $days_to_eta = (int)$today->diff($etaObj)->format('%r%a');
                } catch (Exception $ignored) {
                    $days_to_eta = null;
                }
            }
            $row['days_to_eta'] = $days_to_eta;
            $containers[] = $row;
        }
        $stmtContainers->close();
    } else {
        throw new Exception('Failed to prepare container tracking query: ' . $conn->error);
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
    <title>Container ETA Tracker</title>
    <link rel="stylesheet" href="portal.css">
    <link rel="icon" href="pictures/favicon.png" type="image/x-icon">
    <style>
        .tracker-card {
            background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);
            border: 1px solid #e2e8f0;
            border-radius: 16px;
            box-shadow: 0 8px 20px rgba(15, 23, 42, 0.06);
            padding: 20px;
            margin-bottom: 22px;
        }
        .eta-positive { color: #1d4ed8; font-weight: 700; }
        .eta-late { color: #dc2626; font-weight: 700; }
        .eta-today { color: #0f766e; font-weight: 700; }
        .status-pill {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 999px;
            background: #e0f2fe;
            color: #0c4a6e;
            font-size: 0.82em;
            font-weight: 600;
        }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 10px 12px; border-bottom: 1px solid #e2e8f0; text-align: left; }
        th { background: #f1f5f9; color: #334155; font-size: 0.85em; text-transform: uppercase; letter-spacing: 0.02em; }
    </style>
</head>
<body>
<?php include 'header.php'; ?>
<main>
    <?php
        require_once 'components/breadcrumbs.php';
        echo slp_render_breadcrumbs(['current_label' => 'Container ETA Tracker']);
    ?>

    <div class="tracker-card">
        <h1 style="margin:0 0 6px 0;">Container ETA Tracker</h1>
        <p style="margin:0; color:#64748b;">Read-only view of container numbers, ETA timing, and current automated shipment status.</p>
    </div>

    <div class="tracker-card">
        <form method="GET" style="display:flex; gap:10px; align-items:end; flex-wrap:wrap;">
            <div>
                <label for="project_id" style="display:block; margin-bottom:6px; font-weight:600;">Project</label>
                <select name="project_id" id="project_id" style="padding:8px 10px; min-width:260px;">
                    <option value="0">All Accessible Projects</option>
                    <?php foreach ($available_projects as $project): ?>
                        <option value="<?php echo (int)$project['id']; ?>" <?php echo ((int)$project['id'] === $selected_project_id) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($project['project_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" class="action-button">Apply</button>
        </form>
    </div>

    <?php if ($errorMessage): ?>
        <div class="error-message"><strong>Error:</strong> <?php echo htmlspecialchars($errorMessage); ?></div>
    <?php else: ?>
        <div class="tracker-card">
            <h2 style="margin-top:0;">Containers</h2>
            <?php if (empty($containers)): ?>
                <p style="margin:0; color:#64748b;">No container records found for the selected scope.</p>
            <?php else: ?>
                <div style="overflow-x:auto;">
                    <table>
                        <thead>
                            <tr>
                                <th>Container</th>
                                <th>Project</th>
                                <th>Status</th>
                                <th>ETA</th>
                                <th>Days To ETA</th>
                                <th>Destination Port</th>
                                <th>Pallets</th>
                                <th>Details</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($containers as $container): ?>
                                <?php
                                    $days = $container['days_to_eta'];
                                    $daysClass = '';
                                    $daysText = 'N/A';
                                    if ($days !== null) {
                                        if ($days > 0) {
                                            $daysClass = 'eta-positive';
                                            $daysText = $days . ' day' . ($days === 1 ? '' : 's');
                                        } elseif ($days === 0) {
                                            $daysClass = 'eta-today';
                                            $daysText = 'Today';
                                        } else {
                                            $daysClass = 'eta-late';
                                            $daysText = abs($days) . ' day' . (abs($days) === 1 ? '' : 's') . ' late';
                                        }
                                    }
                                ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($container['container_number']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($container['project_name'] ?? 'N/A'); ?></td>
                                    <td><span class="status-pill"><?php echo htmlspecialchars($container['status_of_delivery'] ?? 'N/A'); ?></span></td>
                                    <td><?php echo htmlspecialchars($container['eta_date'] ?? 'N/A'); ?></td>
                                    <td class="<?php echo $daysClass; ?>"><?php echo htmlspecialchars($daysText); ?></td>
                                    <td><?php echo htmlspecialchars($container['destination_port_name'] ?? 'N/A'); ?></td>
                                    <td><?php echo number_format((int)($container['pallet_count'] ?? 0)); ?></td>
                                    <td><a href="manage_deliveries.php?search=<?php echo urlencode($container['container_number']); ?>" style="color:#2563eb; text-decoration:none; font-weight:600;">View</a></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</main>
</body>
</html>
