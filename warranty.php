<?php
session_name('logistics_session');
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: login');
    exit();
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/warranty_helpers.php';
require_once __DIR__ . '/warranty_filters.php';

$conn = getDBConnection();

// Accessible projects for filter
$userId = (int)($_SESSION['user_id'] ?? 0);
$role = (string)($_SESSION['role'] ?? 'user');
$allowedProjectIds = getAllowedProjectIds($conn, $userId, $role);

$projects = [];
if ($allowedProjectIds === null) {
    $res = $conn->query('SELECT id, project_name FROM projects ORDER BY project_name ASC');
    while ($row = $res->fetch_assoc()) $projects[] = $row;
} elseif (!empty($allowedProjectIds)) {
    $place = implode(',', array_fill(0, count($allowedProjectIds), '?'));
    $types = str_repeat('i', count($allowedProjectIds));
    $stmt = $conn->prepare('SELECT id, project_name FROM projects WHERE id IN (' . $place . ') ORDER BY project_name ASC');
    $stmt->bind_param($types, ...$allowedProjectIds);
$stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) $projects[] = $row;
$stmt->close();
}
$conn->close();

// Filters persistence
$filters = loadPersistedWarrantyFilters();
$incoming = getWarrantyFiltersFromRequest();
foreach ($incoming as $k => $v) {
    if ($k === 'issue_types' || $k === 'statuses') {
        $filters[$k] = (array)$v;
    } else {
        $filters[$k] = $v;
    }
}
persistWarrantyFilters($filters);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Warranty Claims</title>
    <link rel="stylesheet" href="portal.css">
    <link rel="icon" href="pictures/favicon.png" type="image/x-icon">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/dataTables.bootstrap5.min.css">
    <style>
        /* Breadcrumbs */
        .breadcrumb { display: flex; margin-bottom: 20px; }
        .breadcrumb a { color: #488C9A; text-decoration: none; }
        .breadcrumb .separator { margin: 0 8px; color: #6c757d; }

        /* Layout + Filters */
        .filter-bar { 
            display:grid; 
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); 
            gap:16px 20px; 
            align-items:end; 
            background:#fff; 
            padding:18px; 
            margin: 10px 20px 20px; 
            border:1px solid #e9ecef; 
            border-radius:14px; 
            box-shadow:0 10px 30px rgba(0,0,0,0.06); 
            position:sticky; 
            top:70px; 
            z-index:9; 
        }
        .filter-bar .form-label { font-weight:600; color:#243947; font-size:0.9rem; letter-spacing:0.2px; }
        .filter-bar > .filter-group { min-width: 240px; display:flex; flex-direction:column; justify-content:flex-end; min-height: 96px; }
        .filter-bar .form-control, .filter-bar .form-select { height: 38px; }
        .filter-bar select[multiple] { height:38px; overflow:auto; }
        .chip-row { display:flex; gap:8px; flex-wrap:wrap; }
        .filter-chip { height:38px; }
        /* Dropdown pickers */
        .issue-select, .status-select { height:38px; border:1px solid #e1e6ea; border-radius:10px; padding:6px 12px; background:#fff; display:flex; align-items:center; justify-content:space-between; gap:8px; cursor:pointer; width:100%; box-sizing:border-box; }
        .issue-select .label, .status-select .label { white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
        .issue-dropdown, .status-dropdown { position:relative; }
        .issue-panel, .status-panel { position:absolute; top:calc(100% + 8px); left:0; z-index:1050; background:#fff; border:1px solid #e1e6ea; border-radius:12px; box-shadow:0 12px 30px rgba(0,0,0,0.08); padding:10px 12px; min-width:280px; width:max(280px, 100%); display:none; }
        .issue-panel.open, .status-panel.open { display:block; }
        .issue-panel .issue-item, .status-panel .status-item { display:flex; align-items:center; gap:8px; padding:4px 0; }
        .issue-panel .issue-item input:checked + span, .status-panel .status-item input:checked + span { font-weight:700; color:#1f3945; }
        .date-range { display:flex; gap:10px; }
        .apply-group { align-self:end; }
        .filter-chip { display:inline-flex; align-items:center; gap:8px; padding:8px 14px; border-radius:24px; background:linear-gradient(135deg,#E8F4F6,#F5FAFB); color:#1f3945; font-weight:600; border:1px solid #e1eef1; user-select:none; }
        .filter-chip input { accent-color:#3A6E7F; }
        .help-inline { color:#6c757d; font-size:0.8rem; margin-top:4px; display:block; }

        /* Badges */
        .status-badge { padding:6px 12px; border-radius:20px; font-size:0.8rem; font-weight:700; border:1px solid rgba(0,0,0,0.05); }
        .status-Closed { background:#e9ecef; color:#6c757d; }
        .status-Submitted { background:#d1e7ff; color:#084298; }
        .status-In\ Review { background:#fff3cd; color:#664d03; }
        .status-Pending\ Manufacturer { background:#fde2e1; color:#842029; }
        .status-Pending\ EPC { background:#fde2e1; color:#842029; }
        .status-Pending\ Carrier { background:#fde2e1; color:#842029; }
        .status-Approved\ -\ Credit { background:#d4edda; color:#0f5132; }
        .status-Approved\ -\ Replacement { background:#e2e3ff; color:#3d3dff; }
        .status-Replacement\ Shipped { background:#cff4fc; color:#055160; }
        .status-Rejected { background:#f8d7da; color:#842029; }
        #claimsTable tbody tr { cursor:pointer; }

        /* Buttons */
        .filters-btn { background: linear-gradient(135deg, #488C9A, #3A6E7F); color:#fff; border:none; padding:10px 16px; border-radius:12px; font-weight:600; box-shadow:0 6px 16px rgba(72,140,154,0.25); cursor:pointer; width:100%; }
        .filters-btn:hover { filter:brightness(0.96); box-shadow:0 10px 24px rgba(72,140,154,0.30); transform: translateY(-1px); }
        .icon-btn { width:38px; height:38px; display:inline-flex; align-items:center; justify-content:center; border-radius:50%; border:none; background:linear-gradient(135deg,#3A6E7F,#293E4C); color:#fff; box-shadow:0 8px 20px rgba(41,62,76,0.35); }
        .icon-btn:hover { filter:brightness(1.05); transform: translateY(-1px) scale(1.02); }

        /* Table polish */
        .table-container { padding:0 20px; }
        table.dataTable thead th { background: linear-gradient(135deg, #293E4C, #3A6E7F); color:#fff; border-color: rgba(255,255,255,0.15) !important; letter-spacing:0.4px; }
        table.dataTable tbody td { vertical-align: middle; color:#243947; }
        #claimsTable { background:#fff; border:1px solid #e9ecef; border-radius:14px; overflow:hidden; box-shadow:0 10px 30px rgba(0,0,0,0.06); }
        #claimsTable tbody tr:hover { background: linear-gradient(135deg, #F6FBFC, #F9FCFD); }
        .dataTables_wrapper .dataTables_paginate .paginate_button { border-radius:8px !important; background:#fff !important; border:1px solid #e1e6ea !important; margin:0 2px; }
        .dataTables_wrapper .dataTables_paginate .paginate_button.current { background:linear-gradient(135deg,#488C9A,#3A6E7F) !important; color:#fff !important; border:none !important; }
        .dataTables_wrapper .dataTables_length select, .dataTables_wrapper .dataTables_filter input { border:1px solid #e1e6ea; border-radius:10px; padding:6px 10px; }
        
        /* Clean up DataTable info/pagination */
        .dataTables_wrapper .dataTables_info { color:#6c757d; font-size:0.9rem; padding:16px 0; }
        .dataTables_wrapper .dataTables_paginate { padding:16px 0; }
        .dataTables_wrapper .dataTables_paginate ul.pagination { list-style:none; margin:0; padding-left:0; display:flex; }
        .dataTables_wrapper .dataTables_paginate ul.pagination li { list-style:none; }
        .dataTables_wrapper ul { list-style:none; }
        .dataTables_wrapper .row { align-items:center; }
        /* DataTables toolbar alignment */
        .dataTables_wrapper .row { margin:0; }
        .dataTables_wrapper .col-sm-6 { padding:0 8px; }
        .dataTables_wrapper .dataTables_length { display:flex; align-items:center; gap:8px; }
        .dataTables_wrapper .dataTables_filter { display:flex; align-items:center; gap:8px; justify-content:flex-end; }
        .dataTables_wrapper .dataTables_info { margin:0; }
        .dataTables_wrapper .dataTables_paginate { margin:0; text-align:right; }
    </style>
</head>
<body>
<?php include 'header.php'; ?>
<main>
    <div class="breadcrumb" style="margin: 10px 20px;">
        <a href="dashboard.php">Dashboard</a>
        <span class="separator">&raquo;</span>
        <span>Warranty Claims</span>
    </div>

    <h1>Warranty Claims</h1>
    <div style="height: 6px;"></div>

    <form class="filter-bar" id="filtersForm">
        <div class="filter-group">
            <label class="form-label" for="f_project">Project</label>
            <select id="f_project" name="project_id" class="form-select">
                <option value="0">All Projects</option>
                <?php foreach ($projects as $p): ?>
                    <option value="<?php echo (int)$p['id']; ?>" <?php echo ((int)$filters['project_id']===(int)$p['id'])?'selected':''; ?>><?php echo htmlspecialchars($p['project_name']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="filter-group issue-dropdown">
            <label class="form-label">Issue Type</label>
            <div class="issue-select" id="issueSelect">
                <span class="label" id="issueLabel">All types</span>
                <span>▾</span>
            </div>
            <div class="issue-panel" id="issuePanel">
                <?php $issueTypes = ['damaged'=>'Damaged','quantity_discrepancy'=>'Quantity Discrepancy','both'=>'Both'];
                foreach ($issueTypes as $val=>$label): $checked = in_array($val,(array)$filters['issue_types'],true)?'checked':''; ?>
                <label class="issue-item">
                    <input type="checkbox" class="form-check-input" value="<?php echo $val; ?>" <?php echo $checked; ?>>
                    <span><?php echo $label; ?></span>
                </label>
                <?php endforeach; ?>
            </div>
            <input type="hidden" name="issue_types[]" id="issue_hidden_1" value="<?php echo htmlspecialchars((string) (($filters['issue_types'][0] ?? ''))); ?>">
            <input type="hidden" name="issue_types[]" id="issue_hidden_2" value="<?php echo htmlspecialchars((string) (($filters['issue_types'][1] ?? ''))); ?>">
        </div>
        <div class="filter-group">
            <label class="form-label" for="f_resp">Responsible Party</label>
            <select id="f_resp" name="responsible_party" class="form-select">
                <option value="">All</option>
                <?php foreach (['Manufacturer','EPC','Carrier','Other'] as $rp): ?>
                    <option value="<?php echo $rp; ?>" <?php echo ($filters['responsible_party']===$rp)?'selected':''; ?>><?php echo $rp; ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="filter-group status-dropdown">
            <label class="form-label">Status</label>
            <div class="status-select" id="statusSelect">
                <span class="label" id="statusLabel">All statuses</span>
                <span>▾</span>
            </div>
            <div class="status-panel" id="statusPanel">
                <?php $statuses = warrantyStatusPath(); foreach ($statuses as $st): $checked = in_array($st,(array)$filters['statuses'],true)?'checked':''; ?>
                <label class="status-item">
                    <input type="checkbox" class="form-check-input" value="<?php echo $st; ?>" <?php echo $checked; ?>>
                    <span><?php echo $st; ?></span>
                </label>
                <?php endforeach; ?>
            </div>
            <input type="hidden" name="statuses[]" id="status_hidden_1" value="<?php echo htmlspecialchars((string) (($filters['statuses'][0] ?? ''))); ?>">
            <input type="hidden" name="statuses[]" id="status_hidden_2" value="<?php echo htmlspecialchars((string) (($filters['statuses'][1] ?? ''))); ?>">
            <input type="hidden" name="statuses[]" id="status_hidden_3" value="<?php echo htmlspecialchars((string) (($filters['statuses'][2] ?? ''))); ?>">
        </div>
        <div class="filter-group">
            <label class="form-label">Opened Range</label>
            <div class="date-range">
                <input type="date" class="form-control" id="f_from" name="date_from" value="<?php echo htmlspecialchars($filters['date_from']); ?>">
                <input type="date" class="form-control" id="f_to" name="date_to" value="<?php echo htmlspecialchars($filters['date_to']); ?>">
            </div>
        </div>
        <div class="filter-group">
            <label class="form-label" for="f_hide">&nbsp;</label>
            <div class="form-check form-switch">
                <input class="form-check-input" type="checkbox" role="switch" id="f_hide" name="hide_closed" value="1" <?php echo ((int)$filters['hide_closed']===1)?'checked':''; ?>>
                <label class="form-check-label" for="f_hide">Hide Closed</label>
            </div>
        </div>
        <div class="apply-group text-end">
            <button type="button" id="applyFilters" class="filters-btn" style="width:100%">Apply</button>
        </div>
    </form>

    <div class="table-container">
        <table id="claimsTable" class="table table-striped table-bordered">
        <thead>
            <tr>
                    <th>Ticket #</th>
                    <th>Project</th>
                    <th>Issue Type</th>
                    <th>Responsible Party</th>
                    <th>Status</th>
                    <th>Opened</th>
                    <th>Last Update</th>
                    <th>Action</th>
            </tr>
        </thead>
            <tbody></tbody>
    </table>
    </div>
</main>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/dataTables.bootstrap5.min.js"></script>
<script>
let claimsTable;

function buildAjaxData(d) {
  const form = document.getElementById('filtersForm');
  const fd = new FormData(form);
  const issueTypes = [];
  form.querySelectorAll('input[name="issue_types[]"]:checked').forEach(cb => issueTypes.push(cb.value));
  const statuses = Array.from(form.querySelectorAll('#f_statuses option:checked')).map(o => o.value);
  d.project_id = parseInt(fd.get('project_id') || '0');
  d.issue_types = issueTypes;
  d.responsible_party = fd.get('responsible_party') || '';
  d.statuses = statuses;
  d.date_from = fd.get('date_from') || '';
  d.date_to = fd.get('date_to') || '';
  d.hide_closed = form.querySelector('#f_hide').checked ? 1 : 0;
}

function statusBadge(status) {
  return `<span class="status-badge status-${status.replace(/ /g,'\\ ').replace(/-/g,'\\ - ')}">${status}</span>`;
}

document.addEventListener('DOMContentLoaded', function() {
  claimsTable = $('#claimsTable').DataTable({
    processing: true,
    serverSide: true,
    order: [[6, 'desc']],
    ajax: { url: 'get_warranty_claims.php', data: function(d){ buildAjaxData(d); } },
    pageLength: 25,
    dom: '<"row mb-2"<"col-sm-6"l><"col-sm-6"f>>t<"row mt-2"<"col-sm-6"i><"col-sm-6"p>>',
    columns: [
      { data: 0 },
      { data: 1 },
      { data: 2, render: data => `<span class=\"filter-chip\">${(data||'').replace('_',' ')}</span>` },
      { data: 3 },
      { data: 4, render: data => statusBadge(data) },
      { data: 5, render: data => new Date(data).toLocaleString() },
      { data: 6, render: data => new Date(data).toLocaleString() },
      { data: null, orderable:false, searchable:false, render: (row)=> `<button class=\"icon-btn\" title=\"Open\" onclick=\"event.stopPropagation(); window.location='warranty_detail.php?id=${row[0]}'\">▶</button>` }
    ]
  });

  $('#claimsTable tbody').on('click', 'tr', function() {
    const data = claimsTable.row(this).data();
    if (!data) return;
    window.location = `warranty_detail.php?id=${data[0]}`;
  });

  document.getElementById('applyFilters').addEventListener('click', function(){
    claimsTable.ajax.reload();
    });
  
  // Segmented control for Issue Type → writes up to two hidden inputs to preserve multi-select capability
  const issueSelect = document.getElementById('issueSelect');
  const issuePanel = document.getElementById('issuePanel');
  const issueLabel = document.getElementById('issueLabel');
  const issueHidden = [document.getElementById('issue_hidden_1'), document.getElementById('issue_hidden_2')];
  function currentIssues(){
    return Array.from(issuePanel.querySelectorAll('input[type="checkbox"]:checked')).map(i=>i.value).slice(0,2);
  }
  function updateIssueHidden(){
    const vals = currentIssues();
    issueHidden.forEach((inp, idx)=> inp.value = vals[idx] || '');
    issueLabel.textContent = vals.length ? vals.map(v => ({damaged:'Damaged','quantity_discrepancy':'Quantity Discrepancy', both:'Both'}[v])).join(', ') : 'All types';
  }
  issueSelect.addEventListener('click', ()=> {
    issuePanel.classList.toggle('open');
    // Close status if open
    const sp = document.getElementById('statusPanel');
    if (sp) sp.classList.remove('open');
  });
  issuePanel.addEventListener('change', updateIssueHidden);
  document.addEventListener('click', (e)=>{
    if (!issuePanel.contains(e.target) && !issueSelect.contains(e.target)) issuePanel.classList.remove('open');
  });
  updateIssueHidden();

  // Status dropdown with checkboxes → writes to hidden inputs for form serialization
  const statusSelect = document.getElementById('statusSelect');
  const statusPanel = document.getElementById('statusPanel');
  const statusLabel = document.getElementById('statusLabel');
  const statusHidden = [
    document.getElementById('status_hidden_1'),
    document.getElementById('status_hidden_2'),
    document.getElementById('status_hidden_3')
  ];
  function currentStatuses(){
    return Array.from(statusPanel.querySelectorAll('input[type="checkbox"]:checked')).map(i=>i.value).slice(0,3);
  }
  function updateStatusHidden(){
    const vals = currentStatuses();
    statusHidden.forEach((inp, idx)=> inp.value = vals[idx] || '');
    statusLabel.textContent = vals.length ? vals.join(', ') : 'All statuses';
  }
  statusSelect.addEventListener('click', ()=>{
    statusPanel.classList.toggle('open');
    // Close issue if open
    const ip = document.getElementById('issuePanel');
    if (ip) ip.classList.remove('open');
  });
  statusPanel.addEventListener('change', updateStatusHidden);
  document.addEventListener('click', (e)=>{
    if (!statusPanel.contains(e.target) && !statusSelect.contains(e.target)) {
      statusPanel.classList.remove('open');
    }
  });
  updateStatusHidden();
});
</script>
</body>
</html> 


