<?php
/**
 * Warehouse Audit Sessions Dashboard
 *
 * Lists all warehouse audit sessions for the current user's account (or
 * all accounts if global_admin), filterable by status. Provides the
 * "New Session" CTA that leads to warehouse_audit_new.php.
 */

session_name("logistics_session");
session_start();

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

if (!isset($_SESSION['user_id'])) {
    header("Location: login");
    exit();
}
$role    = $_SESSION['role']    ?? 'user';
$user_id = (int)$_SESSION['user_id'];
if (!in_array($role, ['admin', 'global_admin', 'customer_admin'], true)) {
    header("Location: unauthorized");
    exit();
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/warehouse_audit_helpers.php';

$conn = getDBConnection();
if (!$conn) { die("Database connection failed"); }

$account_id = audit_get_user_account_id($conn, $user_id, $role);
if ($role !== 'global_admin' && $account_id === null) {
    die("No account associated with this user");
}

$status_filter = $_GET['status'] ?? '';
$valid_statuses = AUDIT_SESSION_STATUSES;

$where_parts = [];
$params = [];
$types  = '';
if ($role !== 'global_admin') {
    $where_parts[] = 'was.account_id = ?';
    $params[] = $account_id;
    $types   .= 'i';
}
if ($status_filter !== '' && in_array($status_filter, $valid_statuses, true)) {
    $where_parts[] = 'was.status = ?';
    $params[] = $status_filter;
    $types   .= 's';
}
$where_sql = $where_parts ? 'WHERE ' . implode(' AND ', $where_parts) : '';

$sql = "
    SELECT was.id, was.session_code, was.status, was.customer_name, was.service_fee_usd,
           was.expected_pallet_count, was.started_at, was.completed_at, was.committed_to_inventory_at,
           w.name AS warehouse_name, w.city AS warehouse_city, w.state AS warehouse_state,
           p.project_name,
           (SELECT COUNT(*) FROM warehouse_audit_scans s WHERE s.session_id = was.id AND s.is_soft_deleted = 0) AS total_scans,
           (SELECT COUNT(*) FROM warehouse_audit_scans s WHERE s.session_id = was.id AND s.is_soft_deleted = 0 AND s.is_discrepancy = 1) AS discrepancy_count
    FROM warehouse_audit_sessions was
    JOIN warehouses w ON was.warehouse_id = w.id
    LEFT JOIN projects p ON was.project_id = p.id
    $where_sql
    ORDER BY was.created_at DESC
    LIMIT 200
";
$stmt = $conn->prepare($sql);
if ($params) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();
$sessions = [];
while ($row = $result->fetch_assoc()) { $sessions[] = $row; }
$stmt->close();
$conn->close();

function status_badge_class($status) {
    switch ($status) {
        case 'in_progress': return 'badge-active';
        case 'review':      return 'badge-review';
        case 'committed':   return 'badge-committed';
        case 'completed':   return 'badge-complete';
        case 'cancelled':   return 'badge-cancelled';
        default:            return 'badge-draft';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Warehouse Audit Sessions</title>
<link rel="stylesheet" href="portal.css">
<link rel="stylesheet" href="css/warehouse_audit.css">
<link rel="icon" href="pictures/favicon.png" type="image/x-icon">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<style>
.audit-page {
    max-width: 1200px;
    margin: 0 auto;
    padding: 24px 16px;
}
.audit-header {
    background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%);
    border-radius: 24px;
    padding: 28px 32px;
    margin-bottom: 24px;
    box-shadow: 0 8px 32px rgba(0, 0, 0, 0.06);
    border: 1px solid rgba(72, 140, 154, 0.08);
    position: relative;
    overflow: hidden;
}
.audit-header::before {
    content: ''; position: absolute; top: 0; left: 0; right: 0; height: 4px;
    background: linear-gradient(90deg, #488C9A 0%, #293E4C 100%);
}
.audit-header h1 {
    font-size: 2rem; font-weight: 700; margin: 0 0 8px 0;
    background: linear-gradient(135deg, #293E4C 0%, #488C9A 100%);
    -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text;
}
.audit-header p { color: #6c757d; margin: 0; }
.audit-header .header-actions { margin-top: 20px; display: flex; gap: 12px; flex-wrap: wrap; align-items: center; }
.btn-primary {
    background: linear-gradient(135deg, #488C9A 0%, #293E4C 100%);
    color: white; border: none; padding: 12px 24px; border-radius: 12px; font-weight: 600;
    cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; gap: 8px;
    transition: transform 0.15s, box-shadow 0.15s;
}
.btn-primary:hover { transform: translateY(-1px); box-shadow: 0 4px 12px rgba(72, 140, 154, 0.3); }
.filter-chips { display: flex; gap: 8px; flex-wrap: wrap; }
.filter-chip {
    padding: 6px 14px; border-radius: 20px; font-size: 0.85rem;
    background: #e9ecef; color: #495057; text-decoration: none;
    border: 1px solid transparent; transition: background 0.15s;
}
.filter-chip.active { background: #488C9A; color: white; }
.filter-chip:hover { background: #dee2e6; }
.filter-chip.active:hover { background: #3d7987; }
.session-grid {
    display: grid;
    gap: 16px;
    grid-template-columns: repeat(auto-fill, minmax(340px, 1fr));
}
.session-card {
    background: white; border-radius: 16px; padding: 20px; border: 1px solid #e9ecef;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
    transition: transform 0.15s, box-shadow 0.15s;
    text-decoration: none; color: inherit; display: block;
}
.session-card:hover { transform: translateY(-2px); box-shadow: 0 8px 24px rgba(0, 0, 0, 0.08); }
.session-card-head { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 12px; }
.session-code { font-family: monospace; font-weight: 600; color: #293E4C; font-size: 0.95rem; }
.status-badge {
    font-size: 0.7rem; font-weight: 700; text-transform: uppercase; padding: 4px 10px; border-radius: 12px;
    letter-spacing: 0.5px;
}
.badge-active    { background: #d1ecf1; color: #0c5460; }
.badge-review    { background: #fff3cd; color: #856404; }
.badge-committed { background: #d4edda; color: #155724; }
.badge-complete  { background: #488C9A; color: white; }
.badge-cancelled { background: #f8d7da; color: #721c24; }
.badge-draft     { background: #e9ecef; color: #6c757d; }
.session-customer { font-weight: 600; color: #293E4C; font-size: 1.05rem; margin-bottom: 4px; }
.session-meta { color: #6c757d; font-size: 0.85rem; margin-bottom: 12px; }
.session-stats { display: flex; gap: 16px; padding-top: 12px; border-top: 1px solid #f1f3f4; }
.session-stat { flex: 1; }
.session-stat .stat-num { font-size: 1.3rem; font-weight: 700; color: #293E4C; }
.session-stat .stat-label { font-size: 0.75rem; color: #6c757d; text-transform: uppercase; letter-spacing: 0.5px; }
.empty-state { text-align: center; padding: 60px 20px; color: #6c757d; }
.empty-state h3 { color: #293E4C; margin-bottom: 8px; }
</style>
</head>
<body>
<?php include 'header.php'; ?>

<div class="audit-page">
    <div class="audit-header">
        <h1>Warehouse Audit Sessions</h1>
        <p>Mobile-first pallet-by-pallet verification with photo evidence, discrepancy tracking, and customer-ready PDF reports.</p>
        <div class="header-actions">
            <a href="warehouse_audit_new" class="btn-primary">+ New Audit Session</a>
            <div class="filter-chips">
                <a href="warehouse_audit_sessions" class="filter-chip <?= $status_filter === '' ? 'active' : '' ?>">All</a>
                <?php foreach ($valid_statuses as $st): ?>
                    <a href="warehouse_audit_sessions?status=<?= urlencode($st) ?>" class="filter-chip <?= $status_filter === $st ? 'active' : '' ?>">
                        <?= htmlspecialchars(ucwords(str_replace('_', ' ', $st))) ?>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <?php if (empty($sessions)): ?>
        <div class="empty-state">
            <h3>No audit sessions yet</h3>
            <p>Start a new session to begin verifying pallets at a warehouse.</p>
        </div>
    <?php else: ?>
        <div class="session-grid">
            <?php foreach ($sessions as $s):
                $verified = max(0, (int)$s['total_scans'] - (int)$s['discrepancy_count']);
                $expected = (int)$s['expected_pallet_count'];
                $warehouse_label = htmlspecialchars($s['warehouse_name'] . ($s['warehouse_city'] ? ', ' . $s['warehouse_city'] : ''));
            ?>
            <a class="session-card" href="warehouse_audit_session?s=<?= (int)$s['id'] ?>">
                <div class="session-card-head">
                    <span class="session-code"><?= htmlspecialchars($s['session_code']) ?></span>
                    <span class="status-badge <?= status_badge_class($s['status']) ?>">
                        <?= htmlspecialchars(str_replace('_', ' ', $s['status'])) ?>
                    </span>
                </div>
                <div class="session-customer"><?= htmlspecialchars($s['customer_name'] ?: ($s['project_name'] ?: 'Untitled audit')) ?></div>
                <div class="session-meta">
                    <?= $warehouse_label ?>
                    <?php if (!empty($s['started_at'])): ?>
                         · <?= htmlspecialchars(date('M j, Y', strtotime($s['started_at']))) ?>
                    <?php endif; ?>
                </div>
                <div class="session-stats">
                    <div class="session-stat">
                        <div class="stat-num"><?= $verified ?></div>
                        <div class="stat-label">Verified</div>
                    </div>
                    <div class="session-stat">
                        <div class="stat-num"><?= (int)$s['discrepancy_count'] ?></div>
                        <div class="stat-label">Flags</div>
                    </div>
                    <div class="session-stat">
                        <div class="stat-num"><?= $expected ?: '–' ?></div>
                        <div class="stat-label">Expected</div>
                    </div>
                </div>
            </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

</body>
</html>
