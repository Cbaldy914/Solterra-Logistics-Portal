<?php
session_name('logistics_session');
session_start();

if (!isset($_SESSION['user_id'])) { header('Location: login'); exit(); }

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/warranty_helpers.php';

$conn = getDBConnection();

$role = (string)($_SESSION['role'] ?? 'user');
if (!in_array($role, ['admin','global_admin','customer_admin'], true)) { $conn->close(); header('Location: unauthorized.php'); exit(); }

$userId = (int)($_SESSION['user_id'] ?? 0);
$allowedProjectIds = getAllowedProjectIds($conn, $userId, $role); // null => all

// Projects list
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

$selectedProjectId = isset($_GET['project_id']) ? (int)$_GET['project_id'] : 0;
// Load recent appointments for selected project (optional link)
$appointments = [];
if ($selectedProjectId > 0) {
    // Ensure project is allowed
    if (is_array($allowedProjectIds) && !in_array($selectedProjectId, $allowedProjectIds, true)) {
        $conn->close();
        die('Unauthorized project');
    }
    $stmt = $conn->prepare('SELECT s.id, s.bol_number, s.start_time FROM site_scheduling s WHERE s.project_id = ? ORDER BY s.start_time DESC LIMIT 200');
    $stmt->bind_param('i', $selectedProjectId);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) { $appointments[] = $row; }
    $stmt->close();
}

$conn->close();

// CSRF
if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); }
$csrf = $_SESSION['csrf_token'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Exception Ticket</title>
    <link rel="stylesheet" href="portal.css">
    <link rel="icon" href="pictures/favicon.png" type="image/x-icon">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        /* Breadcrumbs like warranty.php */
        .breadcrumb { display: flex; margin-bottom: 20px; }
        .breadcrumb a { color: #488C9A; text-decoration: none; }
        .breadcrumb .separator { margin: 0 8px; color: #6c757d; }

        .page { margin: 0 20px 30px; }
        .card { background:#fff; border:1px solid #e8edf2; border-radius:14px; box-shadow:0 12px 36px rgba(0,0,0,0.06); overflow:hidden; }
        .card-header { padding:14px 18px; border-bottom:1px solid #eef2f6; font-weight:700; color:#293E4C; background:linear-gradient(180deg,#ffffff,#fbfdfe); }
        .card-body { padding:16px 18px; }
        .form-row { display:grid; grid-template-columns: 1fr 1fr; gap:16px; }
        .form-row.full { grid-template-columns: 1fr; }
        .form-group { margin-bottom:14px; }
        .form-label { display:block; font-weight:600; color:#2C3E50; margin-bottom:6px; }
        .form-select, .form-control { border:1px solid #e2e9ef; border-radius:10px; padding:8px 12px; width:100%; box-sizing:border-box; }
        .form-select:focus, .form-control:focus { border-color:#488C9A; box-shadow:0 0 0 3px rgba(72,140,154,0.15); }
        .btn-primary { background: linear-gradient(135deg, #488C9A, #3A6E7F); border:none; border-radius:12px; padding:12px 20px; box-shadow:0 10px 24px rgba(58,110,127,0.25); font-weight:700; color:#fff !important; cursor:pointer; }
        .btn-primary:hover { filter:brightness(0.96); transform: translateY(-1px); box-shadow:0 14px 30px rgba(58,110,127,0.30); }
        .upload-card { display:flex; align-items:center; justify-content:center; flex-direction:column; border:2px dashed #d0d0d0; background:#f9f9f9; color:#6c757d; border-radius:12px; padding:18px; height:70px; cursor:pointer; }
        .file-pills { display:flex; flex-wrap:wrap; gap:6px; margin-top:6px; }
        .file-pill { background:#f4f7f9; border:1px solid #e2e9ef; color:#293E4C; padding:4px 8px; border-radius:999px; font-size:12px; }
        .pallets-help { color:#6c757d; font-size:0.85rem; }
    </style>
    <script>
        function onProjectChange(sel){
            const pid = sel.value || '0';
            const url = new URL(window.location.href);
            url.searchParams.set('project_id', pid);
            window.location = url.toString();
        }
        function toggleAppointmentFields(){
            const linkSel = document.getElementById('appointment_id');
            const manual = document.getElementById('manual_fields');
            const bolInput = document.querySelector('input[name="bol_number"]');
            if (linkSel.value && parseInt(linkSel.value) > 0) {
                manual.style.display = 'none';
                if (bolInput) bolInput.required = false;
                loadPallets();
            } else {
                manual.style.display = 'block';
                if (bolInput) bolInput.required = true;
                clearPallets();
            }
        }
        function clearPallets(){
            const cont = document.getElementById('pallets_container');
            if (cont) cont.innerHTML = '';
        }
        async function loadPallets(){
            const pid = document.querySelector('select[name="project_id"]').value;
            const aid = document.getElementById('appointment_id').value;
            const cont = document.getElementById('pallets_container');
            if (!pid || !aid || parseInt(aid) <= 0) { clearPallets(); return; }
            cont.innerHTML = '<div class="pallets-help">Loading pallets…</div>';
            try {
                const url = `scheduling.php?action=get_appointment_pallets&project_id=${encodeURIComponent(pid)}&appointment_id=${encodeURIComponent(aid)}`;
                const res = await fetch(url, { credentials: 'same-origin' });
                const data = await res.json();
                if (!data.success) { cont.innerHTML = '<div class="pallets-help">No pallets found.</div>'; return; }
                const pallets = data.pallets || [];
                if (!pallets.length) { cont.innerHTML = '<div class="pallets-help">No pallets for this appointment.</div>'; return; }
                let html = '';
                html += '<div class="card" style="margin-top:14px;">';
                html += '<div class="card-header">Appointment Pallets</div>';
                html += '<div class="card-body">';
                html += '<div class="pallets-help" style="margin-bottom:8px;">Enter actual, damaged, and accepted quantities per pallet.</div>';
                html += '<div class="table-responsive">';
                html += '<table class="pallets-table">';
                html += '<thead><tr><th>Pallet</th><th style="text-align:right;">Expected</th><th style="text-align:right;">Actual</th><th style="text-align:right;">Damaged</th><th style="text-align:right;">Accepted</th><th>Notes</th></tr></thead><tbody>';
                pallets.forEach(p => {
                    const id = parseInt(p.id);
                    const label = (p.pallet_identifier ? p.pallet_identifier + ' ' : '') + '(' + (p.wattage||'') + 'W)';
                    const exp = parseInt(p.quantity || 0);
                    html += '<tr>' +
                        `<td>${label}</td>` +
                        `<td style="text-align:right;">${exp}</td>` +
                        `<td style=\"text-align:right;\"><input type=\"number\" min=\"0\" class=\"form-control\" style=\"max-width:120px; margin-left:auto;\" name=\"pallet_actuals[${id}]\" value=\"${exp}\"></td>` +
                        `<td style=\"text-align:right;\"><input type=\"number\" min=\"0\" class=\"form-control\" style=\"max-width:120px; margin-left:auto;\" name=\"pallet_damages[${id}]\" value=\"0\"></td>` +
                        `<td style=\"text-align:right;\"><input type=\"number\" min=\"0\" class=\"form-control\" style=\"max-width:120px; margin-left:auto;\" name=\"pallet_accepted[${id}]\" value=\"${exp}\"></td>` +
                        `<td><input type=\"text\" class=\"form-control\" name=\"pallet_notes[${id}]\" placeholder=\"Note (optional)\"></td>` +
                    '</tr>';
                });
                html += '</tbody></table></div></div></div>';
                cont.innerHTML = html;
            } catch (e) {
                cont.innerHTML = '<div class="pallets-help">Failed to load pallets.</div>';
            }
        }
        function bindUploads(inputId, previewId){
            const inp = document.getElementById(inputId);
            const prev = document.getElementById(previewId);
            inp.addEventListener('change', function(){
                prev.innerHTML = '';
                if (inp.files && inp.files.length){
                    prev.style.display = 'flex';
                    Array.from(inp.files).forEach(f => {
                        const pill = document.createElement('div');
                        pill.className = 'file-pill';
                        pill.textContent = f.name;
                        prev.appendChild(pill);
                    });
                } else {
                    prev.style.display = 'none';
                }
            });
        }
        document.addEventListener('DOMContentLoaded', function(){
            toggleAppointmentFields();
            bindUploads('upload_docs', 'upload_docs_preview');
        });
    </script>
    </head>
<body>
<?php include 'header.php'; ?>
<main class="page">
    <?php
        require_once 'components/breadcrumbs.php';
        echo slp_render_breadcrumbs([
            'current_label' => 'Create Ticket',
            'project_id' => (int)$selectedProjectId,
            'extra' => [ ['label' => 'Exceptions Report', 'url' => 'warranty.php'.($selectedProjectId?('?project_id='.(int)$selectedProjectId):'') ] ]
        ]);
    ?>

    <h1>Create Exception Ticket</h1>

    <form method="post" action="process_warranty_create.php" enctype="multipart/form-data" class="card" style="margin-top:12px;">
        <div class="card-header">Details</div>
        <div class="card-body">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Project</label>
                    <select name="project_id" class="form-select" onchange="onProjectChange(this)" required>
                        <option value="">Select project</option>
                        <?php foreach ($projects as $p): ?>
                            <option value="<?php echo (int)$p['id']; ?>" <?php echo ((int)$p['id']===$selectedProjectId)?'selected':''; ?>><?php echo htmlspecialchars($p['project_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Link to Appointment (optional)</label>
                    <select id="appointment_id" name="appointment_id" class="form-select" onchange="toggleAppointmentFields()" <?php echo $selectedProjectId>0?'':'disabled'; ?>>
                        <option value="0">— None —</option>
                        <?php foreach ($appointments as $a): ?>
                            <option value="<?php echo (int)$a['id']; ?>">#<?php echo (int)$a['id']; ?> · <?php echo htmlspecialchars($a['bol_number']); ?> · <?php echo htmlspecialchars($a['start_time']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div id="pallets_container"></div>

            <div id="manual_fields" class="form-row">
                <div class="form-group">
                    <label class="form-label">BOL Number</label>
                    <input type="text" name="bol_number" class="form-control" placeholder="BOL #" <?php echo $selectedProjectId>0?'required':''; ?>>
                </div>
                <div class="form-group">
                    <label class="form-label">Delivery Date (optional)</label>
                    <input type="date" name="delivery_date" class="form-control">
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Issue Type</label>
                    <select name="issue_type" class="form-select" required>
                        <option value="damaged">Damaged</option>
                        <option value="quantity_discrepancy">Quantity Discrepancy</option>
                        <option value="both">Both</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Responsible Party</label>
                    <select name="responsible_party" class="form-select" required>
                        <?php foreach (['Manufacturer','EPC','Carrier','Other'] as $rp): ?>
                            <option value="<?php echo $rp; ?>"><?php echo $rp; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="form-row full">
                <div class="form-group">
                    <label class="form-label">Public Note (optional)</label>
                    <textarea name="public_notes" class="form-control" rows="3" placeholder="Customer-visible note..."></textarea>
                </div>
            </div>

            <div class="form-row full">
                <div class="form-group">
                    <label class="form-label">Internal Notes (optional)</label>
                    <textarea name="internal_notes" class="form-control" rows="3" placeholder="Only visible to admins..."></textarea>
                </div>
            </div>

            <div class="form-row full">
                <div class="form-group">
                    <label class="form-label">Attachments</label>
                    <label for="upload_docs" class="upload-card">
                        <div style="font-weight:600;">Upload files</div>
                        <div style="font-size:12px; color:#6c757d;">PDF, PNG, JPG, WEBP</div>
                    </label>
                    <input id="upload_docs" type="file" name="proof_files[]" multiple accept=".pdf,.png,.jpg,.jpeg,.webp" style="display:none;">
                    <div id="upload_docs_preview" class="file-pills" style="display:none;"></div>
                </div>
            </div>

            <div style="display:flex; gap:10px; justify-content:flex-end; margin-top:6px;">
                <button type="submit" class="btn-primary">Create Ticket</button>
            </div>
        </div>
    </form>
</main>
</body>
</html>
