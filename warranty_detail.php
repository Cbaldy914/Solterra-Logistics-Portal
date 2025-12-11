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
$notesArr = jsonToArray($claim['notes'] ?? '');
$replacementPlan = isset($notesArr['replacement_plan']) && is_array($notesArr['replacement_plan']) ? $notesArr['replacement_plan'] : [];
$replacementPlanItems = (isset($replacementPlan['items']) && is_array($replacementPlan['items'])) ? $replacementPlan['items'] : [];
$hasReplacementPlan = !empty($replacementPlanItems);

// Replacement pallets linked
$linkedIds = listLinkedReplacementPalletIds($conn, $claimId);
$linkedMap = getPalletIdentifiers($conn, $linkedIds);

// Replacement shipping status totals for linked pallets
$replacementStatusTotals = [];
if (!empty($linkedIds)) {
    $place = implode(',', array_fill(0, count($linkedIds), '?'));
    $types = str_repeat('i', count($linkedIds));
    $stmtShip = $conn->prepare("SELECT status, COUNT(*) AS c, SUM(quantity) AS m FROM inventory_pallets WHERE id IN ($place) GROUP BY status");
    if ($stmtShip) {
        $stmtShip->bind_param($types, ...$linkedIds);
        $stmtShip->execute();
        $rsShip = $stmtShip->get_result();
        while ($row = $rsShip->fetch_assoc()) {
            $key = (string)$row['status'];
            $replacementStatusTotals[$key] = [
                'pallets' => (int)($row['c'] ?? 0),
                'modules' => (int)($row['m'] ?? 0),
            ];
        }
        $stmtShip->close();
    }
}

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
    <title>Exception Ticket #<?php echo htmlspecialchars($claimId); ?></title>
    <link rel="stylesheet" href="portal.css">
    <link rel="icon" href="pictures/favicon.png" type="image/x-icon">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        /* Breadcrumbs */
        .breadcrumb { display:flex; margin-bottom:20px; }
        .breadcrumb a { color: #488C9A; text-decoration: none; }
        .breadcrumb .separator { margin: 0 8px; color: #6c757d; }

        /* Modern Page Header */
        .page-header {
            background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%);
            border-radius: 24px;
            padding: 32px;
            margin: 12px 20px 24px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.06);
            border: 1px solid rgba(72, 140, 154, 0.08);
            position: relative;
            overflow: hidden;
        }
        .page-header::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, #488C9A 0%, #293E4C 100%);
        }
        .page-header h1 {
            font-size: 2.2em;
            font-weight: 700;
            background: linear-gradient(135deg, #293E4C 0%, #488C9A 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            margin: 0 0 16px 0;
            line-height: 1.2;
        }
        .badge-container { display:flex; flex-wrap:wrap; gap:10px; align-items:center; }
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

        /* Mini shipping statuses (replacement) */
        .ship-statuses { display:flex; flex-wrap:wrap; gap:12px; margin-top:10px; }
        .ship-box { background:#f8f9fa; border:1px solid #e1e6ea; border-radius:10px; padding:10px 12px; min-width:140px; text-align:center; }
        .ship-box .status-label { font-weight:700; color:#293E4C; font-size:0.9rem; }
        .ship-box .status-count { font-size:1.2rem; font-weight:800; color:#488C9A; }
        .ship-box .status-unit { color:#6c757d; font-size:0.8rem; }

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
        /* Required markers */
        .req::after { content:' *'; color:#b42318; font-weight:700; }
        /* Inline error box */
        .error-box { background:#fde2e1; color:#5f2120; border:1px solid #f3b8b5; border-radius:10px; padding:10px 12px; margin-bottom:10px; }

        /* Upload card like dashboard Add Project */
        .upload-card { display:flex; align-items:center; justify-content:center; flex-direction:column; border:2px dashed #d0d0d0; background:#f9f9f9; color:#6c757d; border-radius:12px; padding:22px; height:75px; cursor:pointer; transition:all .2s ease; }
        .upload-card:hover { border-color:#488C9A; background:#f0f8fa; color:#488C9A; box-shadow:0 8px 24px rgba(72,140,154,0.2); }
        .upload-card .icon { font-size:28px; line-height:1; margin-bottom:6px; }
        /* File preview pills */
        .file-pills { display:flex; flex-wrap:wrap; gap:6px; margin-top:6px; }
        .file-pill { background:#f4f7f9; border:1px solid #e2e9ef; color:#293E4C; padding:4px 8px; border-radius:999px; font-size:12px; }
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
    <?php
        require_once 'components/breadcrumbs.php';
        $projId = (int)$claim['project_id'];
        echo slp_render_breadcrumbs([
            'current_label' => 'Ticket #'.htmlspecialchars($claimId),
            'project_id' => $projId,
            'extra' => [ ['label' => 'Exceptions Report', 'url' => 'warranty.php?project_id='.$projId] ]
        ]);
    ?>
    <div class="page-header">
        <h1>Ticket #<?php echo htmlspecialchars($claimId); ?> · <?php echo htmlspecialchars($claim['project_name']); ?></h1>
        <div class="badge-container">
            <span class="badge-chip badge-issue">Issue: <?php echo htmlspecialchars(str_replace('_',' ', (string)$claim['issue_type'])); ?></span>
            <span class="status-badge" style="background:#E8F4F6;color:#2C3E50;">Status: <?php echo htmlspecialchars($claim['status']); ?></span>
            <span class="badge-chip badge-ticket">Opened: <?php echo htmlspecialchars($claim['created_at']); ?></span>
            <?php if ($claim['credit_amount'] !== null): ?>
                <span class="badge-chip" style="background:#d4edda;color:#0f5132;">Credit: $<?php echo number_format((float)$claim['credit_amount'], 2); ?></span>
            <?php endif; ?>
        </div>
    </div>

    <!-- Progress bar with labels -->
    <div class="card" style="margin: 0 20px 20px;">
        <div class="card-body">
            <?php 
                $uiPath = warrantyUiPathAdvanced((string)($claim['responsible_party'] ?? 'Manufacturer'), (string)$claim['status'], (string)($claim['resolution_type'] ?? ''), $replacementStatusTotals);
                $activeIdx = uiIndexForStatus((string)$claim['status'], (string)($claim['responsible_party'] ?? ''), (string)($claim['resolution_type'] ?? ''), $replacementStatusTotals);
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
                    
                    <!-- Enhanced Replacement Module Status -->
                    <?php if (!empty($linkedIds) && !empty($replacementStatusTotals)): ?>
                        <div class="replacement-status-container" style="margin-top:20px; padding:20px; background:linear-gradient(135deg, #f8fafc 0%, #ffffff 100%); border:1px solid #e6edf1; border-radius:16px; box-shadow:0 8px 24px rgba(0,0,0,0.04);">
                            <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:16px;">
                                <h6 style="margin:0; color:#293E4C; font-weight:700; font-size:1.1em; display:flex; align-items:center; gap:8px;">
                                    <span style="width:12px; height:12px; background:linear-gradient(135deg, #488C9A, #3A6E7F); border-radius:50%; display:inline-block;"></span>
                                    Replacement Module Status
                                </h6>
                                <div style="background:#e8f4f6; color:#2c3e50; padding:4px 12px; border-radius:20px; font-size:0.85em; font-weight:600;">
                                    <?php echo count($linkedIds); ?> Pallet<?php echo count($linkedIds) !== 1 ? 's' : ''; ?> Linked
                                </div>
                            </div>
                            <div class="replacement-shipping-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 12px;">
                                <?php 
                                    $shipping_statuses = ['At Manufacturer','On Water','Cleared Customs','In Transit to Warehouse','In Warehouse','In Transit to Project','Delivered to Project'];
                                    $status_colors = [
                                        'At Manufacturer' => ['bg' => '#fff3cd', 'border' => '#ffeaa7', 'text' => '#856404', 'accent' => '#f39c12'],
                                        'On Water' => ['bg' => '#d1ecf1', 'border' => '#bee5eb', 'text' => '#0c5460', 'accent' => '#17a2b8'],
                                        'Cleared Customs' => ['bg' => '#d4edda', 'border' => '#c3e6cb', 'text' => '#155724', 'accent' => '#28a745'],
                                        'In Transit to Warehouse' => ['bg' => '#e2e3e5', 'border' => '#d6d8db', 'text' => '#383d41', 'accent' => '#6c757d'],
                                        'In Warehouse' => ['bg' => '#f8d7da', 'border' => '#f1b0b7', 'text' => '#721c24', 'accent' => '#dc3545'],
                                        'In Transit to Project' => ['bg' => '#d1ecf1', 'border' => '#bee5eb', 'text' => '#0c5460', 'accent' => '#17a2b8'],
                                        'Delivered to Project' => ['bg' => '#d4edda', 'border' => '#c3e6cb', 'text' => '#155724', 'accent' => '#28a745']
                                    ];
                                    foreach ($shipping_statuses as $status):
                                        $count = $replacementStatusTotals[$status]['pallets'] ?? 0;
                                        $modules = $replacementStatusTotals[$status]['modules'] ?? 0;
                                        if ($count <= 0) continue;
                                        $colors = $status_colors[$status] ?? ['bg' => '#f8f9fa', 'border' => '#e9ecef', 'text' => '#495057', 'accent' => '#6c757d'];
                                ?>
                                    <div class="replacement-status-card" style="background:<?php echo $colors['bg']; ?>; border:2px solid <?php echo $colors['border']; ?>; border-radius:12px; padding:16px; text-align:center; position:relative; transition:all 0.2s ease; cursor:default;">
                                        <div style="position:absolute; top:8px; right:8px; width:8px; height:8px; background:<?php echo $colors['accent']; ?>; border-radius:50%;"></div>
                                        <div style="font-weight:700; color:<?php echo $colors['text']; ?>; margin-bottom:8px; font-size:0.9em; line-height:1.2;"><?php echo htmlspecialchars($status); ?></div>
                                        <div style="font-size:2em; font-weight:800; color:<?php echo $colors['accent']; ?>; margin-bottom:4px;"><?php echo $count; ?></div>
                                        <div style="color:<?php echo $colors['text']; ?>; font-size:0.85em; font-weight:600; margin-bottom:2px;">pallet<?php echo $count !== 1 ? 's' : ''; ?></div>
                                        <?php if ($modules > 0): ?>
                                            <div style="color:<?php echo $colors['text']; ?>; font-size:0.75em; opacity:0.8;"><?php echo number_format($modules); ?> modules</div>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <?php if (empty($replacementStatusTotals)): ?>
                                <div style="text-align:center; padding:24px; color:#6c757d; font-style:italic;">
                                    No replacement pallets have been created yet
                                </div>
                            <?php endif; ?>
                            
                            <!-- Estimated Delivery Date for Replacement (Admin/Global Admin Only) -->
                            <?php if ($isAdmin && (string)($claim['resolution_type'] ?? '') === 'Replacement' && !empty($linkedIds)): ?>
                                <div style="margin-top:16px; padding-top:16px; border-top:1px solid #e6edf1;">
                                    <div style="display:flex; align-items:center; justify-content:center; gap:12px; font-size:0.9em;">
                                        <span style="color:#293E4C; font-weight:600; display:flex; align-items:center; gap:6px;">
                                            <span style="width:10px; height:10px; background:linear-gradient(135deg, #488C9A, #3A6E7F); border-radius:50%; display:inline-block;"></span>
                                            Estimated Delivery:
                                        </span>
                                        <input type="date" 
                                               id="estimated_delivery_date" 
                                               value="<?php echo htmlspecialchars($claim['estimated_delivery_date'] ?? ''); ?>" 
                                               style="border:1px solid #e2e9ef; border-radius:8px; padding:6px 10px; font-size:0.85em; background:#fff;">
                                        <button type="button" 
                                                onclick="saveEstimatedDeliveryDate()" 
                                                id="save_delivery_btn"
                                                style="background:linear-gradient(135deg, #488C9A, #3A6E7F); border:none; border-radius:6px; padding:6px 12px; font-size:0.8em; color:#fff; cursor:pointer; transition:all 0.2s ease; font-weight:600;"
                                                onmouseover="this.style.filter='brightness(1.05)'"
                                                onmouseout="this.style.filter='brightness(1)'"
                                                title="Save estimated delivery date">Save</button>
                                        <button type="button" 
                                                onclick="document.getElementById('estimated_delivery_date').value=''; saveEstimatedDeliveryDate()" 
                                                style="background:#f8f9fa; border:1px solid #e9ecef; border-radius:6px; padding:6px 10px; font-size:0.8em; color:#6c757d; cursor:pointer; transition:all 0.2s ease;"
                                                onmouseover="this.style.background='#e9ecef'"
                                                onmouseout="this.style.background='#f8f9fa'"
                                                title="Clear date">Clear</button>
                                        <div id="delivery_date_status" style="font-size:0.8em; margin-top:4px; min-height:16px;"></div>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                    
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
                <?php
                    // Load incident report documents tied to this ticket from project_documents
                    $incidentDocs = [];
                    try {
                        $connDocs = getDBConnection();
                        if ($connDocs) {
                            $like = '%incident_ticket_id:' . (int)$claimId . '%';
                            $stmtD = $connDocs->prepare("SELECT id, document_sub_type, original_file_name, file_path, mime_type, uploaded_at, description FROM project_documents WHERE project_id = ? AND document_type = 'exception_reports' AND is_active = 1 AND entity_context LIKE ? ORDER BY uploaded_at DESC");
                            $pidDocs = (int)$claim['project_id'];
                            $stmtD->bind_param('is', $pidDocs, $like);
                            $stmtD->execute();
                            $rd = $stmtD->get_result();
                            while ($r = $rd->fetch_assoc()) { $incidentDocs[] = $r; }
                            $stmtD->close();
                            $connDocs->close();
                        }
                    } catch (Exception $e) { /* ignore and fall back */ }
                ?>
                <?php if (empty($pictures) && empty($incidentDocs)): ?>
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
                        <?php foreach ($incidentDocs as $doc): $isImg = preg_match('/^image\//i', (string)$doc['mime_type']); ?>
                            <div>
                                <a href="<?php echo htmlspecialchars($doc['file_path']); ?>" target="_blank" title="<?php echo htmlspecialchars($doc['document_sub_type'] . ' • ' . ($doc['original_file_name'] ?? '')); ?>">
                                    <?php if ($isImg): ?>
                                        <img src="<?php echo htmlspecialchars($doc['file_path']); ?>" alt="<?php echo htmlspecialchars($doc['original_file_name'] ?? 'Document'); ?>">
                                    <?php else: ?>
                                        <div style="padding:16px; text-align:center;">
                                            📄 <?php echo htmlspecialchars($doc['document_sub_type'] ?: 'Document'); ?><br>
                                            <span style="font-size:12px;color:#6c757d;"><?php echo htmlspecialchars($doc['original_file_name'] ?? ''); ?></span>
                                        </div>
                                    <?php endif; ?>
                                </a>
                                <?php if ($isAdmin): ?>
                                    <div class="mt-1">
                                        <label class="form-check-label small"><input type="checkbox" class="form-check-input delete-project-doc" value="<?php echo (int)$doc['id']; ?>"> Delete</label>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                        <?php if ($isAdmin): ?><?php endif; ?>
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
                    <div class="mt-3 d-flex justify-content-end" style="gap:10px;">
                        <!-- Open new Incident Report upload modal -->
                        <button type="button" class="btn btn-primary" onclick="openIncidentUploadModal()">
                            <i class="fas fa-upload me-2"></i>Upload Attachments
                        </button>
                        <script>
                        // Ensure the click handler exists even if main script is deferred/gated
                        if (typeof window.openIncidentUploadModal !== 'function') {
                            window.openIncidentUploadModal = function(){
                                var m = document.getElementById('incidentUploadModal');
                                if (m) { m.style.display = 'block'; }
                            };
                        }
                        </script>
                        <!-- Delete selected attachments (pictures + ticket docs) -->
                        <button type="button" onclick="deleteSelectedAttachments()" style="background: linear-gradient(135deg, #ef4444, #dc2626); color:#fff; border:none; border-radius:12px; padding:10px 16px; font-weight:700; display:inline-flex; align-items:center; gap:8px; cursor:pointer;">
                            <i class="fas fa-trash"></i>Delete
                        </button>
                    </div>
                </form>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php if ($isAdmin): ?>
    <!-- Incident Report Upload Modal -->
    <div id="incidentUploadModal" class="modal" style="display:none; z-index:10000;">
        <div class="modal-content" style="max-width:720px;width:90%;">
            <span class="close" onclick="closeIncidentUploadModal()" title="Close">&times;</span>
            <h2 style="margin-top:0;">Upload Incident Report Documents</h2>
            <p style="margin-top:4px;color:#6c757d;">Project: <strong><?php echo htmlspecialchars($claim['project_name']); ?></strong> • Ticket #: <strong><?php echo (int)$claimId; ?></strong></p>
            <div class="hr" style="height:1px;background:#eef2f6;margin:12px 0 16px;"></div>

            <div class="admin-form-row full">
                <div class="admin-form-group">
                    <label class="form-label">Document Sub-Type</label>
                    <select id="irDocSubType" class="form-select" required>
                        <option value="">Choose sub-type...</option>
                        <option value="Damage Photo">Damage Photo</option>
                        <option value="Warranty Document">Warranty Document</option>
                        <option value="Project POD">Project POD</option>
                        <option value="Warehouse POD">Warehouse POD</option>
                    </select>
                </div>
            </div>

            <div class="admin-form-row full">
                <div class="admin-form-group">
                    <label class="form-label">Description (Optional)</label>
                    <textarea id="irDescription" class="form-control" rows="3" placeholder="Enter a description..."></textarea>
                </div>
            </div>

            <div class="admin-form-row full">
                <div class="admin-form-group">
                    <div id="irUploadArea" class="upload-card" style="height:auto;min-height:110px;align-items:center;" onclick="document.getElementById('irFiles').click()">
                        <div class="icon">＋</div>
                        <div style="font-weight:600;">Drop photos here or click to browse</div>
                        <div style="font-size:12px; color:#6c757d;">Supports: JPG, JPEG, PNG, WEBP, GIF (Max 50MB each)</div>
                    </div>
                    <input id="irFiles" type="file" accept=".jpg,.jpeg,.png,.webp,.gif" multiple style="display:none;" onchange="irHandleFiles(event)">
                    <div id="irFileList" class="file-pills" style="margin-top:10px;"></div>
                </div>
            </div>

            <div class="d-flex justify-content-end" style="gap:10px; margin-top:10px;">
                <button type="button" class="btn-secondary" onclick="closeIncidentUploadModal()">Cancel</button>
                <button type="button" id="irUploadBtn" class="btn btn-primary" onclick="irUpload()" disabled>
                    <i class="fas fa-upload me-2"></i>Upload
                </button>
            </div>
            <div id="irProgress" style="display:none;margin-top:10px;color:#6c757d;">Uploading...</div>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($isAdmin): ?>
    <script>
    // Guards to ensure handlers exist even if main block hasn't executed yet
    (function(){
        if (typeof window.selectedIrFiles === 'undefined') window.selectedIrFiles = [];
        if (typeof window.irRenderFiles !== 'function') {
            window.irRenderFiles = function(){
                var list = document.getElementById('irFileList');
                if (!list) return;
                if (!window.selectedIrFiles.length){ list.innerHTML=''; return; }
                list.innerHTML = window.selectedIrFiles.map(function(f,i){
                    var size=(f.size/1024/1024).toFixed(2)+' MB';
                    return '<div class="file-pill" style="display:inline-flex;align-items:center;gap:8px;border:1px solid #e1e6ea;border-radius:20px;padding:6px 10px;margin:4px 6px;background:#fff;">'
                        + '<span style="font-weight:600;">'+ (f.name||'file') +'</span>'
                        + '<span style="color:#6c757d;font-size:12px;">'+size+'</span>'
                        + '<button type="button" onclick="irRemoveFile('+i+')" style="border:none;background:#f1f3f5;color:#7a7f85;border-radius:12px;padding:2px 8px;cursor:pointer;">Remove</button>'
                        + '</div>';
                }).join('');
            };
        }
        if (typeof window.irValidate !== 'function') {
            window.irValidate = function(){
                var sub = document.getElementById('irDocSubType');
                var btn = document.getElementById('irUploadBtn');
                if (!sub || !btn) return;
                var ok = !!sub.value && window.selectedIrFiles.length>0;
                btn.disabled = !ok;
            };
        }
        if (typeof window.irHandleFiles !== 'function') {
            window.irHandleFiles = function(e){
                var files = Array.from((e && e.target && e.target.files) ? e.target.files : []);
                window.selectedIrFiles = window.selectedIrFiles.concat(files);
                window.irRenderFiles();
                window.irValidate();
            };
        }
        if (typeof window.irRemoveFile !== 'function') {
            window.irRemoveFile = function(idx){
                window.selectedIrFiles.splice(idx,1);
                window.irRenderFiles();
                window.irValidate();
            };
        }
        if (typeof window.closeIncidentUploadModal !== 'function') {
            window.closeIncidentUploadModal = function(){
                var m = document.getElementById('incidentUploadModal');
                if (m) m.style.display='none';
                var sel = document.getElementById('irDocSubType'); if (sel) sel.value='';
                var d = document.getElementById('irDescription'); if (d) d.value='';
                window.selectedIrFiles = [];
                window.irRenderFiles();
                window.irValidate();
            };
        }
        if (typeof window.irUpload !== 'function') {
            window.irUpload = async function(){
                if (!window.selectedIrFiles.length) return;
                var subEl = document.getElementById('irDocSubType');
                var descEl = document.getElementById('irDescription');
                if (!subEl || !subEl.value) { alert('Please choose a document sub-type.'); return; }
                var form = new FormData();
                form.append('project_id', '<?php echo (int)$claim['project_id']; ?>');
                form.append('document_type', 'exception_reports');
                form.append('document_sub_type', 'Proof of Completion');
                form.append('ticket_id', '<?php echo (int)$claimId; ?>');
                form.append('description', descEl && descEl.value ? descEl.value : '');
                window.selectedIrFiles.forEach(function(f){ form.append('files[]', f); });
                var prog = document.getElementById('irProgress');
                var btn = document.getElementById('irUploadBtn');
                if (prog) prog.style.display='block';
                if (btn) btn.disabled = true;
                try {
                    var resp = await fetch('upload_global_document.php', { method:'POST', body: form });
                    var data = await resp.json();
                    if (data && data.success) {
                        alert('Documents uploaded successfully');
                        window.closeIncidentUploadModal();
                        // optional: trigger page reload or refresh section
                    } else {
                        alert('Upload failed: ' + (data && data.message ? data.message : 'Unknown error'));
                    }
                } catch (err) {
                    alert('Upload failed: ' + err.message);
                } finally {
                    if (prog) prog.style.display='none';
                    if (btn) btn.disabled = false;
                }
            };
        }
        // Delete selected attachments: project documents via AJAX, legacy pictures via form submit
        if (typeof window.deleteSelectedAttachments !== 'function') {
            window.deleteSelectedAttachments = async function(){
                var docChecks = Array.from(document.querySelectorAll('.delete-project-doc:checked'));
                var picChecks = Array.from(document.querySelectorAll('input[name="delete_pictures[]"]:checked'));
                if (docChecks.length === 0 && picChecks.length === 0) { alert('Select at least one attachment to delete.'); return; }
                if (!confirm('Delete selected attachment(s)? This action cannot be undone.')) return;

                // Delete project documents first via API
                if (docChecks.length > 0) {
                    try {
                        var ids = docChecks.map(function(cb){ return parseInt(cb.value, 10); }).filter(function(v){return v>0;});
                        var resp = await fetch('delete_project_documents.php', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ ids: ids })
                        });
                        var data = await resp.json();
                        if (!data || (!data.success && (!data.deleted_ids || data.deleted_ids.length===0))) {
                            alert('Failed to delete some project documents.');
                        }
                    } catch (e) {
                        alert('Error deleting documents: ' + e.message);
                    }
                }

                // If any legacy pictures selected, submit the form to process deletions
                if (picChecks.length > 0) {
                    // Find the nearest form (attachments form wraps this area)
                    var btn = document.activeElement;
                    // Fallback: find the form by traversing
                    var form = btn && btn.closest ? btn.closest('form') : null;
                    if (!form) {
                        form = document.querySelector('form[action="process_warranty_update.php"]');
                    }
                    if (form) {
                        form.submit();
                        return;
                    }
                }
                // Otherwise reload to reflect changes
                window.location.reload();
            };
        }
        // Basic drag-over styling safe-guard
        var area = document.getElementById('irUploadArea');
        if (area && !area.__dragBound) {
            area.addEventListener('dragover', function(e){ e.preventDefault(); area.style.borderColor='#488C9A'; });
            area.addEventListener('dragleave', function(e){ e.preventDefault(); area.style.borderColor='#d0d0d0'; });
            area.addEventListener('drop', function(e){
                e.preventDefault(); area.style.borderColor='#d0d0d0';
                var files = Array.from(e.dataTransfer && e.dataTransfer.files ? e.dataTransfer.files : []);
                if (files.length){ window.selectedIrFiles = window.selectedIrFiles.concat(files); window.irRenderFiles(); window.irValidate(); }
            });
            area.__dragBound = true;
        }
    })();
    </script>
    <?php endif; ?>

    <?php if ($isAdmin): ?>
    <form method="post" action="process_warranty_update.php" enctype="multipart/form-data">
        <input type="hidden" name="claim_id" value="<?php echo (int)$claimId; ?>">
        <?php if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); } ?>
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">

        <div class="card admin-panel" style="margin: 0 20px 20px;">
            <div class="card-header">Status Actions</div>
            <div class="card-body">
                <?php if (!empty($_SESSION['flash_success'])): ?>
                    <div class="error-box" style="background:#d1e7dd;border-color:#badbcc;color:#0f5132;"><?php echo htmlspecialchars($_SESSION['flash_success']); unset($_SESSION['flash_success']); ?></div>
                <?php endif; ?>
                <div id="inline_error" class="error-box" style="display:none;"></div>
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
                                            <label class="form-label req">Resolution Type</label>
                                            <select name="resolution_type" class="form-select" id="resolution_type">
                                                <option value="">—</option>
                                                <?php foreach (['Credit','Replacement','No-charge','Monitoring'] as $rt): ?>
                                                    <option value="<?php echo $rt; ?>" <?php echo ($claim['resolution_type']===$rt)?'selected':''; ?>><?php echo $rt; ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="admin-form-group credit-only" style="display:none;">
                                            <label class="form-label req">Credit Amount</label>
                                            <input type="number" step="0.01" class="form-control" name="credit_amount" value="<?php echo htmlspecialchars((string)$claim['credit_amount']); ?>" placeholder="0.00">
                                        </div>
</div>

<?php if ($isAdmin): ?>
<script>
// Modal controls
function openIncidentUploadModal(){
    document.getElementById('incidentUploadModal').style.display='block';
}
function closeIncidentUploadModal(){
    document.getElementById('incidentUploadModal').style.display='none';
    // reset fields
    document.getElementById('irDocSubType').value='';
    document.getElementById('irDescription').value='';
    selectedIrFiles = [];
    irRenderFiles();
    irValidate();
}

// File selection
let selectedIrFiles = [];
function irHandleFiles(e){
    const files = Array.from(e.target.files||[]);
    selectedIrFiles = selectedIrFiles.concat(files);
    irRenderFiles();
    irValidate();
}
function irRemoveFile(idx){
    selectedIrFiles.splice(idx,1);
    irRenderFiles();
    irValidate();
}
function irRenderFiles(){
    const list = document.getElementById('irFileList');
    if (!list) return;
    if (!selectedIrFiles.length){ list.innerHTML=''; return; }
    list.innerHTML = selectedIrFiles.map((f,i)=>{
        const size=(f.size/1024/1024).toFixed(2)+' MB';
        return `<div class="file-pill" style="display:inline-flex;align-items:center;gap:8px;border:1px solid #e1e6ea;border-radius:20px;padding:6px 10px;margin:4px 6px;background:#fff;">
            <span style="font-weight:600;">${f.name}</span>
            <span style="color:#6c757d;font-size:12px;">${size}</span>
            <button type="button" onclick="irRemoveFile(${i})" style="border:none;background:#f1f3f5;color:#7a7f85;border-radius:12px;padding:2px 8px;cursor:pointer;">Remove</button>
        </div>`
    }).join('');
}

function irValidate(){
    const sub = document.getElementById('irDocSubType').value;
    const ok = !!sub && selectedIrFiles.length>0;
    document.getElementById('irUploadBtn').disabled = !ok;
}
document.getElementById('irDocSubType').addEventListener('change', irValidate);

// Upload
async function irUpload(){
    if (selectedIrFiles.length===0) return;
    const form = new FormData();
    form.append('project_id', '<?php echo (int)$claim['project_id']; ?>');
    form.append('document_type', 'exception_reports');
    form.append('document_sub_type', 'Proof of Completion');
    form.append('ticket_id', '<?php echo (int)$claimId; ?>');
    form.append('description', document.getElementById('irDescription').value||'');
    selectedIrFiles.forEach(f=>form.append('files[]', f));

    const prog = document.getElementById('irProgress');
    const btn = document.getElementById('irUploadBtn');
    prog.style.display='block';
    btn.disabled = true;
    try{
        const resp = await fetch('upload_global_document.php', { method:'POST', body: form });
        const data = await resp.json();
        if (data && data.success){
            alert('Documents uploaded successfully');
            closeIncidentUploadModal();
        } else {
            alert('Upload failed: ' + (data?.message||'Unknown error'));
        }
    }catch(err){
        alert('Upload failed: ' + err.message);
    }finally{
        prog.style.display='none';
        btn.disabled = false;
    }
}

// Drag-drop on card
(function(){
    const area = document.getElementById('irUploadArea');
    if (!area) return;
    area.addEventListener('dragover', e=>{ e.preventDefault(); area.style.borderColor='#488C9A'; });
    area.addEventListener('dragleave', e=>{ e.preventDefault(); area.style.borderColor='#d0d0d0'; });
    area.addEventListener('drop', e=>{
        e.preventDefault(); area.style.borderColor='#d0d0d0';
        const files = Array.from(e.dataTransfer.files||[]);
        if (files.length){ selectedIrFiles = selectedIrFiles.concat(files); irRenderFiles(); irValidate(); }
    });
})();
</script>
<?php endif; ?>

                                    

                                    <div class="admin-form-row full replacement-only">
                                        <div class="admin-form-group">
                                            <label class="form-label">Replacement Pallets</label>
                                            <div style="display: flex; flex-direction: column; gap: 15px;">
                                                <?php $planPallets = 0; $planModules = 0; if ($hasReplacementPlan) { foreach ($replacementPlanItems as $rp) { $planModules += (int)($rp['total_modules'] ?? 0); $planPallets += (int)($rp['full_pallets'] ?? 0); if (!empty($rp['partial_modules'])) { $planPallets++; } } } ?>
                                                <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 10px;">
                                                    <div>
                                                        <div class="form-hint" style="margin: 0;">Currently linked: <strong><?php echo (int)count($linkedIds); ?> pallet(s)</strong></div>
                                                        <?php if ($hasReplacementPlan): ?>
                                                            <div class="form-hint" style="margin: 2px 0 0 0; color:#0f5132;">Staged plan: <strong><?php echo (int)$planPallets; ?> pallet<?php echo $planPallets===1?'':'s'; ?></strong> / <?php echo number_format((int)$planModules); ?> modules</div>
                                                        <?php endif; ?>
                                                    </div>
                                                    <a class="btn btn-secondary" href="create_replacements.php?claim_id=<?php echo (int)$claimId; ?>" style="white-space: nowrap;">+ Create More Pallets</a>
                                                </div>

                                                <?php if ($hasReplacementPlan): ?>
                                                    <div style="background: #e8f4f6; border: 1px solid #cde6ea; border-radius: 10px; padding: 15px;">
                                                        <h6 style="margin: 0 0 10px 0; color: #1f3b4d; font-weight: 700;">Staged Replacement Plan</h6>
                                                        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 10px;">
                                                            <?php foreach ($replacementPlanItems as $rp): ?>
                                                                <div style="background:#fff; border:1px solid #dbe9ed; border-radius:8px; padding:10px 12px;">
                                                                    <div style="font-weight:700;"><?php echo (int)($rp['wattage'] ?? 0); ?>W</div>
                                                                    <div style="color:#2c3e50; font-size:0.95em;">Modules/pallet: <?php echo (int)($rp['modules_per_pallet'] ?? 0); ?></div>
                                                                    <div style="color:#2c3e50; font-size:0.95em;">Pallets: <?php echo (int)($rp['full_pallets'] ?? 0); ?> full<?php echo !empty($rp['partial_modules']) ? ' + 1 partial' : ''; ?></div>
                                                                    <div style="color:#2c3e50; font-size:0.95em;">Total modules: <?php echo number_format((int)($rp['total_modules'] ?? 0)); ?></div>
                                                                    <?php if (!empty($rp['pallets_per_truck']) || !empty($rp['modules_per_truck'])): ?>
                                                                        <div style="color:#2c3e50; font-size:0.95em;">Truck load: <?php echo (int)($rp['pallets_per_truck'] ?? 0); ?> pallets / <?php echo (int)($rp['modules_per_truck'] ?? 0); ?> modules</div>
                                                                    <?php endif; ?>
                                                                    <?php if (!empty($rp['manufacturer'])): ?>
                                                                        <div style="color:#2c3e50; font-size:0.95em;">Manufacturer: <?php echo htmlspecialchars((string)$rp['manufacturer']); ?></div>
                                                                    <?php endif; ?>
                                                                    <?php if (array_key_exists('manufacturer_location_id', $rp)): ?>
                                                                        <div style="color:#2c3e50; font-size:0.95em;">Location ID: <?php echo ($rp['manufacturer_location_id'] === null || $rp['manufacturer_location_id'] === '') ? '—' : (int)$rp['manufacturer_location_id']; ?></div>
                                                                    <?php endif; ?>
                                                                </div>
                                                            <?php endforeach; ?>
                                                        </div>
                                                        <div style="margin-top:10px; color:#0f5132; font-size:0.9em; font-weight:600;">Pallets will be created and linked when you apply a Replacement approval.</div>
                                                    </div>
                                                <?php endif; ?>

                                                <?php if (!empty($linkedIds)): ?>
                                                    <div style="background: #f8f9fa; border: 1px solid #e8edf2; border-radius: 10px; padding: 15px;">
                                                        <h6 style="margin: 0 0 10px 0; color: #293E4C; font-weight: 600;">Linked Pallets:</h6>
                                                        <div style="display: flex; flex-wrap: wrap; gap: 8px;">
                                                            <?php foreach ($linkedIds as $pid): ?>
                                                                <span style="background: #e8f4f6; color: #2c3e50; padding: 4px 10px; border-radius: 20px; font-size: 0.9em; font-weight: 600;">
                                                                    <?php echo htmlspecialchars($linkedMap[$pid] ?? ('ID '.$pid)); ?>
                                                                </span>
                                                            <?php endforeach; ?>
                                                        </div>
                                                        
                                                        <?php 
                                                            $st = $replacementStatusTotals; 
                                                            $statuses = ['At Manufacturer','On Water','Cleared Customs','In Transit to Warehouse','In Warehouse','In Transit to Project','Delivered to Project'];
                                                            $hasAny = false; foreach ($statuses as $s) { if ((int)($st[$s]['pallets'] ?? 0) > 0) { $hasAny = true; break; } }
                                                        ?>
                                                        <?php if ($hasAny): ?>
                                                            <div style="margin-top: 15px;">
                                                                <h6 style="margin: 0 0 10px 0; color: #293E4C; font-weight: 600;">Shipping Status:</h6>
                                                                <div class="ship-statuses">
                                                                    <?php foreach ($statuses as $s): $p=(int)($st[$s]['pallets'] ?? 0); if ($p<=0) continue; ?>
                                                                        <div class="ship-box">
                                                                            <div class="status-label"><?php echo htmlspecialchars($s); ?></div>
                                                                            <div class="status-count"><?php echo $p; ?></div>
                                                                            <div class="status-unit">pallets</div>
                                                                        </div>
                                                                    <?php endforeach; ?>
                                                                </div>
                                                            </div>
                                                        <?php endif; ?>
                                                    </div>
                                                <?php elseif (!$hasReplacementPlan): ?>
                                                    <div style="background: #fff3cd; border: 1px solid #ffeaa7; border-radius: 10px; padding: 15px; text-align: center; color: #856404;">
                                                        <strong>No replacement pallets linked yet.</strong><br>
                                                        <span style="font-size: 0.9em;">Create replacement pallets to proceed with this resolution.</span>
                                                    </div>
                                                <?php endif; ?>
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
                                            <div id="proof_upload_preview" class="file-pills" style="display:none;"></div>
                                            <div class="form-hint">Closing requires a proof file.</div>
                                        </div>
                                    </div>
                                </div>
                                <div id="decision_reject_fields" style="display:none; padding:8px 0;">
                                    <label class="form-label req">Rejection Reason</label>
                                    <textarea class="form-control" name="rejection_reason" rows="3" placeholder="Provide a clear reason for rejection."></textarea>
                                </div>
                                <button type="button" class="btn-primary" id="btn_apply_decision"><svg class="icon" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M5 12h12l-4-4 1.4-1.4L21.8 12l-7.4 5.4L13 16l4-4H5z"/></svg><span>Apply Decision</span></button>
                            <?php elseif ($from === 'Approved - Replacement'): ?>
                                <?php 
                                    // Check current replacement status to determine next action
                                    $currentReplacementStatus = getPredominantReplacementStatus($replacementStatusTotals);
                                    $hasLinkedPallets = !empty($linkedIds);
                                ?>
                                
                                <?php if (!$hasLinkedPallets): ?>
                                    <div class="alert" style="background:#fff3cd; border:1px solid #ffeaa7; border-radius:10px; padding:12px; color:#856404; margin-bottom:12px;">
                                        <strong>No Replacement Pallets Linked</strong><br>
                                        <span style="font-size:0.9em;">Create replacement pallets first to proceed with delivery workflow.</span>
                                    </div>
                                    <a href="create_replacements.php?claim_id=<?php echo (int)$claimId; ?>" class="btn-primary">
                                        <svg class="icon" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 4l8 8-8 8V4z"/></svg>
                                        <span>Create Replacement Pallets</span>
                                    </a>
                                <?php elseif ($currentReplacementStatus === 'At Manufacturer'): ?>
                                    <div class="replacement-workflow-info" style="background:#e8f4f6; border:1px solid #d1ecf1; border-radius:10px; padding:12px; margin-bottom:12px;">
                                        <div style="display:flex; align-items:center; gap:8px; margin-bottom:6px;">
                                            <span style="width:8px; height:8px; background:#f39c12; border-radius:50%;"></span>
                                            <strong style="color:#2c3e50;">Replacement Status: At Manufacturer</strong>
                                        </div>
                                        <div style="color:#0c5460; font-size:0.9em;">Ready to create shipment for replacement pallets</div>
                                    </div>
                                    <a href="create_shipment.php?project_id=<?php echo (int)$claim['project_id']; ?>&status_filter=At%20Manufacturer&replacement_claim_id=<?php echo (int)$claimId; ?>" class="btn-primary">
                                        <svg class="icon" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 4l8 8-8 8V4z"/></svg>
                                        <span>Create Shipment</span>
                                    </a>
                                <?php elseif ($currentReplacementStatus === 'On Water'): ?>
                                    <div class="replacement-workflow-info" style="background:#d1ecf1; border:1px solid #bee5eb; border-radius:10px; padding:12px; margin-bottom:12px;">
                                        <div style="display:flex; align-items:center; gap:8px; margin-bottom:6px;">
                                            <span style="width:8px; height:8px; background:#17a2b8; border-radius:50%;"></span>
                                            <strong style="color:#0c5460;">Replacement Status: On Water</strong>
                                        </div>
                                        <div style="color:#0c5460; font-size:0.9em;">Shipment is in transit overseas</div>
                                    </div>
                                    <button type="button" class="btn-primary" onclick="updateReplacementStatus('Cleared Customs')">
                                        <svg class="icon" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 4l8 8-8 8V4z"/></svg>
                                        <span>Mark Cleared Customs</span>
                                    </button>
                                <?php elseif ($currentReplacementStatus === 'Cleared Customs'): ?>
                                    <div class="replacement-workflow-info" style="background:#d4edda; border:1px solid #c3e6cb; border-radius:10px; padding:12px; margin-bottom:12px;">
                                        <div style="display:flex; align-items:center; gap:8px; margin-bottom:6px;">
                                            <span style="width:8px; height:8px; background:#28a745; border-radius:50%;"></span>
                                            <strong style="color:#155724;">Replacement Status: Cleared Customs</strong>
                                        </div>
                                        <div style="color:#155724; font-size:0.9em;">Ready for domestic transport</div>
                                    </div>
                                    <button type="button" class="btn-primary" onclick="updateReplacementStatus('In Transit to Warehouse')">
                                        <svg class="icon" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 4l8 8-8 8V4z"/></svg>
                                        <span>Start Warehouse Transport</span>
                                    </button>
                                <?php elseif ($currentReplacementStatus === 'In Transit to Warehouse'): ?>
                                    <div class="replacement-workflow-info" style="background:#e2e3e5; border:1px solid #d6d8db; border-radius:10px; padding:12px; margin-bottom:12px;">
                                        <div style="display:flex; align-items:center; gap:8px; margin-bottom:6px;">
                                            <span style="width:8px; height:8px; background:#6c757d; border-radius:50%;"></span>
                                            <strong style="color:#383d41;">Replacement Status: In Transit to Warehouse</strong>
                                        </div>
                                        <div style="color:#383d41; font-size:0.9em;">En route to warehouse facility</div>
                                    </div>
                                    <button type="button" class="btn-primary" onclick="updateReplacementStatus('In Warehouse')">
                                        <svg class="icon" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 4l8 8-8 8V4z"/></svg>
                                        <span>Mark Delivered to Warehouse</span>
                                    </button>
                                <?php elseif ($currentReplacementStatus === 'In Warehouse'): ?>
                                    <div class="replacement-workflow-info" style="background:#f8d7da; border:1px solid #f1b0b7; border-radius:10px; padding:12px; margin-bottom:12px;">
                                        <div style="display:flex; align-items:center; gap:8px; margin-bottom:6px;">
                                            <span style="width:8px; height:8px; background:#dc3545; border-radius:50%;"></span>
                                            <strong style="color:#721c24;">Replacement Status: In Warehouse</strong>
                                        </div>
                                        <div style="color:#721c24; font-size:0.9em;">Ready for final delivery to project site</div>
                                    </div>
                                    <a href="create_shipment.php?project_id=<?php echo (int)$claim['project_id']; ?>&status_filter=In%20Warehouse&replacement_claim_id=<?php echo (int)$claimId; ?>" class="btn-primary">
                                        <svg class="icon" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 4l8 8-8 8V4z"/></svg>
                                        <span>Create Final Delivery</span>
                                    </a>
                                <?php elseif ($currentReplacementStatus === 'In Transit to Project'): ?>
                                    <div class="replacement-workflow-info" style="background:#d1ecf1; border:1px solid #bee5eb; border-radius:10px; padding:12px; margin-bottom:12px;">
                                        <div style="display:flex; align-items:center; gap:8px; margin-bottom:6px;">
                                            <span style="width:8px; height:8px; background:#17a2b8; border-radius:50%;"></span>
                                            <strong style="color:#0c5460;">Replacement Status: In Transit to Project</strong>
                                        </div>
                                        <div style="color:#0c5460; font-size:0.9em;">Final delivery to project site in progress</div>
                                    </div>
                                    <button type="button" class="btn-primary" onclick="updateReplacementStatus('Delivered to Project')">
                                        <svg class="icon" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 4l8 8-8 8V4z"/></svg>
                                        <span>Mark Delivered to Project</span>
                                    </button>
                                <?php elseif ($currentReplacementStatus === 'Delivered to Project'): ?>
                                    <div class="replacement-workflow-info" style="background:#d4edda; border:1px solid #c3e6cb; border-radius:10px; padding:12px; margin-bottom:12px;">
                                        <div style="display:flex; align-items:center; gap:8px; margin-bottom:6px;">
                                            <span style="width:8px; height:8px; background:#28a745; border-radius:50%;"></span>
                                            <strong style="color:#155724;">Replacement Status: Delivered to Project</strong>
                                        </div>
                                        <div style="color:#155724; font-size:0.9em;">All replacement modules have been delivered successfully - ready to close ticket</div>
                                    </div>
                                    <button type="button" class="btn-primary" id="btn_to_closed">
                                        <svg class="icon" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 4l8 8-8 8V4z"/></svg>
                                        <span>Close Ticket</span>
                                    </button>
                                <?php else: ?>
                                    <!-- Fallback for mixed or unknown statuses -->
                                    <div class="replacement-workflow-info" style="background:#f8f9fa; border:1px solid #e9ecef; border-radius:10px; padding:12px; margin-bottom:12px;">
                                        <div style="display:flex; align-items:center; gap:8px; margin-bottom:6px;">
                                            <span style="width:8px; height:8px; background:#6c757d; border-radius:50%;"></span>
                                            <strong style="color:#495057;">Mixed Replacement Status</strong>
                                        </div>
                                        <div style="color:#6c757d; font-size:0.9em;">Multiple pallets at different delivery stages</div>
                                    </div>
                                    <button type="button" class="btn-primary" id="btn_to_closed">
                                        <svg class="icon" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 4l8 8-8 8V4z"/></svg>
                                        <span>Close Ticket</span>
                                    </button>
                                <?php endif; ?>
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

  // Pre-fill UI when redirected after creating replacements
  const params = new URLSearchParams(window.location.search);
  if (params.get('prefill') === 'approve_replacement') {
    const fromStatus = (statusHidden && statusHidden.value) ? statusHidden.value : '';
    if (fromStatus.startsWith('Pending ')) {
      const decApproveRadio = document.getElementById('dec_approve');
      if (decApproveRadio) decApproveRadio.checked = true;
      if (resSelect) resSelect.value = 'Replacement';
      // Force show replacement sections immediately
      toggleReplacementSections();
      syncDecisionUi();
      updateNextStepHelp();
      // Scroll to the replacement section to show the user what they just created
      setTimeout(() => {
        const replacementSection = document.querySelector('.replacement-only');
        if (replacementSection) {
          replacementSection.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
      }, 100);
    }
  }

  const btnApplyDecision = document.getElementById('btn_apply_decision');
  if (btnApplyDecision) btnApplyDecision.addEventListener('click', () => {
    const errorBox = document.getElementById('inline_error');
    function showError(msg){ if (errorBox){ errorBox.textContent = msg; errorBox.style.display = ''; errorBox.scrollIntoView({behavior:'smooth', block:'center'});} }
    if (errorBox){ errorBox.style.display = 'none'; errorBox.textContent=''; }

    if (decApprove && decApprove.checked) {
      // Approve requires a resolution type
      const rt = (resSelect && resSelect.value) ? resSelect.value : '';
      if (!rt) { showError('Select a Resolution Type (Credit or Replacement) before approving.'); return; }
      if (rt === 'Credit') {
        const amtInput = document.querySelector('input[name="credit_amount"]');
        const amt = amtInput ? parseFloat(amtInput.value) : NaN;
        if (!amt || amt <= 0) { showError('Enter a valid Credit Amount greater than 0.'); return; }
      }
      // For Replacement approvals, require at least one linked pallet
      if (rt === 'Replacement') {
        const hasLinked = <?php echo !empty($linkedIds) ? 'true' : 'false'; ?>;
        const hasPlan = <?php echo $hasReplacementPlan ? 'true' : 'false'; ?>;
        if (!hasLinked && !hasPlan) { showError('Replacement requires a linked pallet or a staged replacement plan'); return; }
      }
      // Approval requires either a public update or at least one uploaded file
      const publicNotes = (document.querySelector('textarea[name="public_notes"]')?.value || '').trim();
      const hasFile = document.getElementById('proof_upload')?.files?.length > 0;
      if (!publicNotes && !hasFile) { showError('Add a public update or upload at least one file to proceed.'); return; }
      statusHidden.value = 'Approved';
    } else if (decReject && decReject.checked) {
      // Reject requires a reason or file upload
      const reason = (document.querySelector('textarea[name="rejection_reason"]')?.value || '').trim();
      const uploaded = document.getElementById('proof_upload')?.files?.length > 0;
      if (!reason && !uploaded) { showError('Provide a rejection reason or upload at least one file.'); return; }
      statusHidden.value = 'Rejected';
    } else {
      showError('Select Approve or Reject.');
      return;
    }
    btnApplyDecision.closest('form').submit();
  });

  const btnToShipped = document.getElementById('btn_to_shipped');
  if (btnToShipped) btnToShipped.addEventListener('click', () => {
    const errorBox = document.getElementById('inline_error');
    function showError(msg){ if (errorBox){ errorBox.textContent = msg; errorBox.style.display = ''; errorBox.scrollIntoView({behavior:'smooth', block:'center'});} }
    if (errorBox){ errorBox.style.display = 'none'; errorBox.textContent=''; }
    // Must have at least one linked pallet and tracking number
    const anyLinked = <?php echo !empty($linkedIds) ? 'true' : 'false'; ?>;
    const tracking = (document.querySelector('input[name="replacement_tracking"]')?.value || '').trim();
    if (!anyLinked || !tracking) { showError('Replacement shipment requires linked pallet(s) and tracking number'); return; }
    statusHidden.value = 'Replacement Shipped';
    btnToShipped.closest('form').submit();
  });

  const btnToClosed = document.getElementById('btn_to_closed');
  if (btnToClosed) btnToClosed.addEventListener('click', () => {
    const errorBox = document.getElementById('inline_error');
    function showError(msg){ if (errorBox){ errorBox.textContent = msg; errorBox.style.display = ''; errorBox.scrollIntoView({behavior:'smooth', block:'center'});} }
    if (errorBox){ errorBox.style.display = 'none'; errorBox.textContent=''; }
    // Closing requires proof of completion file
    const hasProof = !!document.getElementById('proof_upload')?.files?.length || <?php echo !empty($proofPrimary) ? 'true' : 'false'; ?>;
    if (!hasProof) { showError('Proof of completion required to close'); return; }
    statusHidden.value = 'Closed';
    btnToClosed.closest('form').submit();
  });

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

  // Update replacement status for intermediate workflow steps
  async function updateReplacementStatus(newStatus) {
    if (!confirm(`Update replacement status to "${newStatus}"? This will affect all linked replacement pallets.`)) {
      return;
    }
    
    try {
      const response = await fetch('update_replacement_status.php', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
        },
        body: JSON.stringify({
          claim_id: <?php echo (int)$claimId; ?>,
          new_status: newStatus,
          csrf_token: '<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>'
        })
      });
      
      const data = await response.json();
      if (data.success) {
        location.reload(); // Refresh to show updated status
      } else {
        alert('Failed to update status: ' + (data.message || 'Unknown error'));
      }
    } catch (error) {
      alert('Error updating status: ' + error.message);
    }
  }

  // Save estimated delivery date
  window.saveEstimatedDeliveryDate = async function() {
    const newDate = document.getElementById('estimated_delivery_date').value;
    const statusDiv = document.getElementById('delivery_date_status');
    const saveBtn = document.getElementById('save_delivery_btn');
    
    if (statusDiv) statusDiv.innerHTML = '<span style="color:#488C9A;">Saving...</span>';
    if (saveBtn) {
      saveBtn.disabled = true;
      saveBtn.innerHTML = 'Saving...';
    }
    
    try {
      const response = await fetch('update_warranty_estimated_delivery.php', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
        },
        body: JSON.stringify({
          claim_id: <?php echo (int)$claimId; ?>,
          estimated_delivery_date: newDate,
          csrf_token: '<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>'
        })
      });
      
      const data = await response.json();
      if (data.success) {
        if (statusDiv) {
          statusDiv.innerHTML = '<span style="color:#28a745;">✓ Saved successfully</span>';
          setTimeout(() => {
            if (statusDiv) statusDiv.innerHTML = '';
          }, 3000);
        }
        if (saveBtn) {
          saveBtn.innerHTML = '✓ Saved';
          setTimeout(() => {
            if (saveBtn) saveBtn.innerHTML = 'Save';
          }, 2000);
        }
      } else {
        if (statusDiv) statusDiv.innerHTML = '<span style="color:#dc3545;">Failed to save</span>';
        alert('Failed to update estimated delivery date: ' + (data.message || 'Unknown error'));
      }
    } catch (error) {
      if (statusDiv) statusDiv.innerHTML = '<span style="color:#dc3545;">Error</span>';
      alert('Error updating estimated delivery date: ' + error.message);
    } finally {
      if (saveBtn) {
        saveBtn.disabled = false;
        if (saveBtn.innerHTML === 'Saving...') {
          saveBtn.innerHTML = 'Save';
        }
      }
    }
  };

  // File input previews for user feedback
  function renderSelectedFiles(inputEl, previewEl){
    if (!inputEl || !previewEl) return;
    const files = inputEl.files;
    if (!files || files.length === 0) {
      previewEl.style.display = 'none';
      previewEl.innerHTML = '';
      return;
    }
    const pills = Array.from(files).map(f => `<span class=\"file-pill\">${f.name}</span>`).join('');
    previewEl.innerHTML = pills;
    previewEl.style.display = '';
  }
  const uploadDocs = document.getElementById('upload_docs');
  const uploadDocsPreview = document.getElementById('upload_docs_preview');
  if (uploadDocs) uploadDocs.addEventListener('change', () => renderSelectedFiles(uploadDocs, uploadDocsPreview));

  const proofUpload = document.getElementById('proof_upload');
  const proofUploadPreview = document.getElementById('proof_upload_preview');
  if (proofUpload) proofUpload.addEventListener('change', () => renderSelectedFiles(proofUpload, proofUploadPreview));
});
</script>
</body>
</html>


