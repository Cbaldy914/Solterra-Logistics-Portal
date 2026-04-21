<?php
/**
 * Warehouse Audit Review (pre-commit)
 *
 * Read-only review page showing reconciliation summary, discrepancy table,
 * equipment status, and the big "Commit to Inventory" CTA. Commit is blocked
 * until every equipment row has an explicit status (not 'not_verified').
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

// Load non-deleted scans, discrepancies first
$stmt = $conn->prepare("
    SELECT id, sequence_number, scan_method, raw_value, normalized_pallet_id,
           parsed_wattage, quantity, is_discrepancy, discrepancy_codes, discrepancy_notes,
           ai_confidence
    FROM warehouse_audit_scans
    WHERE session_id = ? AND is_soft_deleted = 0
    ORDER BY is_discrepancy DESC, sequence_number ASC
");
$stmt->bind_param("i", $session_id);
$stmt->execute();
$result = $stmt->get_result();
$scans = [];
$discrepancies = [];
while ($row = $result->fetch_assoc()) {
    $scans[] = $row;
    if ($row['is_discrepancy']) $discrepancies[] = $row;
}
$stmt->close();

// Load equipment
$stmt = $conn->prepare("SELECT * FROM warehouse_audit_equipment_checks WHERE session_id = ? ORDER BY id");
$stmt->bind_param("i", $session_id);
$stmt->execute();
$result = $stmt->get_result();
$equipment = [];
$unverified_equipment = 0;
while ($row = $result->fetch_assoc()) {
    $equipment[] = $row;
    if ($row['status'] === 'not_verified') $unverified_equipment++;
}
$stmt->close();

// Warehouse + project display
$stmt = $conn->prepare("SELECT name, city, state FROM warehouses WHERE id = ? LIMIT 1");
$stmt->bind_param("i", $session['warehouse_id']);
$stmt->execute();
$warehouse = $stmt->get_result()->fetch_assoc();
$stmt->close();

$project_name = null;
if (!empty($session['project_id'])) {
    $stmt = $conn->prepare("SELECT project_name FROM projects WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $session['project_id']);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $project_name = $row ? $row['project_name'] : null;
}

$conn->close();

$already_committed = !empty($session['committed_to_inventory_at']);
$can_commit = !$already_committed && $unverified_equipment === 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Review: <?= htmlspecialchars($session['session_code']) ?></title>
<link rel="stylesheet" href="portal.css">
<link rel="stylesheet" href="css/warehouse_audit.css">
<link rel="icon" href="pictures/favicon.png" type="image/x-icon">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<style>
.review-page { max-width: 1100px; margin: 0 auto; padding: 20px 16px 120px; }
.review-header {
    background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%);
    border-radius: 20px; padding: 24px; margin-bottom: 20px;
    box-shadow: 0 8px 32px rgba(0,0,0,0.06);
    border: 1px solid rgba(72,140,154,0.08);
    position: relative; overflow: hidden;
}
.review-header::before { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 4px; background: var(--wa-gradient); }
.review-header h1 { margin: 0 0 4px 0; color: var(--wa-dark); font-size: 1.6rem; }
.review-header .session-code { font-family: ui-monospace, monospace; color: var(--wa-primary); font-weight: 600; }
.review-header .header-meta { color: var(--wa-gray-6); margin-top: 6px; font-size: 0.9rem; }

.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 12px;
    margin-bottom: 24px;
}
.stat-tile {
    background: white;
    padding: 20px;
    border-radius: 16px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.04);
    text-align: center;
}
.stat-tile .stat-num { font-size: 2.4rem; font-weight: 700; color: var(--wa-dark); line-height: 1; }
.stat-tile .stat-label { color: var(--wa-gray-6); text-transform: uppercase; font-size: 0.75rem; letter-spacing: 0.5px; margin-top: 6px; }
.stat-tile.stat-verified .stat-num  { color: var(--wa-success); }
.stat-tile.stat-flags .stat-num     { color: var(--wa-warn); }
.stat-tile.stat-missing .stat-num   { color: var(--wa-error); }

.section-card {
    background: white; border-radius: 16px; padding: 24px; margin-bottom: 20px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.04);
    border: 1px solid var(--wa-gray-2);
}
.section-card h2 {
    margin: 0 0 16px 0;
    font-size: 1.2rem;
    color: var(--wa-dark);
    padding-bottom: 10px;
    border-bottom: 2px solid var(--wa-gray-2);
}

.reconcile-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.9rem;
}
.reconcile-table th, .reconcile-table td {
    padding: 10px 8px;
    text-align: left;
    border-bottom: 1px solid var(--wa-gray-2);
}
.reconcile-table th { background: var(--wa-gray-1); font-weight: 600; color: var(--wa-dark); }
.reconcile-table td.num { text-align: right; font-family: ui-monospace, monospace; }
.variance-good { color: var(--wa-success); font-weight: 600; }
.variance-bad  { color: var(--wa-error); font-weight: 600; }

.discrepancy-list { display: flex; flex-direction: column; gap: 10px; }
.disc-row {
    background: #fffbf3;
    border-left: 4px solid var(--wa-warn);
    padding: 12px 16px;
    border-radius: 10px;
}
.disc-row.disc-ai-low { border-left-color: #9f7aea; }
.disc-row-top { display: flex; justify-content: space-between; align-items: baseline; gap: 12px; }
.disc-id { font-weight: 600; color: var(--wa-dark); font-family: ui-monospace, monospace; font-size: 0.85rem; }
.disc-codes { font-size: 0.75rem; color: #a04500; text-transform: uppercase; letter-spacing: 0.3px; }
.disc-notes { color: var(--wa-gray-6); font-size: 0.85rem; margin-top: 4px; }

.commit-bar {
    position: fixed; bottom: 0; left: 0; right: 0;
    background: white;
    padding: 16px;
    border-top: 1px solid var(--wa-gray-2);
    box-shadow: 0 -2px 12px rgba(0,0,0,0.08);
    z-index: 100;
}
.commit-bar-inner {
    max-width: 1100px;
    margin: 0 auto;
    display: flex;
    align-items: center;
    gap: 16px;
}
.commit-status {
    flex: 1;
    font-size: 0.9rem;
    color: var(--wa-gray-6);
}
.commit-status.blocked { color: var(--wa-error); font-weight: 500; }
.btn-commit {
    padding: 14px 28px;
    background: var(--wa-gradient);
    color: white;
    border: none;
    border-radius: 12px;
    font-weight: 600;
    font-size: 1rem;
    cursor: pointer;
    min-height: 50px;
}
.btn-commit:disabled { opacity: 0.5; cursor: not-allowed; background: var(--wa-gray-4); }
.btn-back {
    padding: 14px 20px;
    background: white;
    border: 1px solid var(--wa-gray-4);
    border-radius: 12px;
    font-weight: 500;
    cursor: pointer;
    color: var(--wa-dark);
    text-decoration: none;
    font-family: inherit;
}
.empty { color: var(--wa-gray-6); text-align: center; padding: 20px; }
.alert {
    background: #fff3cd;
    color: #856404;
    border-left: 4px solid var(--wa-warn);
    padding: 14px 18px;
    border-radius: 10px;
    margin-bottom: 20px;
}
.alert.error { background: #f8d7da; color: #721c24; border-left-color: var(--wa-error); }
.alert.success { background: #d4edda; color: #155724; border-left-color: var(--wa-success); }
</style>
</head>
<body>
<?php include 'header.php'; ?>

<div class="review-page">
    <div class="review-header">
        <h1>Review Audit Session</h1>
        <div class="session-code"><?= htmlspecialchars($session['session_code']) ?></div>
        <div class="header-meta">
            <?= htmlspecialchars($session['customer_name'] ?: ($project_name ?: 'Untitled audit')) ?> ·
            <?= htmlspecialchars($warehouse['name'] ?? '') ?><?php
            if (!empty($warehouse['city'])) echo ', ' . htmlspecialchars($warehouse['city']); ?>
        </div>
    </div>

    <?php if ($already_committed): ?>
        <div class="alert success">
            <strong>✓ Committed to inventory</strong> on <?= htmlspecialchars(date('M j, Y g:i A', strtotime($session['committed_to_inventory_at']))) ?>.
            You can download the PDF report from the <a href="warehouse_audit_complete?s=<?= $session_id ?>">completion page</a>.
        </div>
    <?php elseif ($unverified_equipment > 0): ?>
        <div class="alert error">
            <strong>Cannot commit yet:</strong> <?= $unverified_equipment ?> equipment item(s) are still marked <code>not_verified</code>.
            Go back to the session and explicitly mark each as verified, missing, damaged, or wrong model.
        </div>
    <?php endif; ?>

    <div class="stats-grid">
        <div class="stat-tile">
            <div class="stat-num"><?= (int)$reconciliation['expected_count'] ?: '–' ?></div>
            <div class="stat-label">Expected</div>
        </div>
        <div class="stat-tile stat-verified">
            <div class="stat-num"><?= (int)$reconciliation['verified_count'] ?></div>
            <div class="stat-label">Verified</div>
        </div>
        <div class="stat-tile stat-flags">
            <div class="stat-num"><?= (int)$reconciliation['discrepancy_count'] ?></div>
            <div class="stat-label">Discrepancies</div>
        </div>
        <div class="stat-tile stat-missing">
            <div class="stat-num"><?= (int)$reconciliation['missing_count'] ?></div>
            <div class="stat-label">Missing</div>
        </div>
    </div>

    <div class="section-card">
        <h2>Reconciliation by Wattage</h2>
        <?php if (empty($reconciliation['by_wattage'])): ?>
            <div class="empty">No scans yet.</div>
        <?php else: ?>
            <table class="reconcile-table">
                <thead>
                    <tr>
                        <th>Wattage</th>
                        <th class="num">Verified</th>
                        <th class="num">Discrepancy</th>
                        <th class="num">Total Modules</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($reconciliation['by_wattage'] as $w => $data):
                        $label = $w === 'unknown' ? 'Unknown' : (int)$w . ' W';
                    ?>
                    <tr>
                        <td><?= htmlspecialchars($label) ?></td>
                        <td class="num"><?= (int)$data['verified'] ?></td>
                        <td class="num <?= $data['discrepancy'] > 0 ? 'variance-bad' : '' ?>"><?= (int)$data['discrepancy'] ?></td>
                        <td class="num"><?= number_format((int)$data['total_modules']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <div class="section-card">
        <h2>Discrepancies (<?= count($discrepancies) ?>)</h2>
        <?php if (empty($discrepancies)): ?>
            <div class="empty">✓ No discrepancies flagged.</div>
        <?php else: ?>
            <div class="discrepancy-list">
                <?php foreach ($discrepancies as $d):
                    $codes = $d['discrepancy_codes'] ? explode(',', $d['discrepancy_codes']) : [];
                    $is_ai_low = in_array('LOW_AI_CONFIDENCE', $codes, true);
                ?>
                <div class="disc-row <?= $is_ai_low ? 'disc-ai-low' : '' ?>">
                    <div class="disc-row-top">
                        <span class="disc-id">#<?= (int)$d['sequence_number'] ?> · <?= htmlspecialchars($d['raw_value'] ?: $d['normalized_pallet_id']) ?></span>
                        <span class="disc-codes"><?= htmlspecialchars(implode(' · ', array_map(function ($c) { return strtolower(str_replace('_', ' ', $c)); }, $codes))) ?></span>
                    </div>
                    <?php if (!empty($d['discrepancy_notes'])): ?>
                        <div class="disc-notes"><?= htmlspecialchars($d['discrepancy_notes']) ?></div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <div class="section-card">
        <h2>Equipment Verification</h2>
        <?php if (empty($equipment)): ?>
            <div class="empty">No equipment items for this session.</div>
        <?php else: ?>
            <div class="equipment-list-panel">
                <?php foreach ($equipment as $eq): ?>
                <div class="eq-row eq-status-<?= htmlspecialchars($eq['status']) ?>">
                    <div class="eq-main">
                        <div class="eq-type"><?= htmlspecialchars($eq['equipment_type']) ?></div>
                        <div class="eq-sub">
                            <?= htmlspecialchars($eq['manufacturer_name'] ?: '') ?>
                            <?php if (!empty($eq['serial_number'])): ?> · S/N <?= htmlspecialchars($eq['serial_number']) ?><?php endif; ?>
                        </div>
                    </div>
                    <div class="eq-status-label"><?= htmlspecialchars(str_replace('_', ' ', $eq['status'])) ?></div>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="commit-bar">
    <div class="commit-bar-inner">
        <a href="warehouse_audit_session?s=<?= $session_id ?>" class="btn-back">← Back to scanning</a>
        <div class="commit-status <?= $can_commit ? '' : 'blocked' ?>">
            <?php if ($already_committed): ?>
                This session has already been committed.
            <?php elseif ($unverified_equipment > 0): ?>
                <?= $unverified_equipment ?> equipment item(s) still unverified.
            <?php else: ?>
                Ready to commit <?= (int)$reconciliation['verified_count'] ?> verified pallets into inventory.
            <?php endif; ?>
        </div>
        <button id="btnCommit" class="btn-commit" <?= $can_commit ? '' : 'disabled' ?>>
            <?= $already_committed ? 'Committed ✓' : 'Commit to Inventory →' ?>
        </button>
    </div>
</div>

<script>
(function () {
    var btn = document.getElementById('btnCommit');
    if (!btn || btn.disabled) return;
    btn.addEventListener('click', function () {
        if (!confirm('Commit <?= (int)$reconciliation['verified_count'] ?> verified pallets into inventory? This cannot be undone.')) return;
        btn.disabled = true;
        btn.textContent = 'Committing…';
        var fd = new FormData();
        fd.append('csrf_token', '<?= htmlspecialchars($_SESSION['csrf_token']) ?>');
        fd.append('session_id', '<?= (int)$session_id ?>');
        fetch('api/audit/commit_inventory.php', { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data.success) {
                    alert('Commit failed: ' + (data.error || 'unknown'));
                    btn.disabled = false;
                    btn.textContent = 'Commit to Inventory →';
                    return;
                }
                alert('Committed ' + data.pallets_created + ' pallets (' + data.pallets_skipped + ' skipped).');
                // Move the session to completed status
                var fd2 = new FormData();
                fd2.append('csrf_token', '<?= htmlspecialchars($_SESSION['csrf_token']) ?>');
                fd2.append('session_id', '<?= (int)$session_id ?>');
                fetch('api/audit/complete_session.php', { method: 'POST', body: fd2, credentials: 'same-origin' })
                    .then(function (r) { return r.json(); })
                    .then(function () {
                        window.location.href = 'warehouse_audit_complete?s=<?= (int)$session_id ?>';
                    });
            })
            .catch(function (err) {
                alert('Commit failed: ' + err.message);
                btn.disabled = false;
                btn.textContent = 'Commit to Inventory →';
            });
    });
})();
</script>

</body>
</html>
