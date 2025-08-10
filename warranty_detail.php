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
        .btn-primary { background: linear-gradient(135deg, #488C9A, #3A6E7F); border:none; border-radius:12px; padding:12px 20px; box-shadow:0 10px 24px rgba(58,110,127,0.25); font-weight:700; color:#fff !important; cursor:pointer; display:inline-flex; align-items:center; gap:8px; }
        .btn-primary:hover { filter:brightness(0.96); transform: translateY(-1px); box-shadow:0 14px 30px rgba(58,110,127,0.30); color:#fff !important; }
        .btn-secondary { background:#f8f9fa; border:1px solid #e9ecef; border-radius:12px; padding:10px 16px; font-weight:600; color:#495057; cursor:pointer; display:inline-flex; align-items:center; gap:8px; }
        .btn-secondary:hover { background:#e9ecef; border-color:#dee2e6; }
        .btn-primary .icon, .btn-secondary .icon { width:14px; height:14px; }
        .action-btn { display:inline-flex; align-items:center; gap:8px; padding:8px 12px; border-radius:8px; border:none; font-size:0.9rem; font-weight:600; cursor:pointer; transition:all 0.15s ease; }
        .action-btn-primary { background:linear-gradient(135deg,#488C9A,#3A6E7F); color:#fff; }
        .action-btn-primary:hover { filter:brightness(1.05); transform:translateY(-1px); }
        .action-btn-secondary { background:#fff; border:1px solid #e1e6ea; color:#495057; }
        .action-btn-secondary:hover { background:#f8f9fa; border-color:#dee2e6; }
        .replacement-only { display:none; }

        /* Upload card like dashboard Add Project */
        .upload-card { display:flex; align-items:center; justify-content:center; flex-direction:column; border:2px dashed #d0d0d0; background:#f9f9f9; color:#6c757d; border-radius:12px; padding:22px; height:75px; cursor:pointer; transition:all .2s ease; }
        .upload-card:hover { border-color:#488C9A; background:#f0f8fa; color:#488C9A; box-shadow:0 8px 24px rgba(72,140,154,0.2); }
        .upload-card .icon { font-size:28px; line-height:1; margin-bottom:6px; }
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

    <!-- Progress bar with labels -->
    <div class="card" style="margin: 0 20px 20px;">
        <div class="card-body">
            <?php 
                $uiPath = warrantyUiPathAdvanced((string)($claim['responsible_party'] ?? 'Manufacturer'), (string)$claim['status'], (string)($claim['resolution_type'] ?? ''));
                $activeIdx = uiIndexForStatus((string)$claim['status'], (string)($claim['responsible_party'] ?? ''), (string)($claim['resolution_type'] ?? ''));
            ?>
            <div class="progress">
                <?php foreach ($uiPath as $i=>$label): ?>
                    <div class="step <?php echo ($i <= $activeIdx)?'active':''; ?>" title="<?php echo htmlspecialchars($label); ?>"></div>
                <?php endforeach; ?>
            </div>
            <div class="step-labels" style="display:grid; grid-template-columns: repeat(<?php echo count($uiPath); ?>, 1fr); gap: 10px; margin-top:8px;">
                <?php foreach ($uiPath as $i=>$label): ?>
                    <div style="text-align:center; font-size:12px; color: <?php echo ($i <= $activeIdx)?'#2C3E50':'#6c757d'; ?>; font-weight:<?php echo ($i <= $activeIdx)?'700':'600'; ?>;">
                        <?php echo htmlspecialchars($label); ?>
                    </div>
                <?php endforeach; ?>
            </div>
            <?php if ((string)$claim['status'] === 'Rejected'): ?>
                <?php 
                    // Try to find the most recent rejection reason from events
                    $rejReason = '';
                    foreach ($eventsAll as $ev) { if (stripos($ev['event_text'] ?? '', 'Rejected:') === 0) { $rejReason = trim(substr($ev['event_text'], 9)); break; } }
                ?>
                <?php if ($rejReason !== ''): ?>
                    <div style="margin-top:8px; text-align:center; color:#842029; font-weight:600;">Reason: <?php echo htmlspecialchars($rejReason); ?></div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

    <div class="layout">
        <!-- Left: Public timeline -->
        <div class="card">
            <div class="card-header">Public Timeline</div>
            <div class="card-body">
                <?php /* Collapsible view: show latest, expand for history */ ?>
                <?php $latest = empty($eventsPublic) ? null : $eventsPublic[0]; ?>
                <?php if (!$latest): ?>
                    <div class="help-text">No public updates yet.</div>
                <?php else: ?>
                    <div class="timeline-item" style="margin-bottom:8px;">
                        <div class="timeline-time"><?php echo htmlspecialchars($latest['event_ts']); ?> · Latest</div>
                        <div><?php echo htmlspecialchars($latest['event_text']); ?></div>
                    </div>
                    <?php if (count($eventsPublic) > 1): ?>
                        <details>
                            <summary style="cursor:pointer; font-weight:600; color:#293E4C;">Show previous updates</summary>
                            <ul class="timeline" style="margin-top:10px;">
                                <?php foreach (array_slice($eventsPublic, 1) as $ev): ?>
                                    <li class="timeline-item">
                                        <div class="timeline-time"><?php echo htmlspecialchars($ev['event_ts']); ?></div>
                                        <div><?php echo htmlspecialchars($ev['event_text']); ?></div>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </details>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Right: Attachments -->
        <div class="card">
            <div class="card-header">Attachments</div>
            <div class="card-body">
                <?php if ($isAdmin): ?>
                <form method="post" action="process_warranty_update.php" enctype="multipart/form-data">
                    <input type="hidden" name="claim_id" value="<?php echo (int)$claimId; ?>">
                    <?php if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); } ?>
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                <?php endif; ?>

                <?php if (!empty($proofPrimary)): ?>
                    <div class="mb-2"><strong>Primary Proof:</strong> <a href="<?php echo htmlspecialchars($proofPrimary); ?>" target="_blank">View Proof</a></div>
                <?php endif; ?>
                <?php 
                    $totalPics = count($pictures);
                    $picsToShow = array_slice($pictures, 0, 2);
                    $hasMorePics = $totalPics > 2;
                    $galleryId = 'all_pics_' . (int)$claimId;
                    $viewAllId = 'view_all_' . (int)$claimId;
                ?>
                <?php if (empty($pictures)): ?>
                    <div class="help-text">No attachments uploaded yet.</div>
                <?php else: ?>
                    <div class="gallery">
                        <?php foreach ($picsToShow as $p): $isImg = preg_match('/\.(png|jpe?g|webp)$/i', $p); ?>
                            <div>
                                <a href="<?php echo htmlspecialchars($p); ?>" target="_blank">
                                    <?php if ($isImg): ?><img src="<?php echo htmlspecialchars($p); ?>" alt="Attachment"><?php else: ?>
                                        <div style="padding:20px; text-align:center;">📄 <?php echo htmlspecialchars(basename($p)); ?></div>
                                    <?php endif; ?>
                                </a>
                                <?php if ($isAdmin): ?>
                                    <div class="mt-1">
                                        <label class="form-check-label small"><input type="checkbox" class="form-check-input" name="delete_pictures[]" value="<?php echo htmlspecialchars($p); ?>"> Delete</label>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                        <?php if ($isAdmin): ?>
                        <label for="upload_docs" class="upload-card" style="min-width:140px;">
                            <div class="icon">＋</div>
                            <div style="font-weight:600;">Add Documents</div>
                            <div style="font-size:12px; color:#6c757d;">PDF, PNG, JPG, WEBP</div>
                        </label>
                        <?php endif; ?>
                    </div>
                    <?php if ($hasMorePics): ?>
                        <div class="mt-2">
                            <a href="#" id="<?php echo $viewAllId; ?>" class="action-btn-secondary" style="padding:6px 10px; border-radius:8px; border:1px solid #e1e6ea; text-decoration:none;">View All (<?php echo (int)$totalPics; ?>)</a>
                        </div>
                        <div id="<?php echo $galleryId; ?>" class="gallery" style="display:none; margin-top:10px;">
                            <?php foreach ($pictures as $p): $isImg = preg_match('/\.(png|jpe?g|webp)$/i', $p); ?>
                                <div>
                                    <a href="<?php echo htmlspecialchars($p); ?>" target="_blank">
                                        <?php if ($isImg): ?><img src="<?php echo htmlspecialchars($p); ?>" alt="Attachment"><?php else: ?>
                                            <div style="padding:20px; text-align:center;">📄 <?php echo htmlspecialchars(basename($p)); ?></div>
                                        <?php endif; ?>
                                    </a>
                                    <?php if ($isAdmin): ?>
                                        <div class="mt-1">
                                            <label class="form-check-label small"><input type="checkbox" class="form-check-input" name="delete_pictures[]" value="<?php echo htmlspecialchars($p); ?>"> Delete</label>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
                <?php if ($isAdmin): ?>
                    <div class="mt-3">
                        <!-- Hidden input bound to the Add Documents tile above -->
                        <input id="upload_docs" type="file" name="proof_files[]" multiple accept=".pdf,.png,.jpg,.jpeg,.webp" style="display:none;">
                        <div class="d-flex justify-content-end mt-2">
                            <button type="submit" class="btn btn-primary"><i class="fas fa-save me-2"></i>Update Attachments</button>
                        </div>
                    </div>
                </form>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php if ($isAdmin): ?>
    <form method="post" action="process_warranty_update.php" enctype="multipart/form-data">
        <input type="hidden" name="claim_id" value="<?php echo (int)$claimId; ?>">
        <?php if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); } ?>
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">

        <div class="card admin-panel" style="margin: 0 20px 20px;">
            <div class="card-header">Status Actions</div>
            <div class="card-body">
                <div class="admin-form-row">
                    <div class="admin-form-group">
                        <label class="form-label">Current Status</label>
                        <div class="form-control" style="background:#f8f9fa;">
                            <?php echo htmlspecialchars((string)$claim['status']); ?>
                        </div>
                        <input type="hidden" name="status" id="status_hidden" value="<?php echo htmlspecialchars((string)$claim['status']); ?>">
                    </div>
                    <div class="admin-form-group">
                        <label class="form-label">Next Step</label>
                        <div id="next_step_area">
                            <?php $from = (string)$claim['status']; $resp = (string)($claim['responsible_party'] ?? 'Manufacturer'); ?>
                            <?php if ($from === 'Submitted' || $from === 'Draft'): ?>
                                <button type="button" class="btn-primary" id="btn_to_in_review"><svg class="icon" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M5 12h12l-4-4 1.4-1.4L21.8 12l-7.4 5.4L13 16l4-4H5z"/></svg><span>Move to In Review</span></button>
                            <?php elseif ($from === 'In Review'): ?>
                                <button type="button" class="btn-primary" id="btn_to_pending"><svg class="icon" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M5 12h12l-4-4 1.4-1.4L21.8 12l-7.4 5.4L13 16l4-4H5z"/></svg><span>Move to Pending <?php echo htmlspecialchars($resp); ?></span></button>
                                <div class="form-hint">Change Responsible Party below to alter pending label.</div>
                            <?php elseif (strpos($from, 'Pending ') === 0): ?>
                                <div class="form-check" style="margin-bottom:8px;">
                                    <input class="form-check-input" type="radio" name="decision" id="dec_approve" value="approve">
                                    <label class="form-check-label" for="dec_approve">Approve</label>
                                </div>
                                <div class="form-check" style="margin-bottom:8px;">
                                    <input class="form-check-input" type="radio" name="decision" id="dec_reject" value="reject">
                                    <label class="form-check-label" for="dec_reject">Reject</label>
                                </div>
                                <div id="decision_approve_fields" style="display:none; padding:8px 0;">
                                    <div class="admin-form-row">
                                        <div class="admin-form-group">
                                            <label class="form-label">Resolution Type</label>
                                            <select name="resolution_type" class="form-select" id="resolution_type">
                                                <option value="">—</option>
                                                <?php foreach (['Credit','Replacement','No-charge','Monitoring'] as $rt): ?>
                                                    <option value="<?php echo $rt; ?>" <?php echo ($claim['resolution_type']===$rt)?'selected':''; ?>><?php echo $rt; ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="admin-form-group credit-only" style="display:none;">
                                            <label class="form-label">Credit Amount</label>
                                            <input type="number" step="0.01" class="form-control" name="credit_amount" value="<?php echo htmlspecialchars((string)$claim['credit_amount']); ?>" placeholder="0.00">
                                        </div>
                                    </div>

                                    <div class="admin-form-row full replacement-only">
                                        <div class="admin-form-group">
                                            <label class="form-label">Replacement Tracking</label>
                                            <input type="text" class="form-control" name="replacement_tracking" value="<?php echo htmlspecialchars((string)$claim['replacement_tracking']); ?>" placeholder="Tracking # or reference">
                                            <div class="form-hint">Optional now; will be required when marking as Replacement Shipped.</div>
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
                                            <div class="form-check mt-2">
                                                <input class="form-check-input" type="checkbox" name="override_cross_project" id="override_cross_project_top" value="1">
                                                <label class="form-check-label" for="override_cross_project_top">Allow cross-project pallets (records override event)</label>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="admin-form-row full">
                                        <div class="admin-form-group">
                                            <label class="form-label">Proof of Completion</label>
                                            <label for="proof_upload" class="upload-card">
                                                <div class="icon">＋</div>
                                                <div style="font-weight:600;">Upload Proof of Completion</div>
                                                <div style="font-size:12px; color:#6c757d;">PDF, PNG, JPG, WEBP</div>
                                            </label>
                                            <input id="proof_upload" type="file" name="proof_files[]" accept=".pdf,.png,.jpg,.jpeg,.webp" style="display:none;">
                                            <div class="form-hint">Closing requires a proof file.</div>
                                        </div>
                                    </div>
                                </div>
                                <div id="decision_reject_fields" style="display:none; padding:8px 0;">
                                    <label class="form-label">Rejection Reason</label>
                                    <textarea class="form-control" name="rejection_reason" rows="3" placeholder="Provide a clear reason for rejection."></textarea>
                                </div>
                                <button type="button" class="btn-primary" id="btn_apply_decision"><svg class="icon" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M5 12h12l-4-4 1.4-1.4L21.8 12l-7.4 5.4L13 16l4-4H5z"/></svg><span>Apply Decision</span></button>
                            <?php elseif ($from === 'Approved - Replacement'): ?>
                                <button type="button" class="btn-primary" id="btn_to_shipped"><svg class="icon" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M5 12h12l-4-4 1.4-1.4L21.8 12l-7.4 5.4L13 16l4-4H5z"/></svg><span>Mark Replacement Shipped</span></button>
                            <?php elseif ($from === 'Replacement Shipped' || $from === 'Approved - Credit' || $from === 'Rejected'): ?>
                                <button type="button" class="btn-primary" id="btn_to_closed"><svg class="icon" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M5 12h12l-4-4 1.4-1.4L21.8 12l-7.4 5.4L13 16l4-4H5z"/></svg><span>Close</span></button>
                            <?php else: ?>
                                <div class="form-hint">No further steps available.</div>
                            <?php endif; ?>
                        </div>
                        <div id="next_step_help" class="form-hint" style="margin-top:8px;"></div>
                    </div>
                </div>

                <?php $from = (string)$claim['status']; if (strpos($from, 'Approved - ') === 0): ?>
                <div class="admin-form-row" style="margin-top:6px;">
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
                <?php endif; ?>

                <div class="admin-form-row full rejection-only" style="display:none; margin-top:6px;">
                    <div class="admin-form-group">
                        <label class="form-label">Rejection Reason</label>
                        <textarea class="form-control" name="rejection_reason" rows="3" placeholder="Provide a clear reason for rejection."></textarea>
                    </div>
                </div>
            </div>
        </div>

        <div class="card admin-panel" style="margin: 0 20px 20px;">
            <div class="card-header">General Updates</div>
            <div class="card-body">
                <div class="admin-form-row full">
                    <div class="admin-form-group">
                        <label class="form-label">Responsible Party</label>
                        <select name="responsible_party" class="form-select" id="responsible_party_select">
                            <?php foreach (['Manufacturer','EPC','Carrier','Other'] as $rp): ?>
                                <option value="<?php echo $rp; ?>" <?php echo ($claim['responsible_party']===$rp)?'selected':''; ?>><?php echo $rp; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="admin-form-row">
                    <div class="admin-form-group">
                        <label class="form-label">Internal Notes</label>
                        <textarea class="form-control" name="internal_notes" rows="3" placeholder="Only visible to admins."><?php echo htmlspecialchars((string)$claim['internal_notes']); ?></textarea>
                    </div>
                    <div class="admin-form-group">
                        <label class="form-label">Post Public Update</label>
                        <textarea class="form-control" name="public_notes" rows="3" placeholder="Share a clear update that customers will see..."></textarea>
                    </div>
                </div>

                <div class="d-flex justify-content-between align-items-center mt-4 pt-3 border-top">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save me-2"></i>Save changes
                    </button>
                </div>
            </div>
        </div>
    </form>
    <?php endif; ?>

    <!-- Tabs: Pallets & (Admin-only) Audit Log -->
    <div class="tabs">
        <div class="tabs-nav">
            <a href="#tab-pallets" class="active" onclick="showTab(event,'tab-pallets')">Pallets</a>
            <?php if ($isAdmin): ?>
                <a href="#tab-audit" onclick="showTab(event,'tab-audit')">Audit Log</a>
            <?php endif; ?>
        </div>
        <div id="tab-pallets" class="tab-pane">
            <?php 
                $palletDetails = [];
                $decodedNotes = json_decode((string)($claim['notes'] ?? ''), true);
                if (is_array($decodedNotes) && isset($decodedNotes['pallets']) && is_array($decodedNotes['pallets'])) {
                    $palletDetails = $decodedNotes['pallets'];
                }
            ?>
            <?php if (!empty($palletDetails)): ?>
                <div class="table-responsive">
                    <table class="table" style="width:100%; border-collapse:collapse;">
                        <thead>
                            <tr>
                                <th style="text-align:left;">Pallet</th>
                                <th style="text-align:right;">Expected</th>
                                <th style="text-align:right;">Actual</th>
                                <th style="text-align:right;">Damaged</th>
                                <th style="text-align:right;">Accepted</th>
                                <th style="text-align:left;">Notes</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($palletDetails as $pd): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars('ID ' . (int)($pd['pallet_id'] ?? 0)); ?></td>
                                    <td style="text-align:right;">&times;<?php echo (int)($pd['expected'] ?? 0); ?></td>
                                    <td style="text-align:right;">&times;<?php echo (int)($pd['actual'] ?? 0); ?></td>
                                    <td style="text-align:right;">&times;<?php echo (int)($pd['damaged'] ?? 0); ?></td>
                                    <td style="text-align:right;">&times;<?php echo (int)($pd['accepted'] ?? 0); ?></td>
                                    <td><?php echo htmlspecialchars((string)($pd['notes'] ?? '')); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="help-text" style="margin-top:8px;">Details reflect pallet-level data provided at delivery reporting.</div>
                <hr/>
            <?php endif; ?>

            <h4>Replacement Pallets</h4>
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
        <?php if ($isAdmin): ?>
            <div id="tab-audit" class="tab-pane" style="display:none;">
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
            </div>
        <?php endif; ?>
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
    // Ensure we override the CSS rule `.replacement-only { display:none; }`
    // Use grid to match `.admin-form-row` layout
    el.style.display = show ? 'grid' : 'none';
  });
}
document.addEventListener('DOMContentLoaded', () => {
  const sel = document.getElementById('resolution_type');
  if (sel) sel.addEventListener('change', toggleReplacementSections);
  toggleReplacementSections();

  const statusHidden = document.getElementById('status_hidden');
  const respSel = document.getElementById('responsible_party_select');

  const btnInReview = document.getElementById('btn_to_in_review');
  if (btnInReview) btnInReview.addEventListener('click', () => { statusHidden.value = 'In Review'; btnInReview.closest('form').submit(); });

  const btnToPending = document.getElementById('btn_to_pending');
  if (btnToPending) btnToPending.addEventListener('click', () => {
    const resp = (respSel && respSel.value) ? respSel.value : 'Manufacturer';
    const target = 'Pending ' + resp;
    statusHidden.value = target;
    btnToPending.closest('form').submit();
  });

  const decApprove = document.getElementById('dec_approve');
  const decReject = document.getElementById('dec_reject');
  const approveFields = document.getElementById('decision_approve_fields');
  const rejectFields = document.getElementById('decision_reject_fields');
  const resSelect = document.getElementById('resolution_type');
  const creditOnly = document.querySelector('.credit-only');
  const nextHelp = document.getElementById('next_step_help');
  const currentStatus = (statusHidden && statusHidden.value) ? statusHidden.value : '';
  function updateNextStepHelp(){
    let msg = '';
    if (currentStatus === 'Submitted' || currentStatus === 'Draft') {
      msg = 'Next step moves the ticket to In Review. You can add internal notes or attachments anytime.';
    } else if (currentStatus === 'In Review') {
      const resp = (respSel && respSel.value) ? respSel.value : 'Manufacturer';
      msg = `Next step sets status to Pending ${resp}. Ensure Responsible Party is correct.`;
    } else if (currentStatus === 'Pending Manufacturer' || currentStatus === 'Pending EPC' || currentStatus === 'Pending Carrier') {
      msg = 'Choose Approve or Reject. Approve requires a Resolution Type. If Credit, enter Credit Amount. For Replacement, link pallet(s) now or before shipping; tracking required at shipping/close. Reject requires a reason.';
    } else if (currentStatus === 'Approved - Replacement') {
      msg = 'Enter replacement tracking when available, then mark Replacement Shipped. At least one replacement pallet must be linked.';
    } else if (currentStatus === 'Approved - Credit') {
      msg = 'When ready, Close the ticket. Closing requires a proof of completion file.';
    } else if (currentStatus === 'Replacement Shipped' || currentStatus === 'Rejected') {
      msg = 'Final step is Close. Closing requires a proof of completion file.';
    } else if (currentStatus === 'Closed') {
      msg = 'Ticket is closed. You can still add internal notes or attachments if needed.';
    }
    if (nextHelp) nextHelp.textContent = msg;
  }
  function syncDecisionUi(){
    if (decApprove && decApprove.checked) {
      approveFields && (approveFields.style.display = '');
      rejectFields && (rejectFields.style.display = 'none');
    } else if (decReject && decReject.checked) {
      approveFields && (approveFields.style.display = 'none');
      rejectFields && (rejectFields.style.display = '');
    }
    if (creditOnly && resSelect) {
      creditOnly.style.display = (resSelect.value === 'Credit') ? '' : 'none';
    }
  }
  if (decApprove) decApprove.addEventListener('change', syncDecisionUi);
  if (decReject) decReject.addEventListener('change', syncDecisionUi);
  if (resSelect) resSelect.addEventListener('change', syncDecisionUi);
  syncDecisionUi();
  updateNextStepHelp();

  const btnApplyDecision = document.getElementById('btn_apply_decision');
  if (btnApplyDecision) btnApplyDecision.addEventListener('click', () => {
    if (decApprove && decApprove.checked) {
      statusHidden.value = 'Approved';
    } else if (decReject && decReject.checked) {
      statusHidden.value = 'Rejected';
    } else {
      alert('Select Approve or Reject.');
      return;
    }
    btnApplyDecision.closest('form').submit();
  });

  const btnToShipped = document.getElementById('btn_to_shipped');
  if (btnToShipped) btnToShipped.addEventListener('click', () => { statusHidden.value = 'Replacement Shipped'; btnToShipped.closest('form').submit(); });

  const btnToClosed = document.getElementById('btn_to_closed');
  if (btnToClosed) btnToClosed.addEventListener('click', () => { statusHidden.value = 'Closed'; btnToClosed.closest('form').submit(); });

  // View All attachments
  const viewAll = document.querySelector('[id^="view_all_"]');
  if (viewAll) {
    viewAll.addEventListener('click', (e) => {
      e.preventDefault();
      const id = viewAll.id.replace('view_all_', 'all_pics_');
      const g = document.getElementById(id);
      if (g) { g.style.display = (g.style.display === 'none' || g.style.display === '') ? 'grid' : 'none'; }
    });
  }
});
</script>
</body>
</html>


