<?php
/**
 * Warehouse Audit Completion Page
 *
 * Post-commit summary with PDF download link and a return-to-dashboard CTA.
 * Shown after the user commits a session to inventory.
 */

session_name("logistics_session");
session_start();

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

if (!isset($_SESSION['user_id'])) { header("Location: login"); exit(); }
$role    = $_SESSION['role']    ?? 'user';
$user_id = (int)$_SESSION['user_id'];
if (!in_array($role, ['admin', 'global_admin', 'customer_admin'], true)) {
    header("Location: unauthorized"); exit();
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/warehouse_audit_helpers.php';

$session_id = isset($_GET['s']) ? (int)$_GET['s'] : 0;
if (!$session_id) { header("Location: warehouse_audit_sessions"); exit(); }

$conn = getDBConnection();
if (!$conn) { die("Database connection failed"); }

try {
    $session = audit_require_session_access($conn, $session_id, $user_id, $role);
} catch (Throwable $e) {
    http_response_code(403);
    die("Access denied: " . htmlspecialchars($e->getMessage()));
}

$reconciliation = audit_build_reconciliation($conn, $session_id);

$stmt = $conn->prepare("SELECT name, city, state FROM warehouses WHERE id = ? LIMIT 1");
$stmt->bind_param("i", $session['warehouse_id']);
$stmt->execute();
$warehouse = $stmt->get_result()->fetch_assoc();
$stmt->close();

$stmt = $conn->prepare("SELECT COUNT(*) AS n FROM inventory_pallets WHERE created_from_audit_scan_id IN (SELECT id FROM warehouse_audit_scans WHERE session_id = ?)");
$stmt->bind_param("i", $session_id);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$pallets_in_inventory = (int)$row['n'];
$stmt->close();

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Audit Complete: <?= htmlspecialchars($session['session_code']) ?></title>
<link rel="stylesheet" href="portal.css">
<link rel="stylesheet" href="css/warehouse_audit.css">
<link rel="icon" href="pictures/favicon.png" type="image/x-icon">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<style>
.complete-page { max-width: 720px; margin: 0 auto; padding: 40px 20px; text-align: center; }
.complete-icon {
    width: 100px; height: 100px;
    border-radius: 50%;
    background: linear-gradient(135deg, #28a745 0%, #1e7e34 100%);
    color: white;
    font-size: 3rem;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto 24px;
    box-shadow: 0 10px 30px rgba(40, 167, 69, 0.3);
}
.complete-page h1 { color: #293E4C; font-size: 2rem; margin: 0 0 8px 0; }
.complete-subtitle { color: #6c757d; margin-bottom: 32px; font-size: 1.1rem; }
.complete-card {
    background: white;
    border-radius: 20px;
    padding: 32px;
    box-shadow: 0 8px 32px rgba(0,0,0,0.06);
    border: 1px solid #e9ecef;
    margin-bottom: 20px;
    text-align: left;
}
.session-info {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 16px;
    margin-bottom: 24px;
}
.info-block .label { font-size: 0.75rem; text-transform: uppercase; color: #6c757d; letter-spacing: 0.5px; }
.info-block .value { font-size: 1.05rem; font-weight: 600; color: #293E4C; margin-top: 4px; }
.info-block .value.mono { font-family: ui-monospace, Menlo, monospace; }
.stats-inline {
    display: flex;
    justify-content: space-around;
    padding: 20px 0;
    border-top: 1px solid #f1f3f4;
    border-bottom: 1px solid #f1f3f4;
    margin: 24px 0;
}
.stats-inline .s { text-align: center; }
.stats-inline .n { font-size: 1.8rem; font-weight: 700; color: #293E4C; }
.stats-inline .l { font-size: 0.7rem; text-transform: uppercase; color: #6c757d; }
.stats-inline .n.good { color: #28a745; }
.stats-inline .n.warn { color: #f0ad4e; }
.actions { display: flex; gap: 12px; justify-content: center; flex-wrap: wrap; margin-top: 20px; }
.btn-pdf {
    background: linear-gradient(135deg, #488C9A 0%, #293E4C 100%);
    color: white;
    border: none;
    padding: 16px 32px;
    border-radius: 14px;
    font-weight: 600;
    font-size: 1rem;
    cursor: pointer;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 8px;
}
.btn-pdf:hover { transform: translateY(-1px); box-shadow: 0 4px 12px rgba(72, 140, 154, 0.3); }
.btn-return {
    background: white;
    color: #495057;
    border: 1px solid #ced4da;
    padding: 16px 32px;
    border-radius: 14px;
    font-weight: 500;
    text-decoration: none;
    font-family: inherit;
    font-size: 1rem;
}
</style>
</head>
<body>
<?php include 'header.php'; ?>

<div class="complete-page">
    <div class="complete-icon">✓</div>
    <h1>Audit Complete</h1>
    <p class="complete-subtitle">
        Session <strong><?= htmlspecialchars($session['session_code']) ?></strong> has been committed to inventory
        and is ready to deliver to the customer.
    </p>

    <div class="complete-card">
        <div class="session-info">
            <div class="info-block">
                <div class="label">Customer</div>
                <div class="value"><?= htmlspecialchars($session['customer_name'] ?: 'Untitled') ?></div>
            </div>
            <div class="info-block">
                <div class="label">Warehouse</div>
                <div class="value"><?= htmlspecialchars(($warehouse['name'] ?? '') . ($warehouse['city'] ? ', ' . $warehouse['city'] : '')) ?></div>
            </div>
            <div class="info-block">
                <div class="label">Session Code</div>
                <div class="value mono"><?= htmlspecialchars($session['session_code']) ?></div>
            </div>
            <div class="info-block">
                <div class="label">Committed</div>
                <div class="value"><?= !empty($session['committed_to_inventory_at']) ? htmlspecialchars(date('M j, Y g:i A', strtotime($session['committed_to_inventory_at']))) : '—' ?></div>
            </div>
        </div>

        <div class="stats-inline">
            <div class="s">
                <div class="n good"><?= (int)$reconciliation['verified_count'] ?></div>
                <div class="l">Verified</div>
            </div>
            <div class="s">
                <div class="n warn"><?= (int)$reconciliation['discrepancy_count'] ?></div>
                <div class="l">Discrepancies</div>
            </div>
            <div class="s">
                <div class="n"><?= $pallets_in_inventory ?></div>
                <div class="l">In Inventory</div>
            </div>
            <?php if ((int)$session['ai_vision_call_count'] > 0): ?>
            <div class="s">
                <div class="n">$<?= number_format((float)$session['ai_vision_cost_estimate_usd'], 3) ?></div>
                <div class="l">AI Cost</div>
            </div>
            <?php endif; ?>
        </div>

        <div class="actions">
            <a class="btn-pdf" href="generate_warehouse_audit_pdf.php?session_id=<?= $session_id ?>&mode=inline" target="_blank">
                📄 View Audit Report PDF
            </a>
            <a class="btn-pdf" href="generate_warehouse_audit_pdf.php?session_id=<?= $session_id ?>&mode=download">
                ⬇ Download PDF
            </a>
            <a class="btn-return" href="warehouse_audit_sessions">Back to Sessions</a>
        </div>
    </div>
</div>

</body>
</html>
