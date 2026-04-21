<?php
/**
 * Live Warehouse Audit Session (mobile-first)
 *
 * The actual scanning page used on the phone. Hydrates session state from
 * api/audit/session_state.php, provides three scanning tiers (barcode, AI
 * vision, manual entry), photo capture, equipment check tab, discrepancy
 * panel, and a Review/Commit entry point.
 *
 * All heavy lifting lives in /js/warehouse_audit_*.js; this file is the
 * DOM scaffold + PHP-provided bootstrap data.
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

$session_id = isset($_GET['s']) ? (int)$_GET['s'] : 0;
if (!$session_id) {
    header("Location: warehouse_audit_sessions");
    exit();
}

$conn = getDBConnection();
if (!$conn) { die("Database connection failed"); }

try {
    $session = audit_require_session_access($conn, $session_id, $user_id, $role);
} catch (Throwable $e) {
    http_response_code(403);
    die("Access denied: " . htmlspecialchars($e->getMessage()));
}

// Hydrate initial state for fast first paint
$state = audit_get_session_state($conn, $session_id);

// Warehouse details for display
$stmt = $conn->prepare("SELECT name, city, state FROM warehouses WHERE id = ? LIMIT 1");
$stmt->bind_param("i", $session['warehouse_id']);
$stmt->execute();
$warehouse = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Project name for display (if project-scoped)
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

$expected_wattages = is_array($session['expected_wattages']) ? $session['expected_wattages'] : [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<meta name="theme-color" content="#293E4C">
<title>Audit: <?= htmlspecialchars($session['session_code']) ?></title>
<link rel="stylesheet" href="portal.css">
<link rel="stylesheet" href="css/warehouse_audit.css">
<link rel="icon" href="pictures/favicon.png" type="image/x-icon">
</head>
<body class="audit-session-body">

<header class="audit-top-bar">
    <button class="back-btn" onclick="window.location.href='warehouse_audit_sessions'" aria-label="Back">‹</button>
    <div class="audit-top-info">
        <div class="audit-session-code"><?= htmlspecialchars($session['session_code']) ?></div>
        <div class="audit-session-sub">
            <?= htmlspecialchars($warehouse['name'] ?? 'Warehouse') ?><?php
            if (!empty($warehouse['city'])) echo ' · ' . htmlspecialchars($warehouse['city'] . ($warehouse['state'] ? ', ' . $warehouse['state'] : ''));
            ?>
        </div>
    </div>
    <div id="offlinePill" class="offline-pill synced" title="Sync status">● Synced</div>
</header>

<section class="counter-bar">
    <div class="counter-stat">
        <div class="counter-num" id="counterVerified"><?= (int)($state['reconciliation']['verified_count'] ?? 0) ?></div>
        <div class="counter-label">Verified</div>
    </div>
    <div class="counter-sep"></div>
    <div class="counter-stat">
        <div class="counter-num counter-num-total" id="counterExpected">
            <?= (int)($state['reconciliation']['expected_count'] ?? 0) ?: '–' ?>
        </div>
        <div class="counter-label">Expected</div>
    </div>
    <div class="counter-sep"></div>
    <div class="counter-stat">
        <div class="counter-num counter-num-warn" id="counterDiscrepancy"><?= (int)($state['reconciliation']['discrepancy_count'] ?? 0) ?></div>
        <div class="counter-label">Flags</div>
    </div>
</section>

<div class="progress-bar-wrap">
    <div class="progress-bar" id="progressBar" style="width: 0%;"></div>
</div>

<main class="audit-main">

    <!-- Tab bar -->
    <nav class="audit-tabs" role="tablist">
        <button class="audit-tab active" data-tab="scan" role="tab">Scan</button>
        <button class="audit-tab" data-tab="list" role="tab">List <span id="listBadge" class="tab-badge">0</span></button>
        <button class="audit-tab" data-tab="equipment" role="tab">Equipment <span id="equipmentBadge" class="tab-badge">0</span></button>
    </nav>

    <!-- Scan tab: scanner viewport + action buttons -->
    <section class="tab-panel tab-scan active" data-panel="scan">
        <div id="scannerContainer" class="scanner-container">
            <div id="scannerViewport" class="scanner-viewport">
                <div class="scanner-placeholder">
                    <div class="scanner-icon">⟨ · ⟩</div>
                    <p>Tap a button below to scan, photograph, or type a pallet label.</p>
                </div>
            </div>
        </div>

        <div class="action-buttons">
            <button class="action-btn action-scan" id="btnScanBarcode">
                <span class="action-icon">📷</span>
                <span>Scan Barcode / QR</span>
            </button>
            <button class="action-btn action-ai" id="btnScanAi" <?= empty($session['ai_vision_enabled']) ? 'disabled' : '' ?>>
                <span class="action-icon">✨</span>
                <span>Read Label with AI</span>
            </button>
            <button class="action-btn action-manual" id="btnManualEntry">
                <span class="action-icon">⌨</span>
                <span>Enter Manually</span>
            </button>
        </div>

        <div class="quick-wattage-bar">
            <div class="quick-wattage-label">Quick wattage:</div>
            <?php foreach ($expected_wattages as $w): ?>
                <button class="quick-wattage-chip" data-wattage="<?= (int)$w ?>"><?= (int)$w ?>W</button>
            <?php endforeach; ?>
        </div>

        <?php if (!empty($session['ai_vision_enabled'])): ?>
        <div class="ai-usage-pill" id="aiUsagePill" title="AI calls used this session">
            <span class="ai-icon">✨</span>
            <span id="aiCallCount"><?= (int)$session['ai_vision_call_count'] ?></span> / <?= (int)$session['ai_vision_call_cap'] ?> AI reads
        </div>
        <?php endif; ?>
    </section>

    <!-- List tab: live scan list -->
    <section class="tab-panel tab-list" data-panel="list">
        <div class="list-header">
            <input type="text" id="scanSearchInput" class="list-search" placeholder="Search pallet ID or notes…">
            <select id="scanFilter" class="list-filter">
                <option value="all">All scans</option>
                <option value="verified">Verified only</option>
                <option value="discrepancy">Discrepancies</option>
            </select>
        </div>
        <div id="scanList" class="scan-list"></div>
    </section>

    <!-- Equipment tab -->
    <section class="tab-panel tab-equipment" data-panel="equipment">
        <div id="equipmentList" class="equipment-list-panel"></div>
    </section>

</main>

<!-- Bottom CTA bar -->
<footer class="audit-bottom-bar">
    <button class="bottom-cta" id="btnGoToReview">Review & Commit →</button>
</footer>

<!-- Manual entry modal -->
<div class="modal-overlay" id="manualModal" aria-hidden="true">
    <div class="modal">
        <div class="modal-header">
            <h3>Enter Pallet Manually</h3>
            <button class="modal-close" aria-label="Close" onclick="AuditSession.closeModal('manualModal')">×</button>
        </div>
        <div class="modal-body">
            <div class="form-field">
                <label for="m_raw_value">Pallet ID / Serial</label>
                <input type="text" id="m_raw_value" autocomplete="off" autofocus>
            </div>
            <div class="form-row">
                <div class="form-field">
                    <label for="m_wattage">Wattage (W)</label>
                    <input type="number" id="m_wattage" inputmode="numeric">
                </div>
                <div class="form-field">
                    <label for="m_quantity">Quantity (modules)</label>
                    <input type="number" id="m_quantity" inputmode="numeric" value="<?= (int)($session['expected_modules_per_pallet'] ?? 0) ?: '' ?>">
                </div>
            </div>
            <div class="form-field">
                <label for="m_notes">Notes (optional)</label>
                <textarea id="m_notes" rows="2"></textarea>
            </div>
            <label class="checkbox-row">
                <input type="checkbox" id="m_label_unreadable"> Label is damaged or unreadable
            </label>
        </div>
        <div class="modal-footer">
            <button class="btn-secondary" onclick="AuditSession.closeModal('manualModal')">Cancel</button>
            <button class="btn-primary" id="btnSubmitManual">Save Scan</button>
        </div>
    </div>
</div>

<!-- Photo capture modal -->
<div class="modal-overlay" id="photoModal" aria-hidden="true">
    <div class="modal">
        <div class="modal-header">
            <h3>Attach Photo</h3>
            <button class="modal-close" aria-label="Close" onclick="AuditSession.closeModal('photoModal')">×</button>
        </div>
        <div class="modal-body">
            <div class="form-field">
                <label for="p_role">Photo type</label>
                <select id="p_role">
                    <option value="label">Label</option>
                    <option value="pallet_overview">Pallet Overview</option>
                    <option value="damage">Damage</option>
                    <option value="context">Context / General</option>
                </select>
            </div>
            <div class="form-field">
                <label for="p_file">Photo</label>
                <input type="file" id="p_file" accept="image/*" capture="environment">
            </div>
            <div class="form-field">
                <label for="p_caption">Caption (optional)</label>
                <input type="text" id="p_caption">
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn-secondary" onclick="AuditSession.closeModal('photoModal')">Cancel</button>
            <button class="btn-primary" id="btnSubmitPhoto">Upload</button>
        </div>
    </div>
</div>

<!-- Equipment edit modal -->
<div class="modal-overlay" id="equipmentModal" aria-hidden="true">
    <div class="modal">
        <div class="modal-header">
            <h3>Equipment Check</h3>
            <button class="modal-close" aria-label="Close" onclick="AuditSession.closeModal('equipmentModal')">×</button>
        </div>
        <div class="modal-body">
            <input type="hidden" id="eq_id">
            <div class="form-row">
                <div class="form-field">
                    <label for="eq_type">Type</label>
                    <input type="text" id="eq_type">
                </div>
                <div class="form-field">
                    <label for="eq_mfg">Manufacturer</label>
                    <input type="text" id="eq_mfg">
                </div>
            </div>
            <div class="form-row">
                <div class="form-field">
                    <label for="eq_model">Model</label>
                    <input type="text" id="eq_model">
                </div>
                <div class="form-field">
                    <label for="eq_serial">Serial</label>
                    <input type="text" id="eq_serial">
                </div>
            </div>
            <div class="form-field">
                <label for="eq_tag">Asset tag</label>
                <input type="text" id="eq_tag">
            </div>
            <div class="form-field">
                <label for="eq_status">Status</label>
                <select id="eq_status">
                    <option value="not_verified">Not verified</option>
                    <option value="verified">Verified ✓</option>
                    <option value="missing">Missing</option>
                    <option value="damaged">Damaged</option>
                    <option value="wrong_model">Wrong model</option>
                </select>
            </div>
            <div class="form-field">
                <label for="eq_notes">Notes</label>
                <textarea id="eq_notes" rows="2"></textarea>
            </div>

            <div id="eqExistingPhotos" class="eq-existing-photos"></div>

            <div class="form-row">
                <div class="form-field">
                    <label for="eq_tag_photo">Tag photo</label>
                    <input type="file" id="eq_tag_photo" accept="image/*" capture="environment">
                </div>
                <div class="form-field">
                    <label for="eq_overview_photo">Overview photo</label>
                    <input type="file" id="eq_overview_photo" accept="image/*" capture="environment">
                </div>
            </div>
            <div class="helper" style="font-size: 0.75rem; color: #6c757d; margin-top: -6px;">
                Photos upload automatically after you save. Tag photo captures the identifying label; overview shows the whole unit.
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn-secondary" onclick="AuditSession.closeModal('equipmentModal')">Cancel</button>
            <button class="btn-primary" id="btnSubmitEquipment">Save</button>
        </div>
    </div>
</div>

<!-- Bootstrap data for JS -->
<script>
window.AUDIT_BOOTSTRAP = <?= json_encode([
    'session_id'   => $session_id,
    'session_code' => $session['session_code'],
    'csrf_token'   => $_SESSION['csrf_token'],
    'expected_wattages' => $expected_wattages,
    'expected_modules_per_pallet' => (int)($session['expected_modules_per_pallet'] ?? 0),
    'ai_vision_enabled' => (bool)$session['ai_vision_enabled'],
    'ai_vision_call_count' => (int)$session['ai_vision_call_count'],
    'ai_vision_call_cap'   => (int)$session['ai_vision_call_cap'],
    'initial_state' => $state,
    'customer_name' => $session['customer_name'],
    'project_name'  => $project_name,
]) ?>;
</script>

<!-- Load order matters: offline queue, then scanner, then page controller -->
<script src="js/warehouse_audit_offline.js"></script>
<script src="vendor/html5-qrcode/html5-qrcode.min.js"></script>
<script src="js/warehouse_audit_scanner.js"></script>
<script src="js/warehouse_audit_session.js"></script>

</body>
</html>
