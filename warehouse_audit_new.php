<?php
/**
 * Start New Warehouse Audit Session
 *
 * Form to configure and kick off a new audit session. Collects warehouse,
 * project, manufacturer, expected counts, wattages, equipment, service fee,
 * and AI vision settings, then POSTs to api/audit/create_session.php.
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
// global_admin needs to pass account_id via ?account_id=N for dropdown scoping
if ($role === 'global_admin') {
    $account_id = isset($_GET['account_id']) ? (int)$_GET['account_id'] : null;
}

$warehouses = [];
$projects = [];
$manufacturers = [];
if ($account_id) {
    $stmt = $conn->prepare("SELECT id, name, city, state FROM warehouses WHERE account_id = ? OR account_id IS NULL ORDER BY name ASC");
    $stmt->bind_param("i", $account_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) { $warehouses[] = $row; }
    $stmt->close();

    $stmt = $conn->prepare("SELECT id, project_name FROM projects WHERE account_id = ? ORDER BY project_name ASC");
    $stmt->bind_param("i", $account_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) { $projects[] = $row; }
    $stmt->close();

    // Manufacturers may be account-scoped or global
    $mfg_acct_col = $conn->query("SHOW COLUMNS FROM manufacturers LIKE 'account_id'");
    $has_mfg_acct = $mfg_acct_col && $mfg_acct_col->num_rows > 0;
    if ($mfg_acct_col) $mfg_acct_col->close();
    if ($has_mfg_acct) {
        $stmt = $conn->prepare("SELECT id, name FROM manufacturers WHERE is_active = 1 AND (account_id = ? OR account_id IS NULL) ORDER BY name ASC");
        $stmt->bind_param("i", $account_id);
    } else {
        $stmt = $conn->prepare("SELECT id, name FROM manufacturers WHERE is_active = 1 ORDER BY name ASC");
    }
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) { $manufacturers[] = $row; }
    $stmt->close();
}

$conn->close();

$default_service_description = "Full Pallet-by-Pallet Inventory Sweep\n"
    . "• Physical onsite inventory verification of each pallet label / ID where reasonably accessible.\n"
    . "• Confirmation of model / wattage / equipment type against the customer-provided inventory schedule.\n"
    . "• Pallet count reconciliation, representative photo documentation, and summary discrepancy report.\n"
    . "• Visual verification of equipment units and identifying tags where applicable.\n"
    . "• Photo documentation and summary findings report.";
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>New Warehouse Audit Session</title>
<link rel="stylesheet" href="portal.css">
<link rel="stylesheet" href="css/warehouse_audit.css">
<link rel="icon" href="pictures/favicon.png" type="image/x-icon">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<style>
.audit-page { max-width: 840px; margin: 0 auto; padding: 24px 16px; }
.audit-header {
    background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%);
    border-radius: 24px; padding: 28px 32px; margin-bottom: 24px;
    box-shadow: 0 8px 32px rgba(0, 0, 0, 0.06);
    border: 1px solid rgba(72, 140, 154, 0.08);
    position: relative; overflow: hidden;
}
.audit-header::before { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 4px; background: linear-gradient(90deg, #488C9A 0%, #293E4C 100%); }
.audit-header h1 { font-size: 1.8rem; font-weight: 700; margin: 0 0 8px 0; color: #293E4C; }
.audit-header p { color: #6c757d; margin: 0; }
.form-card {
    background: white; border-radius: 16px; padding: 28px; box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
    border: 1px solid #e9ecef; margin-bottom: 20px;
}
.form-section-title { font-size: 1.1rem; font-weight: 600; color: #293E4C; margin-bottom: 16px; padding-bottom: 8px; border-bottom: 2px solid #f1f3f4; }
.form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 16px; }
.form-row.single { grid-template-columns: 1fr; }
.form-field label { display: block; font-weight: 500; color: #495057; margin-bottom: 6px; font-size: 0.9rem; }
.form-field input, .form-field select, .form-field textarea {
    width: 100%; padding: 10px 12px; border: 1px solid #ced4da; border-radius: 8px; font-family: inherit; font-size: 0.95rem;
    transition: border-color 0.15s;
}
.form-field input:focus, .form-field select:focus, .form-field textarea:focus { border-color: #488C9A; outline: none; }
.form-field textarea { resize: vertical; min-height: 100px; }
.form-field .helper { font-size: 0.8rem; color: #6c757d; margin-top: 4px; }
/* Dynamic "type-and-add" wattage chip input */
.wattage-chip-container {
    border: 1px solid #ced4da;
    border-radius: 10px;
    padding: 10px;
    background: white;
    transition: border-color 0.15s;
}
.wattage-chip-container:focus-within { border-color: #488C9A; }
.wattage-dynamic-chips { display: flex; flex-wrap: wrap; gap: 6px; }
.wattage-dynamic-chips:not(:empty) { margin-bottom: 8px; }
.wattage-chip-active {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 6px 6px 6px 14px;
    background: linear-gradient(135deg, #488C9A 0%, #3A6E7F 100%);
    color: white;
    border-radius: 18px;
    font-weight: 600;
    font-size: 0.9rem;
    line-height: 1;
}
.wattage-chip-remove {
    background: rgba(255, 255, 255, 0.25);
    border: none;
    color: white;
    width: 22px;
    height: 22px;
    border-radius: 50%;
    cursor: pointer;
    font-size: 1.1rem;
    line-height: 1;
    padding: 0;
    display: flex;
    align-items: center;
    justify-content: center;
}
.wattage-chip-remove:hover { background: rgba(255, 255, 255, 0.45); }
#wattageInput {
    border: none !important;
    outline: none !important;
    width: 100%;
    padding: 6px 4px !important;
    font-family: inherit;
    font-size: 0.95rem;
    background: transparent;
}
.equipment-list { display: flex; flex-direction: column; gap: 12px; }
.equipment-row { display: grid; grid-template-columns: 2fr 2fr 1fr auto; gap: 8px; align-items: center; }
.equipment-row button.remove-equipment { background: #f8d7da; color: #721c24; border: none; padding: 10px; border-radius: 6px; cursor: pointer; }
.btn-add-equipment { background: #e9ecef; color: #495057; border: 1px dashed #adb5bd; padding: 10px; border-radius: 8px; cursor: pointer; font-weight: 500; width: 100%; margin-top: 8px; }
.form-actions { display: flex; justify-content: flex-end; gap: 12px; }
.btn-primary { background: linear-gradient(135deg, #488C9A 0%, #293E4C 100%); color: white; border: none; padding: 14px 28px; border-radius: 12px; font-weight: 600; cursor: pointer; font-size: 1rem; }
.btn-primary:hover { transform: translateY(-1px); box-shadow: 0 4px 12px rgba(72, 140, 154, 0.3); }
.btn-secondary { background: white; color: #495057; border: 1px solid #ced4da; padding: 14px 28px; border-radius: 12px; font-weight: 500; cursor: pointer; font-size: 1rem; text-decoration: none; display: inline-flex; align-items: center; }
.ai-toggle-row { display: flex; align-items: center; gap: 12px; background: #f8f9fa; padding: 14px; border-radius: 10px; margin-top: 12px; }
.ai-toggle-row input[type="checkbox"] { width: 20px; height: 20px; }
.ai-disclosure { font-size: 0.8rem; color: #6c757d; margin-top: 4px; }
.alert-error { background: #f8d7da; color: #721c24; padding: 12px 16px; border-radius: 8px; margin-bottom: 16px; display: none; }
.alert-error.visible { display: block; }
</style>
</head>
<body>
<?php include 'header.php'; ?>

<div class="audit-page">
    <div class="audit-header">
        <h1>Start New Warehouse Audit</h1>
        <p>Configure the session and jump straight into mobile scanning mode.</p>
    </div>

    <div id="formError" class="alert-error"></div>

    <!-- Single form wrapping every section; JS submit via click handler.
         Prevent default submit so Enter in any field won't trigger a native POST. -->
    <form id="auditForm" onsubmit="return false;">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">

    <div class="form-card">
        <h2 class="form-section-title">Customer & Service</h2>
        <div class="form-row">
            <div class="form-field">
                <label for="customer_name">Customer / Job Name</label>
                <input type="text" id="customer_name" name="customer_name" placeholder="e.g. Pine Gate Renewables / Pathward">
            </div>
            <div class="form-field">
                <label for="service_fee_usd">Service Fee (USD)</label>
                <input type="number" step="0.01" min="0" id="service_fee_usd" name="service_fee_usd" placeholder="6500.00">
            </div>
        </div>
        <div class="form-row single">
            <div class="form-field">
                <label for="service_description">Service Description</label>
                <textarea id="service_description" name="service_description"><?= htmlspecialchars($default_service_description) ?></textarea>
                <div class="helper">Shown on the customer PDF report.</div>
            </div>
        </div>
    </div>

    <div class="form-card">
        <h2 class="form-section-title">Location & Project</h2>
        <div class="form-row">
            <div class="form-field">
                <label for="warehouse_id">Warehouse *</label>
                <select id="warehouse_id" name="warehouse_id" required>
                    <option value="">Select a warehouse</option>
                    <?php foreach ($warehouses as $w): ?>
                        <option value="<?= (int)$w['id'] ?>">
                            <?= htmlspecialchars($w['name'] . ($w['city'] ? ' (' . $w['city'] . ($w['state'] ? ', ' . $w['state'] : '') . ')' : '')) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-field">
                <label for="project_id">Project (optional)</label>
                <select id="project_id" name="project_id">
                    <option value="">None — warehouse-only audit</option>
                    <?php foreach ($projects as $p): ?>
                        <option value="<?= (int)$p['id'] ?>"><?= htmlspecialchars($p['project_name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <div class="helper">Leave blank to commit pallets as unassigned warehouse inventory.</div>
            </div>
        </div>
        <div class="form-row single">
            <div class="form-field">
                <label for="manufacturer_id">Primary Manufacturer (for pallets)</label>
                <select id="manufacturer_id" name="manufacturer_id">
                    <option value="">None</option>
                    <?php foreach ($manufacturers as $m): ?>
                        <option value="<?= (int)$m['id'] ?>"><?= htmlspecialchars($m['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <div class="helper">Required when committing to a project (used for module batch creation).</div>
            </div>
        </div>
    </div>

    <div class="form-card">
        <h2 class="form-section-title">Expected Inventory</h2>
        <div class="form-row">
            <div class="form-field">
                <label for="expected_pallet_count">Expected Pallet Count</label>
                <input type="number" min="0" id="expected_pallet_count" name="expected_pallet_count" placeholder="1174">
            </div>
            <div class="form-field">
                <label for="expected_modules_per_pallet">Modules per Pallet</label>
                <input type="number" min="0" id="expected_modules_per_pallet" name="expected_modules_per_pallet" placeholder="36">
                <div class="helper">Default applied to new scans unless the label says otherwise.</div>
            </div>
        </div>
        <div class="form-row single">
            <div class="form-field">
                <label for="wattageInput">Expected Wattages</label>
                <div class="wattage-chip-container">
                    <div id="wattageChips" class="wattage-dynamic-chips"></div>
                    <input type="number"
                           id="wattageInput"
                           inputmode="numeric"
                           min="100"
                           max="2000"
                           placeholder="Type a wattage and press Enter (e.g. 690)">
                </div>
                <div class="helper">Press Enter or comma to add. Tap × on a chip to remove. Leave blank to accept any wattage without flagging.</div>
            </div>
        </div>
    </div>

    <div class="form-card">
        <h2 class="form-section-title">Expected Equipment (non-pallet items)</h2>
        <div id="equipmentList" class="equipment-list"></div>
        <button type="button" id="btnAddEquipment" class="btn-add-equipment">+ Add equipment row</button>
        <div class="helper" style="margin-top: 8px;">For non-pallet items like inverters, transformers, etc. Each row will need visual verification + photos during the audit.</div>
    </div>

    <div class="form-card">
        <h2 class="form-section-title">AI Vision Label Reader (Tier 2)</h2>
        <div class="ai-toggle-row">
            <input type="checkbox" id="ai_vision_enabled" name="ai_vision_enabled" value="1" checked>
            <label for="ai_vision_enabled" style="flex: 1;">
                <strong>Enable AI label reading with Gemini 2.5 Flash</strong>
                <div class="ai-disclosure">When you photograph a label, AI will pre-fill the pallet ID, wattage, and other fields. ~$0.003 per label. Customer label photos transit through Google's API (same path as Sunny). Disable for sensitive customers.</div>
            </label>
        </div>
    </div>

    <div class="form-actions">
        <a href="warehouse_audit_sessions" class="btn-secondary">Cancel</a>
        <button type="button" id="btnStartSession" class="btn-primary">Start Session →</button>
    </div>

    </form>
</div>

<script>
(function () {
    // Dynamic wattage chip input: user types a wattage and presses Enter/comma
    // to add it as a removable chip. Supports unlimited values so future
    // module wattages (705, 710, 725...) don't require a code change.
    var wattageState = [];
    var wattageInput = document.getElementById('wattageInput');
    var wattageChipsContainer = document.getElementById('wattageChips');

    function renderWattageChips() {
        if (!wattageChipsContainer) return;
        wattageChipsContainer.innerHTML = wattageState.map(function (w, i) {
            return '<span class="wattage-chip-active" data-index="' + i + '">' +
                   w + 'W ' +
                   '<button type="button" class="wattage-chip-remove" aria-label="Remove ' + w + 'W">&times;</button>' +
                   '</span>';
        }).join('');
        wattageChipsContainer.querySelectorAll('.wattage-chip-remove').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var parent = btn.closest('.wattage-chip-active');
                if (!parent) return;
                var idx = parseInt(parent.getAttribute('data-index'), 10);
                if (!isNaN(idx)) {
                    wattageState.splice(idx, 1);
                    renderWattageChips();
                }
            });
        });
    }

    function addWattageFromInput() {
        if (!wattageInput) return;
        var raw = wattageInput.value.trim();
        if (!raw) return;
        // Allow comma-separated bulk entry: "690, 695, 700" -> three chips
        var pieces = raw.split(/[\s,]+/).filter(Boolean);
        var added = false;
        pieces.forEach(function (p) {
            var val = parseInt(p, 10);
            if (!val || val < 100 || val > 2000) return;
            if (wattageState.indexOf(val) === -1) {
                wattageState.push(val);
                added = true;
            }
        });
        if (added) {
            wattageState.sort(function (a, b) { return a - b; });
            renderWattageChips();
        }
        wattageInput.value = '';
    }

    if (wattageInput) {
        wattageInput.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' || e.key === ',') {
                e.preventDefault();
                addWattageFromInput();
            } else if (e.key === 'Backspace' && wattageInput.value === '' && wattageState.length > 0) {
                // Convenient backspace-to-remove-last when input is empty
                wattageState.pop();
                renderWattageChips();
            }
        });
        wattageInput.addEventListener('blur', function () {
            if (wattageInput.value.trim()) { addWattageFromInput(); }
        });
    }

    // Equipment rows
    var equipmentList = document.getElementById('equipmentList');
    function addEquipmentRow(type, manufacturer, quantity) {
        var row = document.createElement('div');
        row.className = 'equipment-row';
        row.innerHTML = '<input type="text" placeholder="Type (e.g. MV Inverter)" value="' + (type || '') + '" class="eq-type">' +
                        '<input type="text" placeholder="Manufacturer (e.g. Siemens)" value="' + (manufacturer || '') + '" class="eq-mfg">' +
                        '<input type="number" min="1" placeholder="Qty" value="' + (quantity || 1) + '" class="eq-qty">' +
                        '<button type="button" class="remove-equipment">×</button>';
        row.querySelector('.remove-equipment').addEventListener('click', function () { row.remove(); });
        equipmentList.appendChild(row);
    }
    document.getElementById('btnAddEquipment').addEventListener('click', function () { addEquipmentRow(); });

    // Submit
    document.getElementById('btnStartSession').addEventListener('click', function () {
        var errEl = document.getElementById('formError');
        errEl.classList.remove('visible');

        var warehouseId = document.getElementById('warehouse_id').value;
        if (!warehouseId) {
            errEl.textContent = 'Warehouse is required.';
            errEl.classList.add('visible');
            return;
        }

        // Flush any pending text still in the input
        if (wattageInput && wattageInput.value.trim()) { addWattageFromInput(); }
        var wattages = wattageState.slice();

        var equipment = [];
        document.querySelectorAll('.equipment-row').forEach(function (row) {
            var type = row.querySelector('.eq-type').value.trim();
            var mfg = row.querySelector('.eq-mfg').value.trim();
            var qty = parseInt(row.querySelector('.eq-qty').value, 10) || 1;
            if (type) { equipment.push({ type: type, manufacturer: mfg, quantity: qty }); }
        });

        var fd = new FormData();
        fd.append('csrf_token', '<?= htmlspecialchars($_SESSION['csrf_token']) ?>');
        fd.append('warehouse_id', warehouseId);
        fd.append('project_id', document.getElementById('project_id').value || '');
        fd.append('manufacturer_id', document.getElementById('manufacturer_id').value || '');
        fd.append('customer_name', document.getElementById('customer_name').value || '');
        fd.append('service_description', document.getElementById('service_description').value || '');
        fd.append('service_fee_usd', document.getElementById('service_fee_usd').value || '');
        fd.append('expected_pallet_count', document.getElementById('expected_pallet_count').value || '');
        fd.append('expected_modules_per_pallet', document.getElementById('expected_modules_per_pallet').value || '');
        fd.append('expected_wattages', JSON.stringify(wattages));
        fd.append('expected_equipment', JSON.stringify(equipment));
        fd.append('ai_vision_enabled', document.getElementById('ai_vision_enabled').checked ? '1' : '0');

        var btn = document.getElementById('btnStartSession');
        btn.disabled = true; btn.textContent = 'Creating session…';

        fetch('api/audit/create_session.php', { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data.success) {
                    errEl.textContent = data.error || 'Failed to create session.';
                    errEl.classList.add('visible');
                    btn.disabled = false; btn.textContent = 'Start Session →';
                    return;
                }
                window.location.href = 'warehouse_audit_session?s=' + data.session_id;
            })
            .catch(function (err) {
                errEl.textContent = 'Network error: ' + err.message;
                errEl.classList.add('visible');
                btn.disabled = false; btn.textContent = 'Start Session →';
            });
    });
})();
</script>
</body>
</html>
