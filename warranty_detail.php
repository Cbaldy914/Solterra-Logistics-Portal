<?php
session_name('logistics_session');
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: login');
    exit();
}

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/warranty_helpers.php';

$conn = getDBConnection();

$claimId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($claimId <= 0) {
    die('Invalid claim ID');
}

// Load claim and project
$sql = "SELECT w.*, ss.project_id, p.project_name
        FROM warranty_claims w
        JOIN site_scheduling ss ON ss.id = w.scheduling_id
        JOIN projects p ON p.id = ss.project_id
        WHERE w.id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param('i', $claimId);
$stmt->execute();
$res = $stmt->get_result();
$claim = $res->fetch_assoc();
$stmt->close();
if (!$claim) {
    die('Claim not found');
}

// Authorization: ensure access to project
$role = $_SESSION['role'] ?? 'user';
$userId = (int)($_SESSION['user_id'] ?? 0);
$allowed = getAllowedProjectIds($conn, $userId, $role);
if (is_array($allowed) && !in_array((int)$claim['project_id'], $allowed, true)) {
    die('Unauthorized');
}

$isAdmin = isAdminRole();

// Load events
$eventsPublic = [];
$q = $conn->prepare('SELECT event_ts, event_text FROM warranty_claim_events WHERE claim_id = ? AND is_public = 1 ORDER BY event_ts DESC');
$q->bind_param('i', $claimId);
$q->execute();
$rp = $q->get_result();
while ($row = $rp->fetch_assoc()) { $eventsPublic[] = $row; }
$q->close();

$eventsAll = [];
if ($isAdmin) {
    $qa = $conn->prepare('SELECT event_ts, event_text, is_public FROM warranty_claim_events WHERE claim_id = ? ORDER BY event_ts DESC');
    $qa->bind_param('i', $claimId);
    $qa->execute();
    $ra = $qa->get_result();
    while ($row = $ra->fetch_assoc()) { $eventsAll[] = $row; }
    $qa->close();
}

// Attachments
$pictures = jsonToArray($claim['pictures'] ?? '');
$proofPrimary = $claim['proof_of_completion_path'] ?? '';

// Replacement pallets linked
$linkedIds = listLinkedReplacementPalletIds($conn, $claimId);
$linkedMap = getPalletIdentifiers($conn, $linkedIds);

// Pallet choices (same project by default)
$choices = [];
$ps = $conn->prepare('SELECT id, COALESCE(pallet_identifier, CONCAT("ID ", id)) label FROM inventory_pallets WHERE assigned_project_id = ? ORDER BY id DESC LIMIT 500');
$pid = (int)$claim['project_id'];
$ps->bind_param('i', $pid);
$ps->execute();
$prs = $ps->get_result();
while ($row = $prs->fetch_assoc()) { $choices[] = $row; }
$ps->close();

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Warranty Ticket #<?php echo htmlspecialchars($claimId); ?></title>
    <link rel="stylesheet" href="portal.css">
    <link rel="icon" href="pictures/favicon.png" type="image/x-icon">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        /* Breadcrumbs */
        .breadcrumb { display:flex; margin-bottom:20px; }
        .breadcrumb a { color: #488C9A; text-decoration: none; }
        .breadcrumb .separator { margin: 0 8px; color: #6c757d; }

        /* Header + badges */
        .page-header { display:flex; flex-wrap:wrap; gap:12px; align-items:flex-start; justify-content:space-between; margin: 12px 20px 18px; }
        .badge-chip { display:inline-flex; align-items:center; gap:6px; padding:8px 14px; border-radius:24px; font-weight:600; font-size:0.85rem; border:1px solid #e6edf1; background:linear-gradient(135deg,#F5FAFB,#FFFFFF); color:#253b49; }
        .badge-issue { background:linear-gradient(135deg,#E8F4F6,#F5FAFB); }
        .badge-ticket { background:#f7f9fb; }
        .status-badge { padding:8px 14px; border-radius:24px; font-weight:700; font-size:0.85rem; background:#E8F4F6; color:#2C3E50; border:1px solid #e6edf1; }

        /* Grid */
        .layout { display:grid; grid-template-columns: 1.15fr 0.85fr; gap:20px; margin: 0 20px 20px; align-items:start; }
        @media(max-width: 992px){ .layout { grid-template-columns: 1fr; } }

        /* Cards */
        .card { background:#fff; border:1px solid #e8edf2; border-radius:14px; box-shadow:0 12px 36px rgba(0,0,0,0.06); overflow:hidden; }
        .card-header { padding:14px 18px; border-bottom:1px solid #eef2f6; font-weight:700; color:#293E4C; letter-spacing:0.2px; background:linear-gradient(180deg,#ffffff,#fbfdfe); }
        .card-body { padding:16px 18px; }

        /* Timeline */
        .timeline { list-style:none; padding:0; margin:0; display:flex; flex-direction:column; gap:12px; }
        .timeline-item { background:#f9fbfd; border:1px solid #e8edf2; border-radius:12px; padding:12px 14px; }
        .timeline-time { color:#6c757d; font-size:0.84rem; margin-bottom:4px; }

        /* Gallery */
        .gallery { display:grid; grid-template-columns: repeat(auto-fill, minmax(140px,1fr)); gap:12px; }
        .gallery a { display:block; border:1px solid #e8edf2; border-radius:12px; overflow:hidden; background:#fbfdff; transition:transform .15s ease; }
        .gallery a:hover { transform: translateY(-2px); box-shadow:0 10px 24px rgba(0,0,0,0.08); }
        .gallery img { width:100%; height:120px; object-fit:cover; display:block; }

        /* Stepper */
        .progress { display:flex; gap:10px; padding: 10px 6px; }
        .step { flex:1; height:10px; border-radius:8px; background:#ecf1f5; position:relative; overflow:hidden; }
        .step::before { content:''; position:absolute; inset:0; background:linear-gradient(90deg, #e7eff5, #ecf4f7); }
        .step.active::before { background:linear-gradient(90deg, #488C9A, #3A6E7F); }
        .step::after { content:''; position:absolute; top:-7px; right:-5px; width:10px; height:10px; border-radius:50%; background:#dbe5ec; }
        .step.active::after { background:#3A6E7F; }

        /* Forms - spaced and clean */
        .form-label { display:block; }
        .admin-panel .form-label { display:block; font-weight:600; color:#2C3E50; letter-spacing:0.2px; margin-bottom:6px; }
        .form-select, .form-control { border:1px solid #e2e9ef; border-radius:10px; padding:8px 12px; }
        .form-select:focus, .form-control:focus { border-color:#488C9A; box-shadow:0 0 0 3px rgba(72,140,154,0.15); }
        .admin-form-row { display:grid; grid-template-columns: 1fr 1fr; gap:16px; margin-bottom:16px; }
        .admin-form-row.full { grid-template-columns: 1fr; }
        .admin-form-group { margin-bottom:14px; }
        .form-hint { color:#6c757d; font-size:0.8rem; margin-top:4px; }

        /* Buttons */
        .btn-primary { background: linear-gradient(135deg, #488C9A, #3A6E7F); border:none; border-radius:12px; padding:12px 20px; box-shadow:0 10px 24px rgba(58,110,127,0.25); font-weight:700; color:#fff !important; }
        .btn-primary:hover { filter:brightness(0.96); transform: translateY(-1px); box-shadow:0 14px 30px rgba(58,110,127,0.30); color:#fff !important; }
        .btn-secondary { background:#f8f9fa; border:1px solid #e9ecef; border-radius:12px; padding:10px 16px; font-weight:600; color:#495057; }
        .btn-secondary:hover { background:#e9ecef; border-color:#dee2e6; }
        .action-btn { display:inline-flex; align-items:center; gap:8px; padding:8px 12px; border-radius:8px; border:none; font-size:0.9rem; font-weight:600; cursor:pointer; transition:all 0.15s ease; }
        .action-btn-primary { background:linear-gradient(135deg,#488C9A,#3A6E7F); color:#fff; }
        .action-btn-primary:hover { filter:brightness(1.05); transform:translateY(-1px); }
        .action-btn-secondary { background:#fff; border:1px solid #e1e6ea; color:#495057; }
        .action-btn-secondary:hover { background:#f8f9fa; border-color:#dee2e6; }
        .replacement-only { display:none; }

        /* Tabs */
        .tabs { margin: 0 20px 80px; }
        .tabs-nav { display:flex; gap:12px; border-bottom:1px solid #e9ecef; margin-bottom:12px; }
        .tabs-nav a { padding:10px 14px; border-radius:10px 10px 0 0; background:#f4f7f9; border:1px solid #e9ecef; border-bottom:none; text-decoration:none; color:#293E4C; font-weight:700; letter-spacing:0.2px; }
        .tabs-nav a.active { background:#fff; }
        .tab-pane { background:#fff; border:1px solid #e9ecef; border-radius:0 10px 10px 10px; padding:18px; box-shadow:0 12px 36px rgba(0,0,0,0.06); }
        .help-text { color:#6c757d; font-size:0.85rem; }
    </style>
</head>
<body>
<?php include 'header.php'; ?>
<main>
    <div class="page-header">
        <div>
            <div class="breadcrumb" style="margin-bottom:8px;">
                <a href="warranty.php">Warranty Claims</a>
                <span class="separator">&raquo;</span>
                <span>Ticket #<?php echo htmlspecialchars($claimId); ?></span>
            </div>
            <h1 style="margin:0 0 6px 0;">Ticket #<?php echo htmlspecialchars($claimId); ?> · <?php echo htmlspecialchars($claim['project_name']); ?></h1>
            <div class="d-flex flex-wrap" style="gap:8px;">
                <span class="badge-chip badge-issue">Issue: <?php echo htmlspecialchars(str_replace('_',' ', (string)$claim['issue_type'])); ?></span>
                <span class="status-badge" style="background:#E8F4F6;color:#2C3E50;">Status: <?php echo htmlspecialchars($claim['status']); ?></span>
                <span class="badge-chip badge-ticket">Opened: <?php echo htmlspecialchars($claim['created_at']); ?></span>
                <?php if ($claim['credit_amount'] !== null): ?>
                    <span class="badge-chip" style="background:#d4edda;color:#0f5132;">Credit: $<?php echo number_format((float)$claim['credit_amount'], 2); ?></span>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Progress bar -->
    <div class="card" style="margin: 0 20px 20px;">
        <div class="card-body">
            <div class="progress">
                <?php $path = warrantyStatusPath(); $idx = array_search($claim['status'], $path, true); foreach ($path as $i=>$label): ?>
                    <div class="step <?php echo ($idx!==false && $i <= $idx)?'active':''; ?>" title="<?php echo htmlspecialchars($label); ?>"></div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <div class="layout">
        <!-- Left: Public timeline + public notes composer for admin -->
        <div class="card">
            <div class="card-header">Public Timeline</div>
            <div class="card-body">
                <?php if ($isAdmin): ?>
                <form method="post" action="post_warranty_public_note.php" class="mb-3">
                    <input type="hidden" name="claim_id" value="<?php echo (int)$claimId; ?>">
                    <label for="public_notes" class="form-label">Post Public Update</label>
                    <textarea id="public_notes" name="public_notes" class="form-control" rows="3" placeholder="Share a clear update that customers will see..."></textarea>
                    <div class="d-flex justify-content-end mt-2">
                        <button type="submit" class="action-btn action-btn-primary">
                            <i class="fas fa-paper-plane"></i>Post Update
                        </button>
                    </div>
                </form>
                <hr/>
                <?php endif; ?>
                <ul class="timeline">
                    <?php if (empty($eventsPublic)): ?>
                        <li class="help-text">No public updates yet.</li>
                    <?php else: foreach ($eventsPublic as $ev): ?>
                        <li class="timeline-item">
                            <div class="timeline-time"><?php echo htmlspecialchars($ev['event_ts']); ?></div>
                            <div><?php echo htmlspecialchars($ev['event_text']); ?></div>
                        </li>
                    <?php endforeach; endif; ?>
                </ul>
            </div>
        </div>

        <!-- Right: Attachments -->
        <div class="card">
            <div class="card-header">Attachments</div>
            <div class="card-body">
                <?php if (!empty($proofPrimary)): ?>
                    <div class="mb-2"><strong>Primary Proof:</strong> <a href="<?php echo htmlspecialchars($proofPrimary); ?>" target="_blank">View Proof</a></div>
                <?php endif; ?>
                <?php if (empty($pictures)): ?>
                    <div class="help-text">No attachments uploaded yet.</div>
                <?php else: ?>
                    <div class="gallery">
                        <?php foreach ($pictures as $p): $isImg = preg_match('/\.(png|jpe?g|webp)$/i', $p); ?>
                            <a href="<?php echo htmlspecialchars($p); ?>" target="_blank">
                                <?php if ($isImg): ?><img src="<?php echo htmlspecialchars($p); ?>" alt="Attachment"><?php else: ?>
                                    <div style="padding:20px; text-align:center;">📄 <?php echo htmlspecialchars(basename($p)); ?></div>
                                <?php endif; ?>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php if ($isAdmin): ?>
    <!-- Admin Panel -->
    <div class="card admin-panel" style="margin: 0 20px 20px;">
        <div class="card-header">Admin Controls</div>
        <div class="card-body">
            <form method="post" action="process_warranty_update.php" enctype="multipart/form-data">
                <input type="hidden" name="claim_id" value="<?php echo (int)$claimId; ?>">
                <div class="admin-form-row">
                    <div class="admin-form-group">
                        <label class="form-label">Status</label>
                        <select name="status" class="form-select">
                            <?php 
                                $path = warrantyStatusPath();
                                $uiStatuses = [];
                                foreach ($path as $st) {
                                    if (strpos($st, 'Approved - ') === 0) {
                                        if (!in_array('Approved', $uiStatuses, true)) $uiStatuses[] = 'Approved';
                                    } else {
                                        $uiStatuses[] = $st;
                                    }
                                }
                                $currentUi = (strpos((string)$claim['status'], 'Approved - ') === 0) ? 'Approved' : (string)$claim['status'];
                                foreach ($uiStatuses as $st): ?>
                                    <option value="<?php echo $st; ?>" <?php echo ($currentUi===$st)?'selected':''; ?>><?php echo $st; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="admin-form-group">
                        <label class="form-label">Responsible Party</label>
                        <select name="responsible_party" class="form-select">
                            <?php foreach (['Manufacturer','EPC','Carrier','Other'] as $rp): ?>
                                <option value="<?php echo $rp; ?>" <?php echo ($claim['responsible_party']===$rp)?'selected':''; ?>><?php echo $rp; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="admin-form-row">
                    <div class="admin-form-group">
                        <label class="form-label">Resolution Type</label>
                        <select name="resolution_type" class="form-select">
                            <option value="">—</option>
                            <?php foreach (['Credit','Replacement','No-charge','Monitoring'] as $rt): ?>
                                <option value="<?php echo $rt; ?>" <?php echo ($claim['resolution_type']===$rt)?'selected':''; ?>><?php echo $rt; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="admin-form-group">
                        <label class="form-label">Credit Amount</label>
                        <input type="number" step="0.01" class="form-control" name="credit_amount" value="<?php echo htmlspecialchars((string)$claim['credit_amount']); ?>" placeholder="0.00">
                    </div>
                </div>

                <div class="admin-form-row full replacement-only">
                    <div class="admin-form-group">
                        <label class="form-label">Replacement Tracking</label>
                        <input type="text" class="form-control" name="replacement_tracking" value="<?php echo htmlspecialchars((string)$claim['replacement_tracking']); ?>" placeholder="Tracking # or reference">
                        <div class="form-hint">When first set, status can auto-advance to Replacement Shipped.</div>
                    </div>
                </div>

                <div class="admin-form-row full">
                    <div class="admin-form-group">
                        <label class="form-label">Proof of Completion</label>
                        <input type="file" name="proof_files[]" multiple class="form-control" accept=".pdf,.png,.jpg,.jpeg,.webp">
                        <div class="form-hint">Upload multiple files: photos, signed memos, etc.</div>
                    </div>
                </div>

                <div class="admin-form-row full">
                    <div class="admin-form-group">
                        <label class="form-label">Internal Notes</label>
                        <textarea class="form-control" name="internal_notes" rows="3" placeholder="Only visible to admins."><?php echo htmlspecialchars((string)$claim['internal_notes']); ?></textarea>
                    </div>
                </div>

                <div class="admin-form-row full replacement-only">
                    <div class="admin-form-group">
                        <label class="form-label">Replacement Pallets</label>
                        <a class="btn btn-secondary" href="link_replacement_pallets.php?claim_id=<?php echo (int)$claimId; ?>">Link replacement pallet(s)</a>
                        <div class="form-hint">Currently linked: <?php echo (int)count($linkedIds); ?> pallet(s).</div>
                        <?php if (!empty($linkedIds)): ?>
                            <ul class="mt-2">
                                <?php foreach ($linkedIds as $pid): ?>
                                    <li><?php echo htmlspecialchars($linkedMap[$pid] ?? ('ID '.$pid)); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                        <div class="form-check mt-3">
                            <input class="form-check-input" type="checkbox" name="override_cross_project" id="override_cross_project" value="1">
                            <label class="form-check-label" for="override_cross_project">Allow cross-project pallets (records override event)</label>
                        </div>
                    </div>
                </div>
                <div class="d-flex justify-content-between align-items-center mt-4 pt-3 border-top">
                    <div class="text-muted small">
                        <i class="fas fa-info-circle me-1"></i>
                        Changes are logged automatically and customers are notified of public updates.
                    </div>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save me-2"></i>Save Changes
                    </button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <!-- Tabs: Pallets & Audit Log -->
    <div class="tabs">
        <div class="tabs-nav">
            <a href="#tab-pallets" class="active" onclick="showTab(event,'tab-pallets')">Pallets</a>
            <a href="#tab-audit" onclick="showTab(event,'tab-audit')">Audit Log</a>
        </div>
        <div id="tab-pallets" class="tab-pane">
            <?php if (empty($linkedIds)): ?>
                <div class="help-text">No replacement pallets linked.</div>
            <?php else: ?>
                <ul>
                    <?php foreach ($linkedIds as $pid): ?>
                        <li><?php echo htmlspecialchars($linkedMap[$pid] ?? ('ID '.$pid)); ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
        <div id="tab-audit" class="tab-pane" style="display:none;">
            <?php if (!$isAdmin): ?>
                <div class="help-text">Admins only.</div>
            <?php else: ?>
                <?php if (empty($eventsAll)): ?>
                    <div class="help-text">No events yet.</div>
                <?php else: ?>
                    <ul class="timeline">
                        <?php foreach ($eventsAll as $ev): ?>
                            <li class="timeline-item">
                                <div class="timeline-time"><?php echo htmlspecialchars($ev['event_ts']); ?> · <?php echo $ev['is_public'] ? 'Public' : 'Internal'; ?></div>
                                <div><?php echo htmlspecialchars($ev['event_text']); ?></div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</main>

<script>
function showTab(e, id){
  e.preventDefault();
  document.querySelectorAll('.tabs-nav a').forEach(a=>a.classList.remove('active'));
  e.target.classList.add('active');
  document.querySelectorAll('.tab-pane').forEach(p=>p.style.display='none');
  document.getElementById(id).style.display='block';
}

// Toggle replacement-only sections
function toggleReplacementSections(){
  const sel = document.querySelector('select[name="resolution_type"]');
  if (!sel) return;
  const show = sel.value === 'Replacement';
  document.querySelectorAll('.replacement-only').forEach(el => {
    el.style.display = show ? '' : 'none';
  });
}
document.addEventListener('DOMContentLoaded', () => {
  const sel = document.querySelector('select[name="resolution_type"]');
  if (sel) sel.addEventListener('change', toggleReplacementSections);
  toggleReplacementSections();
});
</script>
</body>
</html>


