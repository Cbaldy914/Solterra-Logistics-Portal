<?php
session_name("logistics_session");
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: login');
    exit();
}

$role = $_SESSION['role'] ?? 'user';
$can_upload = in_array($role, ['admin','global_admin']);
$user_id = intval($_SESSION['user_id']);

if (!isset($_GET['project_id']) || !ctype_digit($_GET['project_id'])) {
    die('Missing project_id');
}
$project_id = intval($_GET['project_id']);

require_once '../config.php';
$conn = getDBConnection();
if (!$conn) { die('DB connection failed'); }

// Access control: admin/global_admin, or users tied to account
if ($role === 'global_admin') {
    $stmt = $conn->prepare('SELECT id, project_name, image_url FROM projects WHERE id = ?');
    $stmt->bind_param('i', $project_id);
} else {
    $stmt = $conn->prepare('SELECT p.id, p.project_name, p.image_url FROM projects p JOIN customer_account_users cau ON p.account_id = cau.account_id WHERE p.id = ? AND cau.user_id = ?');
    $stmt->bind_param('ii', $project_id, $user_id);
}
$stmt->execute();
$res = $stmt->get_result();
$project = $res->fetch_assoc();
$stmt->close();
if (!$project) { die('You do not have access to this project.'); }

$project_name = $project['project_name'];
$cover_image_url = $project['image_url'] ?: 'pictures/test.png';

// Load photos
$photos = [];
$stmt = $conn->prepare("SELECT id, original_file_name, file_path, uploaded_at FROM project_documents WHERE project_id = ? AND is_active = 1 AND document_type = 'pictures' AND document_sub_type = 'Project Photo'");
$stmt->bind_param('i', $project_id);
$stmt->execute();
$rs = $stmt->get_result();
while ($row = $rs->fetch_assoc()) { $photos[] = $row; }
$stmt->close();

// Load order
$order_path = __DIR__ . "/uploads/project_documents/{$project_id}/pictures/.order.json";
$ordered_ids = [];
if (is_file($order_path)) {
    $json = @file_get_contents($order_path);
    if ($json !== false) { $ordered_ids = json_decode($json, true) ?: []; }
}

// Apply ordering: first by order.json, then append remaining by uploaded_at desc
$photos_map = [];
foreach ($photos as $ph) { $photos_map[$ph['id']] = $ph; }
$ordered_photos = [];
foreach ($ordered_ids as $pid) { if (isset($photos_map[$pid])) { $ordered_photos[] = $photos_map[$pid]; unset($photos_map[$pid]); } }
// Remaining by newest first
usort($photos, function($a,$b){ return strcmp($b['uploaded_at'],$a['uploaded_at']); });
foreach ($photos as $ph) { if (isset($photos_map[$ph['id']])) { $ordered_photos[] = $ph; } }

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Project Photos - <?php echo htmlspecialchars($project_name); ?></title>
  <link rel="stylesheet" href="portal.css">
  <link rel="icon" href="pictures/favicon.png" type="image/x-icon" />
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
  <style>
    .global-documents-header { background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%); border-radius: 24px; padding: 32px; margin-bottom: 24px; box-shadow: 0 8px 32px rgba(0,0,0,0.06); border: 1px solid rgba(72,140,154,0.08); position: relative; overflow:hidden; }
    .global-documents-header::before { content:''; position:absolute; top:0; left:0; right:0; height:4px; background: linear-gradient(90deg, #488C9A 0%, #293E4C 100%); }
    .header-content { display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:24px; }
    .header-left { display:flex; align-items:center; gap:24px; }
    .cover-block { width: 180px; height: 120px; background:#eaeff2; border-radius: 16px; overflow:hidden; box-shadow: 0 10px 20px rgba(72,140,154,0.2); border:1px solid rgba(72,140,154,0.15); }
    .cover-block img { width:100%; height:100%; object-fit:cover; display:block; }
    .header-info h1 { font-size: 2.5em; font-weight: 700; background: linear-gradient(135deg, #293E4C 0%, #488C9A 100%); -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text; margin: 0 0 8px 0; line-height: 1.2; }
    .header-subtitle { color: #6c757d; font-size: 1.05em; font-weight: 500; margin: 0; }

    .dropzone { border: 2px dashed rgba(72,140,154,0.35); border-radius: 16px; padding: 28px; text-align:center; color:#537786; background: linear-gradient(135deg,#f8fbfc,#f3f8fa); margin-bottom: 16px; cursor:pointer; }
    .dropzone.dragover { background: #eef7fb; border-color: #488C9A; }

    .grid { display:grid; grid-template-columns: repeat(auto-fill,minmax(220px,1fr)); gap:16px; }
    .tile { position:relative; border-radius:14px; overflow:hidden; background:#0f2532; box-shadow: 0 10px 20px rgba(0,0,0,0.15); }
    .tile img { display:block; width:100%; height:180px; object-fit:cover; }
    .tile .meta { position:absolute; left:0; right:0; bottom:0; display:flex; justify-content:space-between; align-items:center; padding:8px 10px; background: linear-gradient(180deg, rgba(0,0,0,0) 0%, rgba(0,0,0,0.65) 80%); color:#fff; }
    .tile .actions { position:absolute; top:8px; right:8px; display:flex; gap:8px; }
    .chip { background: rgba(255,255,255,0.14); padding:4px 8px; border-radius: 999px; font-size: .8rem; }
    .icon-btn { background: rgba(0,0,0,0.5); color:#fff; border:none; border-radius:8px; width:36px; height:36px; display:flex; align-items:center; justify-content:center; cursor:pointer; }
    .icon-btn:hover { background: rgba(0,0,0,0.7); }
    .drag-handle { cursor:grab; }

    .modal { display:none; position: fixed; inset: 0; background: rgba(0,0,0,0.7); align-items:center; justify-content:center; z-index:1000; }
    .modal .modal-content { background:#0b1f2a; border-radius:16px; padding:0; width:min(900px,95%); color:#e6f1f5; box-shadow: 0 15px 40px rgba(0,0,0,0.4); overflow:hidden; }
    .modal-header { display:flex; justify-content:space-between; align-items:center; padding:16px 20px; border-bottom:1px solid rgba(255,255,255,0.06); }
    .modal-body { padding:0; }
    .modal-body img { display:block; width:100%; height:auto; }
  </style>
</head>
<body>
<?php include 'header.php'; ?>
<main>
  <?php
    require_once 'components/breadcrumbs.php';
    echo slp_render_breadcrumbs([
      'current_label' => 'Project Photos',
      'extra' => [ ['label' => 'Project Overview', 'url' => 'project_overview.php?project_id='.$project_id ] ]
    ]);
  ?>

  <div class="global-documents-header">
    <div class="header-content">
      <div class="header-left">
        <div class="cover-block" id="coverPreview"><img src="<?php echo htmlspecialchars($cover_image_url); ?>" alt="Project Cover"/></div>
        <div class="header-info">
          <h1>Project Photos</h1>
          <p class="header-subtitle"><?php echo htmlspecialchars($project_name); ?> — drag to reorder, first photo is the cover</p>
        </div>
      </div>
    </div>
  </div>

  <?php if ($can_upload): ?>
  <input type="hidden" id="tempToken" value="<?php echo htmlspecialchars(uniqid('pp_', true)); ?>" />
  <div id="dropzone" class="dropzone" onclick="openFilePicker()">
    <i class="fas fa-images" style="font-size:22px; color:#488C9A;"></i>
    <div>Drop images here or click to browse</div>
  </div>
  <input type="file" id="fileInput" accept="image/*" multiple style="display:none;" />
  <?php endif; ?>

  <?php if ($can_upload): ?>
  <div class="save-actions" style="display:flex; justify-content:flex-end; margin: 8px 0 16px;">
    <button id="saveBtn" type="button" class="document-button" style="width:auto; padding:12px 18px; background: linear-gradient(135deg, #488C9A 0%, #3A6E7F 100%); color:#fff; box-shadow: 0 4px 15px rgba(72,140,154,0.3); cursor:pointer; transition: transform 0.06s ease, box-shadow 0.2s ease;" onclick="saveAll()" disabled>
      <i class="fas fa-save"></i> Save
    </button>
  </div>
  <?php endif; ?>

  <div class="grid" id="photoGrid">
    <?php if (empty($ordered_photos)): ?>
      <div class="empty-state" style="grid-column: 1 / -1; text-align:center; padding: 40px; border:2px dashed rgba(72,140,154,0.25); border-radius:16px; color:#537786; background:linear-gradient(135deg,#f8fbfc,#f3f8fa)">
        <i class="fas fa-camera" style="font-size:32px; color:#f59e0b;"></i>
        <div style="margin-top:8px;">No photos yet — upload to get started</div>
      </div>
    <?php else: foreach ($ordered_photos as $idx => $ph): ?>
      <div class="tile" <?php echo $can_upload ? 'draggable="true"' : ''; ?> data-id="<?php echo $ph['id']; ?>" data-path="<?php echo htmlspecialchars($ph['file_path']); ?>">
        <img src="<?php echo htmlspecialchars($ph['file_path']); ?>" alt="Project Photo"/>
        <div class="actions">
          <button class="icon-btn" title="View" onclick="openPreview('<?php echo htmlspecialchars($ph['file_path']); ?>')"><i class="fas fa-eye"></i></button>
          <?php if ($can_upload): ?>
            <button class="icon-btn" title="Set as Cover" onclick="setAsCover(this)"><i class="fas fa-image"></i></button>
            <button class="icon-btn" title="Delete" onclick="deletePhoto(<?php echo $ph['id']; ?>)"><i class="fas fa-trash"></i></button>
            <button class="icon-btn drag-handle" title="Drag to reorder"><i class="fas fa-up-down-left-right"></i></button>
          <?php endif; ?>
        </div>
        <div class="meta"><span class="chip">#<?php echo $idx+1; ?></span><span class="chip">ID: <?php echo $ph['id']; ?></span></div>
      </div>
    <?php endforeach; endif; ?>
  </div>

  <div id="previewModal" class="modal" onclick="closePreview(event)">
    <div class="modal-content">
      <div class="modal-header"><span>Preview</span><button class="icon-btn" onclick="hidePreview()">&times;</button></div>
      <div class="modal-body"><img id="previewImg" src="" alt="Preview"/></div>
    </div>
  </div>

  <?php if ($can_upload): ?>
  <div class="save-actions" style="margin-top:16px; display:flex; justify-content:flex-end;"></div>
  <?php endif; ?>

</main>

<script>
const projectId = <?php echo $project_id; ?>;
const grid = document.getElementById('photoGrid');
const fileInput = document.getElementById('fileInput');
const dropzone = document.getElementById('dropzone');
const saveBtn = document.getElementById('saveBtn');
const tempTokenEl = document.getElementById('tempToken');
const tempToken = tempTokenEl ? tempTokenEl.value : '';

function markDirty(){ if (saveBtn) saveBtn.disabled = false; }
function openFilePicker(){ if (fileInput) fileInput.click(); }

if (fileInput) fileInput.addEventListener('change', async ()=>{ if (fileInput.files?.length){ await uploadTemp(fileInput.files); fileInput.value=''; } });
if (dropzone) {
  ['dragenter','dragover'].forEach(evt => dropzone.addEventListener(evt, (e)=>{ e.preventDefault(); dropzone.classList.add('dragover'); }));
  ['dragleave','drop'].forEach(evt => dropzone.addEventListener(evt, (e)=>{ e.preventDefault(); dropzone.classList.remove('dragover'); }));
  dropzone.addEventListener('drop', async (e)=>{ const files=e.dataTransfer?.files; if (files?.length) await uploadTemp(files); });
}

async function uploadTemp(files){
  for (const f of Array.from(files)){
    const form = new FormData();
    form.append('token', tempToken);
    form.append('file', f);
    try {
      const res = await fetch('upload_temp_photo.php', { method:'POST', body: form });
      const data = await res.json();
      if (data.success){ addStagedTile(data.file.name, data.file.path); markDirty(); }
      else { alert(data.message || 'Upload failed'); }
    } catch(e){ alert('Network error during upload'); }
  }
}

function enableDrag() {
  if (!<?php echo $can_upload ? 'true' : 'false'; ?>) return;
  Array.from(grid.children).forEach(tile => {
    tile.addEventListener('dragstart', (e)=>{ e.dataTransfer.setData('text/plain', tile.dataset.id); tile.classList.add('dragging'); });
    tile.addEventListener('dragend', ()=>{ tile.classList.remove('dragging'); updateIndices(); markDirty(); });
  });
  grid.addEventListener('dragover', (e)=>{
    e.preventDefault();
    const dragging = grid.querySelector('.dragging');
    const after = getDragAfterElement(grid, e.clientY);
    if (!dragging) return;
    if (after == null) { grid.appendChild(dragging); } else { grid.insertBefore(dragging, after); }
  });
}

function getDragAfterElement(container, y) {
  const els = [...container.querySelectorAll('.tile:not(.dragging)')];
  return els.reduce((closest, child) => {
    const box = child.getBoundingClientRect();
    const offset = y - box.top - box.height / 2;
    if (offset < 0 && offset > closest.offset) { return { offset, element: child }; } else { return closest; }
  }, { offset: Number.NEGATIVE_INFINITY }).element;
}

function updateIndices() {
  Array.from(grid.children).forEach((tile, idx) => {
    const chip = tile.querySelector('.meta .chip');
    if (chip) chip.textContent = `#${idx+1}`;
  });
  // Update cover preview instantly
  const first = grid.querySelector('.tile');
  if (first) {
    const src = first.getAttribute('data-path') || first.getAttribute('data-staged-path');
    const img = document.querySelector('#coverPreview img');
    if (img && src) img.src = src;
  }
}

async function saveAll(){
  if (!saveBtn) return;
  // Button feedback
  const prevHTML = saveBtn.innerHTML;
  saveBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
  saveBtn.style.opacity = '0.9';
  saveBtn.style.transform = 'translateY(-1px)';
  saveBtn.disabled = true;

  const mixed = Array.from(grid.children).map(el => {
    if (el.dataset.id) return 'id:' + el.dataset.id;
    if (el.dataset.temp) return 'temp:' + el.dataset.temp;
    return null;
  }).filter(Boolean);
  try {
    const res = await fetch('commit_project_photos.php', { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({ project_id: projectId, token: tempToken, order: mixed }) });
    const data = await res.json();
    if (!data.success){
      alert(data.message || 'Failed to save');
      saveBtn.innerHTML = prevHTML; saveBtn.disabled = false; saveBtn.style.opacity = '1'; saveBtn.style.transform = 'none';
      return;
    }
    saveBtn.innerHTML = '<i class="fas fa-check"></i> Saved';
    setTimeout(()=>{ window.location.reload(); }, 600);
  } catch(e){ alert('Network error while saving'); }
}

function addStagedTile(name, path){
  const tile = document.createElement('div');
  tile.className = 'tile'; tile.setAttribute('draggable','true'); tile.dataset.temp = name; tile.setAttribute('data-staged-path', path);
  tile.innerHTML = `<img src="${path}" alt="staged"/>
    <div class=\"actions\">
      <button class=\"icon-btn\" title=\"View\" onclick=\"openPreview('${path}')\"><i class=\"fas fa-eye\"></i></button>
      <button class=\"icon-btn\" title=\"Remove\" onclick=\"removeStaged('${name}', this)\"><i class=\"fas fa-trash\"></i></button>
      <button class=\"icon-btn drag-handle\" title=\"Drag to reorder\"><i class=\"fas fa-up-down-left-right\"></i></button>
    </div>
    <div class=\"meta\"><span class=\"chip\">New</span></div>`;
  grid.insertBefore(tile, grid.firstChild);
  enableDrag(); updateIndices();
}

async function removeStaged(name, btn){
  if (!confirm('Remove this photo?')) return;
  try { await fetch('delete_temp_photo.php', { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({ token: tempToken, name }) }); } catch(e){}
  const tile = btn.closest('.tile'); if (tile) tile.remove(); updateIndices();
}

function setAsCover(btn) {
  const tile = btn.closest('.tile'); if (!tile) return;
  grid.insertBefore(tile, grid.firstChild);
  updateIndices(); markDirty();
}

async function deletePhoto(id) {
  if (!confirm('Delete this photo?')) return;
  try {
    const res = await fetch('delete_project_documents.php', {
      method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ ids: [id] })
    });
    const data = await res.json();
    if (!data.success) { alert('Delete failed'); return; }
    await reloadGrid();
  } catch (e) { alert('Network error while deleting'); }
}

function openPreview(path) {
  const modal = document.getElementById('previewModal');
  document.getElementById('previewImg').src = path;
  modal.style.display = 'flex';
}
function hidePreview() { document.getElementById('previewModal').style.display = 'none'; }
function closePreview(e) { if (e.target.id === 'previewModal') hidePreview(); }

enableDrag();
</script>
</body>
</html>
