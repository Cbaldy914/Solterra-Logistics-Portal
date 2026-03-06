<?php
session_name("logistics_session");
session_start();

// Legacy route: manage_deliveries now forwards to the unified view_project tracker.
if (!isset($_SESSION['user_id'])) {
    header("Location: login");
    exit();
}
$role = $_SESSION['role'] ?? 'user';
if (!in_array($role, ['admin', 'customer_admin', 'global_admin'], true)) {
    header("Location: unauthorized");
    exit();
}

$legacy_project_id = isset($_GET['project_id']) ? (int)$_GET['project_id'] : 0;
$legacy_filter_project_id = $_GET['filter_project_id'] ?? null;
$legacy_delivery_id = isset($_GET['delivery_id']) ? (int)$_GET['delivery_id'] : 0;
$legacy_origin_batch_id = isset($_GET['origin_batch_id']) ? (int)$_GET['origin_batch_id'] : 0;

$resolved_project_id = 0;
if ($legacy_project_id > 0) {
    $resolved_project_id = $legacy_project_id;
} elseif (is_string($legacy_filter_project_id) && ctype_digit($legacy_filter_project_id) && (int)$legacy_filter_project_id > 0) {
    $resolved_project_id = (int)$legacy_filter_project_id;
}

if ($resolved_project_id <= 0 && $legacy_delivery_id > 0) {
    require_once '../config.php';
    $conn = getDBConnection();
    if ($conn && !$conn->connect_errno) {
        $stmt = $conn->prepare("SELECT project_id FROM deliveries WHERE id = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param("i", $legacy_delivery_id);
            $stmt->execute();
            $stmt->bind_result($ctx_project_id);
            if ($stmt->fetch() && !empty($ctx_project_id)) {
                $resolved_project_id = (int)$ctx_project_id;
            }
            $stmt->close();
        }
        $conn->close();
    }
}

$target_params = [];
if ($resolved_project_id > 0) {
    $target_params['project_id'] = $resolved_project_id;
} elseif ($legacy_origin_batch_id > 0) {
    $target_params['origin_batch_id'] = $legacy_origin_batch_id;
}

if ($legacy_delivery_id > 0) {
    $target_params['delivery_id'] = $legacy_delivery_id;
}

$legacy_status = $_GET['status'] ?? ($_GET['status_filter'] ?? '');
$legacy_wattage = $_GET['wattage'] ?? ($_GET['wattage_filter'] ?? '');
$legacy_search = $_GET['search'] ?? '';
$legacy_start_date = $_GET['start_date'] ?? '';
$legacy_end_date = $_GET['end_date'] ?? '';

if ($legacy_status !== '') {
    if ($legacy_status === 'Delivered') {
        $legacy_status = 'Delivered to Project';
    }
    $target_params['status'] = $legacy_status;
}
if ($legacy_wattage !== '') {
    $target_params['wattage'] = $legacy_wattage;
}
if ($legacy_search !== '') {
    $target_params['search'] = $legacy_search;
}
if ($legacy_start_date !== '') {
    $target_params['start_date'] = $legacy_start_date;
}
if ($legacy_end_date !== '') {
    $target_params['end_date'] = $legacy_end_date;
}

if (!empty($target_params)) {
    header('Location: view_project.php?' . http_build_query($target_params));
    exit();
}

// When launched from top-nav without context, show active projects the user can access.
require_once '../config.php';
$conn = getDBConnection();
if ($conn && !$conn->connect_errno) {
    $user_id = (int)($_SESSION['user_id'] ?? 0);
    $projects = [];

    if ($role === 'global_admin') {
        $stmt = $conn->prepare("
            SELECT id, project_name
            FROM projects
            WHERE status IS NULL OR status = 'active'
            ORDER BY project_name ASC
        ");
    } else {
        $stmt = $conn->prepare("
            SELECT DISTINCT p.id, p.project_name
            FROM projects p
            JOIN customer_account_users cau ON p.account_id = cau.account_id
            WHERE cau.user_id = ?
              AND (p.status IS NULL OR p.status = 'active')
            ORDER BY p.project_name ASC
        ");
    }

    if ($stmt) {
        if ($role !== 'global_admin') {
            $stmt->bind_param("i", $user_id);
        }
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $projects[] = [
                'id' => (int)$row['id'],
                'project_name' => (string)$row['project_name'],
            ];
        }
        $stmt->close();
    }

    $conn->close();

    if (count($projects) === 1) {
        header('Location: view_project.php?project_id=' . (int)$projects[0]['id']);
        exit();
    }

    if (count($projects) === 0) {
        header('Location: manage_projects.php');
        exit();
    }
} else {
    header('Location: manage_projects.php');
    exit();
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Select Project - Manage Deliveries</title>
    <link rel="stylesheet" href="portal.css">
    <link rel="icon" href="pictures/favicon.png" type="image/x-icon">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        body {
            background: #f8f9fa;
            font-family: 'Poppins', sans-serif;
        }
        .delivery-project-picker {
            margin: 0 20px 44px 20px;
        }
        .md-hero {
            background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%);
            border-radius: 24px;
            padding: 32px;
            margin-bottom: 28px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.06);
            border: 1px solid rgba(72, 140, 154, 0.08);
            position: relative;
            overflow: hidden;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 24px;
        }
        .md-hero::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, #488C9A 0%, #293E4C 100%);
        }
        .md-title {
            margin: 0 0 6px;
            font-size: 2.5em;
            font-weight: 700;
            background: linear-gradient(135deg, #293E4C 0%, #488C9A 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            line-height: 1.2;
        }
        .md-subtitle {
            margin: 0;
            color: #6c757d;
            font-size: 1.1em;
            font-weight: 500;
        }
        .md-stat {
            text-align: center;
            background: linear-gradient(135deg, rgba(72, 140, 154, 0.15) 0%, rgba(72, 140, 154, 0.2) 100%);
            color: #488C9A;
            padding: 16px 20px;
            border-radius: 16px;
            min-width: 120px;
            transition: all 0.3s ease;
        }
        .md-stat:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(72, 140, 154, 0.2);
        }
        .md-stat-number {
            display: block;
            font-size: 2em;
            line-height: 1;
            font-weight: 700;
        }
        .md-stat-label {
            display: block;
            margin-top: 4px;
            font-size: 0.85em;
            color: #6c757d;
            font-weight: 500;
        }
        .md-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
            gap: 16px;
        }
        .md-project-card {
            display: flex;
            flex-direction: row;
            align-items: center;
            gap: 16px;
            text-decoration: none;
            background: linear-gradient(135deg, #ffffff 0%, #f8f9fa 100%);
            border: 1px solid rgba(72, 140, 154, 0.12);
            border-radius: 16px;
            padding: 20px;
            min-height: 80px;
            box-shadow: 0 6px 20px rgba(0, 0, 0, 0.06);
            transition: transform 0.3s ease, box-shadow 0.3s ease, border-color 0.3s ease;
            position: relative;
            overflow: hidden;
        }
        .md-project-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            bottom: 0;
            width: 4px;
            background: linear-gradient(180deg, #488C9A 0%, #293E4C 100%);
            opacity: 0;
            transition: opacity 0.3s ease;
        }
        .md-project-card:hover {
            transform: translateY(-3px);
            border-color: rgba(72, 140, 154, 0.35);
            box-shadow: 0 12px 32px rgba(72, 140, 154, 0.18);
        }
        .md-project-card:hover::before {
            opacity: 1;
        }
        .md-project-card:hover .md-project-arrow {
            opacity: 1;
            transform: translateX(0);
            color: #488C9A;
        }
        .md-project-card:hover .md-project-initial {
            transform: scale(1.05);
            box-shadow: 0 6px 16px rgba(72, 140, 154, 0.35);
        }
        .md-project-initial {
            width: 48px;
            height: 48px;
            border-radius: 14px;
            background: linear-gradient(135deg, #488C9A 0%, #3A6E7F 100%);
            color: white;
            font-size: 1.2em;
            font-weight: 700;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            box-shadow: 0 4px 12px rgba(72, 140, 154, 0.25);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            letter-spacing: -0.5px;
        }
        .md-project-card-body {
            flex: 1;
            min-width: 0;
            display: flex;
            flex-direction: column;
            gap: 2px;
        }
        .md-project-name {
            color: #293E4C;
            font-size: 1.15em;
            font-weight: 700;
            line-height: 1.3;
            overflow-wrap: anywhere;
        }
        .md-project-meta {
            color: #6c757d;
            font-size: 0.85em;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .md-project-meta i {
            font-size: 0.85em;
            color: #488C9A;
        }
        .md-project-arrow {
            font-size: 1.1em;
            color: #b0bec5;
            flex-shrink: 0;
            opacity: 0.5;
            transform: translateX(-4px);
            transition: all 0.3s ease;
        }
        .md-actions {
            display: flex;
            gap: 10px;
            margin-top: 24px;
            flex-wrap: wrap;
        }
        .md-action-link {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 999px;
            padding: 10px 18px;
            text-decoration: none;
            font-weight: 600;
            border: 1px solid rgba(72, 140, 154, 0.2);
            color: #3a7280;
            background: #ffffff;
            transition: all .2s ease;
        }
        .md-action-link:hover {
            border-color: rgba(72, 140, 154, 0.4);
            color: #2f5a69;
            box-shadow: 0 6px 14px rgba(72, 140, 154, 0.12);
        }
        .md-action-link.primary {
            color: #ffffff;
            background: linear-gradient(135deg, #488C9A 0%, #3A6E7F 100%);
            border-color: #3A6E7F;
        }
        @media (max-width: 860px) {
            .delivery-project-picker {
                margin: 0 12px 36px 12px;
            }
            .md-hero {
                flex-direction: column;
                align-items: flex-start;
                padding: 24px;
            }
            .md-subtitle {
                font-size: 1em;
            }
            .md-stat {
                width: 100%;
                max-width: 220px;
            }
            .md-stat-number {
                font-size: 1.8em;
            }
            .md-title {
                font-size: 2em;
            }
            .md-project-name {
                font-size: 1.05em;
            }
            .md-project-initial {
                width: 42px;
                height: 42px;
                font-size: 1.1em;
                border-radius: 12px;
            }
            .md-project-arrow {
                display: none;
            }
        }
    </style>
</head>
<body>
<?php include 'header.php'; ?>
<main class="delivery-project-picker">
    <?php
    require_once 'components/breadcrumbs.php';
    echo slp_render_breadcrumbs([
        'current_label' => 'Manage Deliveries',
    ]);
    ?>
    <section class="md-hero">
        <div>
            <h1 class="md-title">Manage Deliveries</h1>
            <p class="md-subtitle">Select a project to open its delivery tracker.</p>
        </div>
        <aside class="md-stat" aria-label="Active projects available">
            <span class="md-stat-number"><?php echo count($projects); ?></span>
            <span class="md-stat-label">Active Projects</span>
        </aside>
    </section>
    <section class="md-grid" aria-label="Project selection">
        <?php foreach ($projects as $project):
            $name = htmlspecialchars($project['project_name']);
            $words = preg_split('/[\s\-_]+/', trim($project['project_name']));
            $initials = '';
            if (count($words) >= 2) {
                $initials = mb_strtoupper(mb_substr($words[0], 0, 1) . mb_substr($words[1], 0, 1));
            } else {
                $initials = mb_strtoupper(mb_substr($project['project_name'], 0, 2));
            }
        ?>
            <a class="md-project-card" href="view_project.php?project_id=<?php echo (int)$project['id']; ?>">
                <span class="md-project-initial"><?php echo htmlspecialchars($initials); ?></span>
                <span class="md-project-card-body">
                    <span class="md-project-name"><?php echo $name; ?></span>
                    <span class="md-project-meta"><i class="fas fa-truck"></i> Open Delivery Tracker</span>
                </span>
                <span class="md-project-arrow"><i class="fas fa-chevron-right"></i></span>
            </a>
        <?php endforeach; ?>
    </section>
    <nav class="md-actions" aria-label="Page actions">
        <a class="md-action-link primary" href="manage_projects">Go to Manage Projects</a>
        <a class="md-action-link" href="dashboard">Back to Dashboard</a>
    </nav>
</main>
</body>
</html>
