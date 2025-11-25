<?php
session_name("logistics_session");
session_start();

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['admin','global_admin','user'])) {
    header("Location: unauthorized");
    exit();
}

require_once '../config.php';
$conn = getDBConnection();
if (!$conn) {
    die('Database connection failed.');
}

$userId = (int)$_SESSION['user_id'];
$role   = $_SESSION['role'];
$scenarioIdParam = isset($_GET['scenario_id']) ? (int)$_GET['scenario_id'] : 0;

// Resolve account scope
$accountId = null;
$accounts = [];
if ($role === 'global_admin') {
    $resAcc = $conn->query("SELECT id, name FROM customer_accounts ORDER BY name ASC");
    if ($resAcc) {
        while ($row = $resAcc->fetch_assoc()) { $accounts[] = $row; }
    }
    $accountId = isset($_GET['account_id']) ? (int)$_GET['account_id'] : null;
    if (!$accountId && !empty($accounts)) {
        $accountId = (int)$accounts[0]['id'];
    }
} else {
    $stmt = $conn->prepare("SELECT account_id FROM customer_account_users WHERE user_id = ? LIMIT 1");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $stmt->bind_result($acct);
    if ($stmt->fetch()) { $accountId = (int)$acct; }
    $stmt->close();
}

if (!$accountId) {
    die('No account assigned.');
}

// Fetch projects for this account
$projects = [];
$stmtProj = $conn->prepare("SELECT id, project_name FROM projects WHERE account_id = ? ORDER BY project_name ASC");
$stmtProj->bind_param("i", $accountId);
$stmtProj->execute();
$resProj = $stmtProj->get_result();
while ($row = $resProj->fetch_assoc()) { $projects[] = $row; }
$stmtProj->close();

// Warehouses (not account-bound)
$warehouses = [];
$resWh = $conn->query("SELECT id, name, city, state FROM warehouses ORDER BY name ASC");
if ($resWh) {
    while ($row = $resWh->fetch_assoc()) { $warehouses[] = $row; }
}

$statuses = [
    'At Manufacturer',
    'On Water',
    'Cleared Customs',
    'In Transit to Warehouse',
    'In Warehouse',
    'In Transit to Project',
    'Delivered to Project',
    'Damaged'
];
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Module Allocations</title>
    <link rel="stylesheet" href="portal.css?v=1">
    <style>
        :root {
            --ink: #0f172a;
            --muted: #64748b;
            --border: #e2e8f0;
            --bg: #f8fafc;
            --card: #ffffff;
            --accent: #0ea5e9;
            --accent-strong: #0284c7;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
        }
        body { background: var(--bg); color: var(--ink); font-family: 'Inter', system-ui, -apple-system, sans-serif; }
        .alloc-shell { display: grid; grid-template-columns: 300px 1fr 360px; gap: 18px; padding: 18px; }
        @media (max-width: 1280px) { .alloc-shell { grid-template-columns: 280px 1fr; } .alloc-right { grid-column: span 2; } }
        @media (max-width: 900px) { .alloc-shell { grid-template-columns: 1fr; } .alloc-left, .alloc-right { position: relative; top: auto; } }
        .alloc-left { position: sticky; top: 90px; align-self: start; background: var(--card); border:1px solid var(--border); border-radius: 14px; padding: 16px; box-shadow: 0 8px 30px rgba(15,23,42,0.06); }
        .alloc-main { min-height: 70vh; }
        .alloc-right { position: sticky; top: 90px; align-self: start; background: var(--card); border:1px solid var(--border); border-radius: 14px; padding: 16px; box-shadow: 0 8px 30px rgba(15,23,42,0.06); }
        .pill { display: inline-flex; align-items: center; gap: 6px; padding: 6px 10px; border-radius: 999px; font-size: 12px; font-weight: 600; border:1px solid var(--border); color: var(--muted); }
        .pill.status { border: none; color: #fff; }
        .pill.status.at-manufacturer { background:#6b7280; }
        .pill.status.on-water { background:#0ea5e9; }
        .pill.status.cleared-customs { background:#10b981; }
        .pill.status.in-transit-warehouse { background:#f59e0b; }
        .pill.status.in-warehouse { background:#2563eb; }
        .pill.status.in-transit-project { background:#a855f7; }
        .pill.status.delivered-to-project { background:#16a34a; }
        .pill.status.damaged { background:#ef4444; }
        .card { background: var(--card); border:1px solid var(--border); border-radius: 14px; padding: 16px; box-shadow: 0 8px 30px rgba(15,23,42,0.06); }
        .metrics { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px,1fr)); gap: 10px; margin-bottom: 14px; }
        .metric { padding: 14px; border-radius: 12px; border:1px solid var(--border); background: linear-gradient(180deg,#fff,#f8fafc); }
        .metric h4 { margin: 0; font-size: 13px; color: var(--muted); }
        .metric .big { font-size: 22px; font-weight: 700; margin-top: 6px; }
        .list { display: flex; flex-direction: column; gap: 10px; }
        .row { background: var(--card); border:1px solid var(--border); border-radius: 12px; padding: 12px; display: grid; grid-template-columns: 30px 1fr 1fr 1fr 1fr; gap: 12px; align-items: center; }
        .row:hover { border-color: #cbd5e1; box-shadow: 0 4px 12px rgba(15,23,42,0.06); }
        .row .title { font-weight: 700; }
        .muted { color: var(--muted); font-size: 13px; }
        .badge { display: inline-flex; align-items: center; padding: 4px 8px; border-radius: 10px; background:#e0f2fe; color:#075985; font-weight: 600; font-size: 12px; }
        .bucket-card { border:1px solid var(--border); border-radius: 12px; padding: 12px; background: #fff; }
        .btn { display:inline-flex; align-items: center; justify-content: center; gap:6px; border-radius: 10px; padding: 10px 14px; border:1px solid transparent; font-weight: 700; cursor: pointer; }
        .btn.primary { background: var(--accent); color:#fff; border-color: var(--accent); }
        .btn.primary:hover { background: var(--accent-strong); }
        .btn.ghost { background:#fff; border-color: var(--border); color: var(--ink); }
        .btn.ghost:hover { border-color:#cbd5e1; }
        .btn.inline { padding: 6px 10px; font-weight:600; }
        .chips { display:flex; flex-wrap: wrap; gap: 6px; margin: 8px 0 0; }
        .chip { background:#e2e8f0; color:#0f172a; padding:6px 10px; border-radius: 999px; font-size:12px; cursor:pointer; }
        .chip.active { background: var(--accent); color:#fff; }
        .scenario-move { background:#f8fafc; border:1px dashed #cbd5e1; padding:10px; border-radius:10px; font-size:13px; }
        .sticky-top { position: sticky; top: 0; background: var(--card); z-index: 5; padding: 10px 0; }
        .input, select { width:100%; padding: 10px 12px; border-radius: 10px; border:1px solid var(--border); background:#fff; }
        .input:focus, select:focus { outline:2px solid var(--accent); border-color: var(--accent); }
        .row.scenario-applied { border-color: var(--accent); box-shadow: 0 4px 16px rgba(14,165,233,0.15); }
        .selection-bar { position: sticky; bottom: 0; left:0; right:0; background:#0f172a; color:#fff; display:flex; align-items:center; justify-content: space-between; padding:12px 14px; border-radius:12px; box-shadow:0 8px 30px rgba(0,0,0,0.28); }
        .selection-bar .actions { display:flex; gap:10px; }
        .small-muted { color: var(--muted); font-size: 12px; }
    </style>
</head>
<body>
<?php include 'header.php'; ?>
<div class="alloc-shell">
    <aside class="alloc-left">
        <div style="display:flex; align-items:center; justify-content: space-between; gap:8px; margin-bottom:10px;">
            <h3 style="margin:0; font-size:16px;">Filters</h3>
            <button class="btn ghost inline" id="resetFilters">Reset</button>
        </div>
        <?php if ($role === 'global_admin'): ?>
            <label class="small-muted">Account</label>
            <select id="accountSelect" class="input" style="margin-bottom:10px;">
                <?php foreach ($accounts as $acc): ?>
                    <option value="<?php echo $acc['id']; ?>" <?php echo ($acc['id']==$accountId?'selected':''); ?>><?php echo htmlspecialchars($acc['name']); ?></option>
                <?php endforeach; ?>
            </select>
        <?php endif; ?>
        <label class="small-muted">Project</label>
        <select id="projectFilter" class="input" style="margin-bottom:10px;">
            <option value="">All projects</option>
            <option value="unassigned">Unassigned / Storage</option>
            <?php foreach ($projects as $p): ?>
                <option value="<?php echo $p['id']; ?>"><?php echo htmlspecialchars($p['project_name']); ?></option>
            <?php endforeach; ?>
        </select>

        <label class="small-muted">Status</label>
        <select id="statusFilter" class="input" style="margin-bottom:10px;">
            <option value="">All statuses</option>
            <?php foreach ($statuses as $s): ?>
                <option value="<?php echo $s; ?>"><?php echo $s; ?></option>
            <?php endforeach; ?>
        </select>

        <label class="small-muted">Warehouse</label>
        <select id="warehouseFilter" class="input" style="margin-bottom:10px;">
            <option value="">All warehouses</option>
            <?php foreach ($warehouses as $w): ?>
                <option value="<?php echo $w['id']; ?>"><?php echo htmlspecialchars($w['name']); ?></option>
            <?php endforeach; ?>
        </select>

        <label class="small-muted">Search pallet / project / warehouse</label>
        <input id="searchInput" class="input" type="text" placeholder="Search" />

        <div style="margin-top:12px; display:flex; flex-direction:column; gap:8px;">
            <label style="display:flex; align-items:center; gap:8px; font-size:13px; color:var(--muted);">
                <input type="checkbox" id="hideDelivered" /> Hide delivered
            </label>
            <label style="display:flex; align-items:center; gap:8px; font-size:13px; color:var(--muted);">
                <input type="checkbox" id="hideDamaged" /> Hide damaged
            </label>
        </div>
    </aside>

    <main class="alloc-main">
        <div style="display:flex; align-items:center; justify-content: space-between; gap:10px; margin-bottom:14px;">
            <div>
                <div class="small-muted">Module Allocations Sandbox</div>
                <h1 style="margin:4px 0 0;">Module Allocations</h1>
                <div class="small-muted">View all pallets across projects and stage reassignments before requesting approval.</div>
            </div>
            <div style="display:flex; gap:8px;">
                <button class="btn ghost" id="newScenarioBtn">New scenario</button>
                <button class="btn primary" id="saveScenarioBtn">Save scenario</button>
                <button class="btn" style="background:#16a34a; color:#fff;" id="requestScenarioBtn">Request enactment</button>
            </div>
        </div>

        <div class="metrics" id="metricBar"></div>

        <div class="card" style="margin-bottom:12px;">
            <div style="display:flex; align-items:center; justify-content: space-between; gap:10px; margin-bottom:6px;">
                <div style="display:flex; align-items:center; gap:8px;">
                    <strong>Project buckets</strong>
                    <span class="small-muted">Top destinations by pallet count</span>
                </div>
                <span class="small-muted" id="lastUpdated"></span>
            </div>
            <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(180px,1fr)); gap:8px;" id="bucketCards"></div>
        </div>

        <div class="card" style="padding:0;">
            <div class="sticky-top" style="padding:12px 16px; border-bottom:1px solid var(--border); display:grid; grid-template-columns:30px 1fr 1fr 1fr 1fr; gap:12px; font-weight:700;">
                <span></span>
                <span>Pallet</span>
                <span>Assigned project</span>
                <span>Status & location</span>
                <span>Wattage / Qty</span>
            </div>
            <div id="list" class="list" style="padding:12px;"></div>
            <div id="listFooter" style="padding:12px; text-align:center; color:var(--muted);"></div>
        </div>
    </main>

    <aside class="alloc-right" id="scenarioPanel">
        <div style="display:flex; align-items:center; justify-content: space-between; gap:8px;">
            <div>
                <div class="small-muted">Active scenario</div>
                <h3 id="scenarioName" style="margin:4px 0 0;">Draft scenario</h3>
            </div>
            <span class="pill" id="scenarioStatus">Draft</span>
        </div>
        <div class="small-muted" style="margin:6px 0 12px;">Track pallet moves without changing live data. Save to revisit; request to notify admins.</div>
        <div id="scenarioMoves" style="display:flex; flex-direction:column; gap:8px;"></div>
        <hr style="margin:14px 0; border:none; border-top:1px solid var(--border);">
        <div>
            <label class="small-muted">Scenario name</label>
            <input id="scenarioNameInput" class="input" type="text" placeholder="e.g., Shift 5MW to Solar Farm 1" style="margin-bottom:10px;" />
            <label class="small-muted">Notes</label>
            <textarea id="scenarioDescInput" class="input" style="height:80px; resize:vertical;"></textarea>
        </div>
        <hr style="margin:14px 0; border:none; border-top:1px solid var(--border);">
        <div>
            <div style="display:flex; align-items:center; justify-content: space-between;">
                <strong>Saved scenarios</strong>
                <button class="btn ghost inline" id="refreshScenarios">Refresh</button>
            </div>
            <div id="scenarioList" style="display:flex; flex-direction:column; gap:8px; margin-top:10px;"></div>
        </div>
    </aside>
</div>

<div id="selectionBar" class="selection-bar" style="display:none;">
    <div><strong id="selectedCount">0</strong> selected</div>
    <div class="actions">
        <select id="bulkProject" class="input" style="width:200px;">
            <option value="">Assign to…</option>
            <option value="unassigned">Unassigned / Storage</option>
            <?php foreach ($projects as $p): ?>
                <option value="<?php echo $p['id']; ?>"><?php echo htmlspecialchars($p['project_name']); ?></option>
            <?php endforeach; ?>
        </select>
        <button class="btn primary" id="applyBulk">Apply to scenario</button>
    </div>
</div>

<script>
(() => {
    const accountId = <?php echo (int)$accountId; ?>;
    const scenarioParam = <?php echo (int)$scenarioIdParam; ?>;
    const projects = <?php echo json_encode($projects); ?>;
    const statuses = <?php echo json_encode($statuses); ?>;
    let currentAccountId = accountId;
    let items = [];
    let itemCache = new Map();
    let loading = false;
    let offset = 0;
    let total = 0;
    const limit = 60;
    const selections = new Set();
    const totals = { by_status: [], by_project: [] };
    let scenario = {
        id: null,
        name: 'Draft scenario',
        description: '',
        status: 'draft',
        moves: {},
        base_snapshot_hash: null
    };

    const els = {
        list: document.getElementById('list'),
        listFooter: document.getElementById('listFooter'),
        metricBar: document.getElementById('metricBar'),
        bucketCards: document.getElementById('bucketCards'),
        lastUpdated: document.getElementById('lastUpdated'),
        scenarioName: document.getElementById('scenarioName'),
        scenarioStatus: document.getElementById('scenarioStatus'),
        scenarioMoves: document.getElementById('scenarioMoves'),
        scenarioNameInput: document.getElementById('scenarioNameInput'),
        scenarioDescInput: document.getElementById('scenarioDescInput'),
        scenarioList: document.getElementById('scenarioList'),
        selectionBar: document.getElementById('selectionBar'),
        selectedCount: document.getElementById('selectedCount'),
        bulkProject: document.getElementById('bulkProject'),
        projectFilter: document.getElementById('projectFilter'),
        statusFilter: document.getElementById('statusFilter'),
        warehouseFilter: document.getElementById('warehouseFilter'),
        searchInput: document.getElementById('searchInput'),
        hideDelivered: document.getElementById('hideDelivered'),
        hideDamaged: document.getElementById('hideDamaged'),
        accountSelect: document.getElementById('accountSelect')
    };

    // Seed scenario form fields
    if (els.scenarioNameInput) els.scenarioNameInput.value = scenario.name;
    if (els.scenarioDescInput) els.scenarioDescInput.value = scenario.description;

    const statusClass = (text) => text.toLowerCase().replace(/\s+/g,'-');

    function renderRow(row) {
        const wrapper = document.createElement('div');
        wrapper.className = 'row';
        if (scenario.moves[row.id]) wrapper.classList.add('scenario-applied');

        const cb = document.createElement('input');
        cb.type = 'checkbox';
        cb.checked = selections.has(row.id);
        cb.addEventListener('change', () => toggleSelect(row.id));
        const cCol = document.createElement('div');
        cCol.appendChild(cb);

        const title = document.createElement('div');
        title.innerHTML = `<div class="title">${row.pallet_identifier || 'Pallet ' + row.id}</div><div class="muted">${row.manufacturer || 'Unknown manufacturer'}</div>`;

        const project = document.createElement('div');
        const projLabel = row.assigned_project_name || 'Unassigned';
        project.innerHTML = `<div>${projLabel}</div><div class="muted">Current: ${row.current_project_name || '—'}</div>`;

        const status = document.createElement('div');
        const pill = `<span class="pill status ${statusClass(row.status)}">${row.status}</span>`;
        status.innerHTML = `${pill}<div class="muted">${row.warehouse_name || 'No warehouse'}</div>`;

        const watts = document.createElement('div');
        watts.innerHTML = `<div><strong>${row.wattage}W</strong> × ${row.quantity}</div><div class="muted">${(row.wattage * row.quantity / 1000).toFixed(1)} kW</div>`;

        if (scenario.moves[row.id]) {
            const move = scenario.moves[row.id];
            const dest = move.to_project_id ? (projects.find(p => p.id == move.to_project_id)?.project_name || 'Project '+move.to_project_id) : 'Unassigned';
            const moveEl = document.createElement('div');
            moveEl.className = 'scenario-move';
            moveEl.textContent = `Scenario: move to ${dest}`;
            watts.appendChild(moveEl);
        }

        wrapper.appendChild(cCol);
        wrapper.appendChild(title);
        wrapper.appendChild(project);
        wrapper.appendChild(status);
        wrapper.appendChild(watts);
        return wrapper;
    }

    function updateSelectionBar() {
        const count = selections.size;
        els.selectedCount.textContent = count;
        els.selectionBar.style.display = count > 0 ? 'flex' : 'none';
    }

    function toggleSelect(id) {
        if (selections.has(id)) selections.delete(id); else selections.add(id);
        updateSelectionBar();
    }

    function renderList() {
        els.list.innerHTML = '';
        const fragment = document.createDocumentFragment();
        items.forEach(item => fragment.appendChild(renderRow(item)));
        els.list.appendChild(fragment);
        if (loading) {
            els.listFooter.textContent = 'Loading…';
        } else if (items.length >= total) {
            els.listFooter.textContent = `${total} pallets`;
        } else {
            els.listFooter.textContent = `Showing ${items.length} of ${total} (scroll for more)`;
        }
    }

    function renderMetrics() {
        const statusTotals = totals.by_status;
        const cards = [
            { label:'Total pallets', value: total },
            { label:'In warehouse', value: statusTotals.find(s=>s.status==='In Warehouse')?.pallets || 0 },
            { label:'In transit', value: (statusTotals.find(s=>s.status==='In Transit to Warehouse')?.pallets||0) + (statusTotals.find(s=>s.status==='In Transit to Project')?.pallets||0) },
            { label:'Delivered', value: statusTotals.find(s=>s.status==='Delivered to Project')?.pallets || 0 },
        ];
        els.metricBar.innerHTML = cards.map(c=>`<div class="metric"><h4>${c.label}</h4><div class="big">${c.value}</div></div>`).join('');
    }

    function renderBuckets() {
        const sorted = [...totals.by_project].sort((a,b)=>b.pallets - a.pallets).slice(0,8);
        els.bucketCards.innerHTML = sorted.map(b=>`<div class="bucket-card"><div class="title" style="font-size:14px;">${b.project_name}</div><div class="muted">${b.pallets} pallets • ${b.modules} modules</div></div>`).join('');
    }

    async function fetchPallets(reset=false) {
        if (loading) return;
        loading = true;
        if (reset) { offset = 0; items = []; selections.clear(); updateSelectionBar(); }
        renderList();
        const params = new URLSearchParams();
        params.set('limit', limit);
        params.set('offset', offset);
        params.set('include_totals', reset ? '1':'0');
        params.set('account_id', currentAccountId);
        if (els.projectFilter.value) params.set('project_id', els.projectFilter.value);
        if (els.statusFilter.value) params.set('status', els.statusFilter.value);
        if (els.warehouseFilter.value) params.set('warehouse_id', els.warehouseFilter.value);
        if (els.searchInput.value) params.set('search', els.searchInput.value.trim());
        if (els.hideDelivered.checked) params.set('hide_delivered','1');
        if (els.hideDamaged.checked) params.set('hide_damaged','1');

        const resp = await fetch(`api/module_allocations_data.php?${params.toString()}`);
        const data = await resp.json();
        if (resp.ok) {
            offset += data.pagination.limit;
            total = data.pagination.total;
            items = reset ? data.items : items.concat(data.items);
            data.items.forEach(i => itemCache.set(i.id, i));
            if (data.totals) {
                totals.by_status = data.totals.by_status || [];
                totals.by_project = data.totals.by_project || [];
                renderMetrics();
                renderBuckets();
                els.lastUpdated.textContent = new Date().toLocaleTimeString();
            }
        }
        loading = false;
        renderList();
    }

    function applyBulk() {
        const target = els.bulkProject.value;
        if (!target) return alert('Select a destination project.');
        selections.forEach(id => {
            const base = itemCache.get(id);
            if (!base) return;
            scenario.moves[id] = {
                inventory_pallet_id: id,
                from_project_id: base.assigned_project_id,
                to_project_id: target === 'unassigned' ? null : parseInt(target, 10),
                from_status: base.status,
                to_status: base.status,
                from_warehouse_id: base.current_warehouse_id,
                to_warehouse_id: base.current_warehouse_id,
                quantity: base.quantity
            };
        });
        selections.clear();
        updateSelectionBar();
        renderList();
        renderScenarioMoves();
    }

    function renderScenarioMoves() {
        const entries = Object.values(scenario.moves);
        if (!entries.length) {
            els.scenarioMoves.innerHTML = '<div class="muted">No pallet moves yet. Select pallets and apply a destination to stage changes.</div>';
            return;
        }
        const fragment = document.createDocumentFragment();
        entries.slice(0,10).forEach(m => {
            const dest = m.to_project_id ? (projects.find(p=>p.id==m.to_project_id)?.project_name || 'Project '+m.to_project_id) : 'Unassigned';
            const div = document.createElement('div');
            div.className = 'scenario-move';
            div.textContent = `Pallet ${m.inventory_pallet_id} → ${dest} (${m.quantity} modules)`;
            fragment.appendChild(div);
        });
        if (entries.length > 10) {
            const more = document.createElement('div');
            more.className = 'small-muted';
            more.textContent = `${entries.length - 10} more moves not shown`;
            fragment.appendChild(more);
        }
        els.scenarioMoves.innerHTML = '';
        els.scenarioMoves.appendChild(fragment);
    }

    async function saveScenario(action='save') {
        if (!Object.keys(scenario.moves).length) {
            alert('Add at least one pallet move before saving.');
            return;
        }
        const payload = {
            action,
            scenario_id: scenario.id,
            account_id: currentAccountId,
            name: els.scenarioNameInput.value.trim() || 'Draft scenario',
            description: els.scenarioDescInput.value.trim(),
            base_snapshot_hash: scenario.base_snapshot_hash,
            moves: Object.values(scenario.moves)
        };
        const resp = await fetch('api/module_allocation_scenarios.php', {
            method:'POST',
            headers:{'Content-Type':'application/json'},
            body: JSON.stringify(payload)
        });
        const data = await resp.json();
        if (!resp.ok) { alert(data.error || 'Save failed'); return; }
        scenario.id = data.scenario_id;
        scenario.status = data.status;
        scenario.name = payload.name;
        els.scenarioName.textContent = payload.name;
        els.scenarioStatus.textContent = data.status;
        loadScenarios();
        alert(data.message);
    }

    async function loadScenarios() {
        const params = new URLSearchParams({ account_id: currentAccountId, limit: 20, status: '' });
        const resp = await fetch(`api/module_allocation_scenarios.php?${params.toString()}`);
        const data = await resp.json();
        if (!resp.ok) return;
        els.scenarioList.innerHTML = '';
        data.items.forEach(s => {
            const card = document.createElement('div');
            card.className = 'bucket-card';
            card.innerHTML = `<div style="display:flex; justify-content: space-between; align-items:center;">
                <div><div class="title" style="font-size:14px;">${s.name}</div><div class="small-muted">${s.status}</div></div>
                <button class="btn ghost inline" data-id="${s.id}">Open</button>
            </div>
            <div class="muted" style="margin-top:6px;">${s.diff_summary || 'No summary'}</div>`;
            card.querySelector('button').addEventListener('click', ()=>loadScenarioById(s.id));
            els.scenarioList.appendChild(card);
        });
    }

    async function loadScenarioById(id) {
        const params = new URLSearchParams({ scenario_id:id, account_id: currentAccountId });
        const resp = await fetch(`api/module_allocation_scenarios.php?${params.toString()}`);
        const data = await resp.json();
        if (!resp.ok) { alert(data.error || 'Unable to load scenario'); return; }
        scenario.id = data.scenario.id;
        scenario.name = data.scenario.name;
        scenario.description = data.scenario.description;
        scenario.status = data.scenario.status;
        scenario.base_snapshot_hash = data.scenario.base_snapshot_hash;
        scenario.moves = {};
        (data.moves || []).forEach(m => { scenario.moves[m.inventory_pallet_id] = m; });
        els.scenarioName.textContent = scenario.name;
        els.scenarioStatus.textContent = scenario.status;
        els.scenarioNameInput.value = scenario.name;
        els.scenarioDescInput.value = scenario.description || '';
        renderScenarioMoves();
        // refresh list to highlight moves styling
        renderList();
    }

    function initEvents() {
        document.getElementById('applyBulk').addEventListener('click', applyBulk);
        document.getElementById('newScenarioBtn').addEventListener('click', () => {
            scenario = { id:null, name:'Draft scenario', description:'', status:'draft', moves:{}, base_snapshot_hash:null };
            els.scenarioName.textContent = scenario.name;
            els.scenarioStatus.textContent = 'draft';
            els.scenarioNameInput.value = '';
            els.scenarioDescInput.value = '';
            renderScenarioMoves();
            renderList();
        });
        document.getElementById('saveScenarioBtn').addEventListener('click', () => saveScenario('save'));
        document.getElementById('requestScenarioBtn').addEventListener('click', () => saveScenario('request'));
        document.getElementById('refreshScenarios').addEventListener('click', loadScenarios);
        document.getElementById('resetFilters').addEventListener('click', () => {
            els.projectFilter.value = '';
            els.statusFilter.value = '';
            els.warehouseFilter.value = '';
            els.searchInput.value = '';
            els.hideDelivered.checked = false;
            els.hideDamaged.checked = false;
            fetchPallets(true);
        });
        [els.projectFilter, els.statusFilter, els.warehouseFilter].forEach(el => el.addEventListener('change', ()=>fetchPallets(true)));
        els.searchInput.addEventListener('input', debounce(()=>fetchPallets(true), 300));
        els.hideDelivered.addEventListener('change', ()=>fetchPallets(true));
        els.hideDamaged.addEventListener('change', ()=>fetchPallets(true));
        if (els.accountSelect) {
            els.accountSelect.addEventListener('change', () => {
                currentAccountId = parseInt(els.accountSelect.value, 10);
                window.location = 'module_allocations.php?account_id=' + currentAccountId;
            });
        }
        window.addEventListener('scroll', () => {
            const nearBottom = window.innerHeight + window.scrollY >= document.body.offsetHeight - 200;
            if (nearBottom && items.length < total) fetchPallets(false);
        });
        document.getElementById('applyBulk');
    }

    function debounce(fn, wait) {
        let t; return (...args) => { clearTimeout(t); t = setTimeout(()=>fn(...args), wait); };
    }

    // Initial load
    initEvents();
    fetchPallets(true);
    loadScenarios();
    if (scenarioParam) loadScenarioById(scenarioParam);
})();
</script>
</body>
</html>
