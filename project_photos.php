<?php
session_name("logistics_session");
session_start();
if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); }

if (!isset($_SESSION['user_id'])) {
    header('Location: login');
    exit();
}

$role = $_SESSION['role'] ?? 'user';
$can_upload = in_array($role, ['admin','global_admin','customer_admin']);
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
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
  <style>
    .page-header { background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%); border-radius: 24px; padding: 32px; margin-bottom: 24px; box-shadow: 0 8px 32px rgba(0,0,0,0.06); border: 1px solid rgba(72,140,154,0.08); position: relative; overflow:visible; }
    .page-header::before { content:''; position:absolute; top:0; left:0; right:0; height:4px; background: linear-gradient(90deg, #488C9A 0%, #293E4C 100%); }
    .header-content { display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:24px; }
    .header-left { display:flex; align-items:center; gap:24px; }
    .header-right { display:flex; align-items:center; justify-content:flex-end; }
    .header-info h1 { font-size: 2.2em; font-weight: 700; background: linear-gradient(135deg, #293E4C 0%, #488C9A 100%); -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text; margin: 0 0 6px 0; line-height: 1.2; }
    .header-subtitle { color: #6c757d; font-size: 1.1em; font-weight: 500; margin: 0 0 4px 0; }
    .header-note { color: #94a3b8; font-size: 0.9em; margin: 0; }
    .cover-preview { width: 150px; height: 120px; background:#eaeff2; border-radius: 20px; overflow:hidden; flex-shrink:0; box-shadow: 0 12px 24px rgba(72,140,154,0.3); border:1px solid rgba(72,140,154,0.15); }
    .cover-preview img { width:100%; height:100%; object-fit:cover; display:block; }

    .header-save-btn { width:auto; padding:12px 18px; background: linear-gradient(135deg, #488C9A 0%, #3A6E7F 100%); color:#fff; box-shadow: 0 4px 15px rgba(72,140,154,0.3); cursor:pointer; transition: transform 0.06s ease, box-shadow 0.2s ease; }
    .header-save-btn:disabled { opacity: 0.55; cursor: default; box-shadow: none; }

    @media (max-width: 768px) {
      .header-right { width: 100%; justify-content: flex-start; }
    }

    .photo-grid { display:grid; grid-template-columns: repeat(auto-fill,minmax(220px,1fr)); gap:16px; }
    .photo-card { position:relative; border-radius:16px; overflow:hidden; background:#fff; box-shadow: 0 10px 20px rgba(0,0,0,0.12); border: 1px solid rgba(72,140,154,0.1); min-height: 180px; cursor: grab; transition: transform 0.18s ease, box-shadow 0.18s ease; }
    .photo-card img { display:block; width:100%; height:100%; object-fit:cover; min-height: 180px; }
    .photo-card.cover { grid-column: span 2; grid-row: span 2; min-height: 260px; }
    .photo-card.cover img { min-height: 260px; }
    .photo-actions { position:absolute; top:10px; right:10px; display:flex; gap:8px; }
    .photo-order { position:absolute; bottom:12px; left:12px; background: rgba(0,0,0,0.6); color:#fff; padding:4px 10px; border-radius:999px; font-size:0.8rem; }
    .photo-badge { position:absolute; bottom:12px; right:12px; background: #488C9A; color:#fff; padding:4px 10px; border-radius:999px; font-size:0.75rem; font-weight:600; }
    .photo-card:hover { transform: translateY(-2px); box-shadow: 0 14px 26px rgba(0,0,0,0.18); }
    .photo-card.dragging { opacity: 0.4; cursor: grabbing; transform: scale(0.98); }

    .add-card { display:flex; align-items:center; justify-content:center; flex-direction:column; gap:8px; border:2px dashed rgba(72,140,154,0.35); background: linear-gradient(135deg,#f8fbfc,#f3f8fa); color:#537786; cursor:pointer; box-shadow:none; }
    .add-card i { font-size: 28px; color: #488C9A; }
    .add-card:hover { border-color: #488C9A; background: #eef7fb; }

    .placeholder-card { display:flex; align-items:center; justify-content:center; flex-direction:column; gap:8px; border:2px dashed rgba(72,140,154,0.25); background: #f8fbfc; color:#537786; box-shadow:none; }

    .icon-btn { background: rgba(0,0,0,0.55); color:#fff; border:none; border-radius:8px; width:34px; height:34px; display:flex; align-items:center; justify-content:center; cursor:pointer; }
    .icon-btn:hover { background: rgba(0,0,0,0.75); }
    .photo-card img { pointer-events: none; }

    .upload-modal { display:none; position: fixed; inset: 0; background: rgba(15,28,36,0.55); align-items:center; justify-content:center; z-index:1100; }
    .upload-modal-content { background:#fff; border-radius:16px; padding:20px 28px; display:flex; align-items:center; gap:12px; box-shadow: 0 15px 40px rgba(0,0,0,0.3); color:#293E4C; font-weight:600; }
    .upload-spinner { width:24px; height:24px; border:3px solid #e6e6e6; border-top-color:#488C9A; border-radius:50%; animation: spin 1s linear infinite; }
    @keyframes spin { to { transform: rotate(360deg); } }

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

  <div class="page-header">
    <div class="header-content">
      <div class="header-left">
        <div class="cover-preview" id="coverPreview"><img src="<?php echo htmlspecialchars($cover_image_url); ?>" alt="Project Cover"/></div>
        <div class="header-info">
          <h1>Project Photos</h1>
          <p class="header-subtitle"><?php echo htmlspecialchars($project_name); ?></p>
          <p class="header-note">Drag to reorder. The first photo becomes the cover image.</p>
        </div>
      </div>
      <?php if ($can_upload): ?>
      <div class="header-right">
        <button id="saveBtn" type="button" class="document-button header-save-btn" onclick="saveAll()" disabled>
          <i class="fas fa-save"></i> Save Changes
        </button>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($can_upload): ?>
    <input type="hidden" id="tempToken" value="<?php echo htmlspecialchars(uniqid('pp_', true)); ?>" />
    <input type="file" id="fileInput" accept="image/*" multiple style="display:none;" />
  <?php endif; ?>

  <div class="photo-grid" id="photoGrid">
    <?php if (empty($ordered_photos)): ?>
      <div class="photo-card placeholder-card">
        <i class="fas fa-image" style="font-size:28px; color:#488C9A;"></i>
        <div>Cover photo will appear here</div>
      </div>
      <?php if ($can_upload): ?>
        <div class="photo-card add-card" id="addPhotoCard" onclick="openFilePicker()">
          <i class="fas fa-plus-circle"></i>
          <div>Add Photo</div>
        </div>
      <?php endif; ?>
    <?php else: foreach ($ordered_photos as $idx => $ph): ?>
      <div class="photo-card <?php echo $idx === 0 ? 'cover' : ''; ?>" <?php echo $can_upload ? 'draggable="true"' : ''; ?> data-id="<?php echo $ph['id']; ?>" data-path="<?php echo htmlspecialchars($ph['file_path']); ?>">
        <img src="<?php echo htmlspecialchars($ph['file_path']); ?>" alt="Project Photo"/>
        <div class="photo-actions">
          <button class="icon-btn" title="View" onclick="openPreview('<?php echo htmlspecialchars($ph['file_path']); ?>')"><i class="fas fa-eye"></i></button>
          <?php if ($can_upload): ?>
            <button class="icon-btn" title="Delete" onclick="deletePhoto(<?php echo $ph['id']; ?>)"><i class="fas fa-trash"></i></button>
          <?php endif; ?>
        </div>
        <span class="photo-order"><?php echo $idx + 1; ?></span>
        <span class="photo-badge" style="<?php echo $idx === 0 ? '' : 'display:none;'; ?>">Cover</span>
      </div>
    <?php endforeach; endif; ?>
    <?php if (!empty($ordered_photos) && $can_upload): ?>
      <div class="photo-card add-card" id="addPhotoCard" onclick="openFilePicker()">
        <i class="fas fa-plus-circle"></i>
        <div>Add Photo</div>
      </div>
    <?php endif; ?>
  </div>

  <div id="previewModal" class="modal" onclick="closePreview(event)">
    <div class="modal-content">
      <div class="modal-header"><span>Preview</span><button class="icon-btn" onclick="hidePreview()">&times;</button></div>
      <div class="modal-body"><img id="previewImg" src="" alt="Preview"/></div>
    </div>
  </div>

  <div id="uploadModal" class="upload-modal">
    <div class="upload-modal-content">
      <div class="upload-spinner"></div>
      <div id="uploadModalText">Uploading photos...</div>
    </div>
  </div>

</main>

<script>
const projectId = <?php echo $project_id; ?>;
const csrfToken = <?php echo json_encode($_SESSION['csrf_token']); ?>;
const grid = document.getElementById('photoGrid');
const fileInput = document.getElementById('fileInput');
const saveBtn = document.getElementById('saveBtn');
const tempTokenEl = document.getElementById('tempToken');
const tempToken = tempTokenEl ? tempTokenEl.value : '';
const addCard = document.getElementById('addPhotoCard');
const uploadModal = document.getElementById('uploadModal');
const uploadModalText = document.getElementById('uploadModalText');

function markDirty(){ if (saveBtn) saveBtn.disabled = false; }
function showUploadModal(total){ if (!uploadModal) return; uploadModal.style.display = 'flex'; updateUploadModal(0, total); }
function updateUploadModal(done, total){ if (uploadModalText) uploadModalText.textContent = total ? `Uploading ${done} of ${total} photos...` : 'Uploading photos...'; }
function hideUploadModal(){ if (uploadModal) uploadModal.style.display = 'none'; }
function openFilePicker(){ if (fileInput) fileInput.click(); }

if (fileInput) fileInput.addEventListener('change', async ()=>{ if (fileInput.files?.length){ await uploadTemp(fileInput.files); fileInput.value=''; } });

async function uploadTemp(files){
  const fileList = Array.from(files);
  showUploadModal(fileList.length);
  let index = 0;
  try {
    for (const f of fileList){
      index += 1;
      updateUploadModal(index, fileList.length);
      const form = new FormData();
      form.append('token', tempToken);
      form.append('csrf_token', csrfToken);
      form.append('file', f);
      const res = await fetch('upload_temp_photo.php', { method:'POST', body: form });
      const data = await res.json();
      if (data.success){ addStagedTile(data.file.name, data.file.path); markDirty(); }
      else { alert(data.message || 'Upload failed'); }
    }
  } catch(e){ alert('Network error during upload'); }
  hideUploadModal();
}

function enableDrag() {
  if (!<?php echo $can_upload ? 'true' : 'false'; ?>) return;
  Array.from(grid.querySelectorAll('.photo-card:not(.add-card):not(.placeholder-card)')).forEach(tile => {
    if (tile.dataset.dragInit) return;
    tile.dataset.dragInit = 'true';
    tile.querySelectorAll('button').forEach(btn => { btn.draggable = false; });
    tile.addEventListener('dragstart', (e)=>{ e.dataTransfer.setData('text/plain', tile.dataset.id || ''); tile.classList.add('dragging'); });
    tile.addEventListener('dragend', ()=>{ tile.classList.remove('dragging'); updateIndices(); markDirty(); });
  });
  if (!grid.dataset.dragInit) {
    grid.dataset.dragInit = 'true';
    grid.addEventListener('dragover', (e)=>{
      e.preventDefault();
      const dragging = grid.querySelector('.dragging');
      if (!dragging) return;
      const target = getDragAfterElement(grid, e.clientX, e.clientY);
      if (!target || !target.element) {
        if (addCard && addCard.parentNode === grid) {
          grid.insertBefore(dragging, addCard);
        } else {
          grid.appendChild(dragging);
        }
      } else if (target.offset > 0) {
        grid.insertBefore(dragging, target.element.nextSibling);
      } else {
        grid.insertBefore(dragging, target.element);
      }
    });
  }
}

function getDragAfterElement(container, x, y) {
  const element = document.elementFromPoint(x, y)?.closest('.photo-card:not(.add-card):not(.placeholder-card)');
  if (!element || element.classList.contains('dragging')) {
    return null;
  }
  const box = element.getBoundingClientRect();
  const offset = y - box.top - box.height / 2;
  return { element, offset };
}

function updateIndices() {
  const tiles = Array.from(grid.querySelectorAll('.photo-card:not(.add-card):not(.placeholder-card)'));
  tiles.forEach((tile, idx) => {
    const order = tile.querySelector('.photo-order');
    if (order) order.textContent = `${idx + 1}`;
    const badge = tile.querySelector('.photo-badge');
    if (idx === 0) {
      tile.classList.add('cover');
      if (badge) {
        badge.textContent = 'Cover';
        badge.style.display = 'inline-flex';
      }
    } else {
      tile.classList.remove('cover');
      if (badge) badge.style.display = 'none';
    }
  });
  positionAddCard();
  const first = tiles[0];
  if (first) {
    const src = first.getAttribute('data-path') || first.getAttribute('data-staged-path');
    const img = document.querySelector('#coverPreview img');
    if (img && src) img.src = src;
  }
}

function positionAddCard() {
  if (!addCard) return;
  if (!grid.contains(addCard)) {
    grid.appendChild(addCard);
  } else {
    grid.appendChild(addCard);
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

  const mixed = Array.from(grid.querySelectorAll('.photo-card[data-id], .photo-card[data-temp]'))
    .map(el => {
      if (el.dataset.id) return 'id:' + el.dataset.id;
      if (el.dataset.temp) return 'temp:' + el.dataset.temp;
      return null;
    })
    .filter(Boolean);
  try {
    const res = await fetch('commit_project_photos.php', { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({ project_id: projectId, token: tempToken, order: mixed, csrf_token: csrfToken }) });
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
  tile.className = 'photo-card';
  tile.setAttribute('draggable','true');
  tile.dataset.temp = name;
  tile.setAttribute('data-staged-path', path);
  tile.innerHTML = `<img src="${path}" alt="staged"/>
    <div class=\"photo-actions\">
      <button class=\"icon-btn\" title=\"View\" onclick=\"openPreview('${path}')\"><i class=\"fas fa-eye\"></i></button>
      <button class=\"icon-btn\" title=\"Remove\" onclick=\"removeStaged('${name}', this)\"><i class=\"fas fa-trash\"></i></button>
    </div>
    <span class=\"photo-order\">New</span>
    <span class=\"photo-badge\" style=\"display:none;\">Cover</span>`;
  const placeholder = grid.querySelector('.placeholder-card');
  if (placeholder) {
    placeholder.remove();
  }

  if (addCard && addCard.parentNode === grid) {
    if (addCard.nextSibling) {
      grid.insertBefore(tile, addCard.nextSibling);
    } else {
      grid.appendChild(tile);
    }
  } else {
    grid.appendChild(tile);
  }
  enableDrag(); updateIndices();
}

async function removeStaged(name, btn){
  if (!confirm('Remove this photo?')) return;
  try { await fetch('delete_temp_photo.php', { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({ token: tempToken, name, csrf_token: csrfToken }) }); } catch(e){}
  const tile = btn.closest('.tile'); if (tile) tile.remove(); updateIndices();
}

async function deletePhoto(id) {
  if (!confirm('Delete this photo?')) return;
  try {
    const res = await fetch('delete_project_documents.php', {
      method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ ids: [id], csrf_token: csrfToken })
    });
    const data = await res.json();
    if (!data.success) { alert('Delete failed'); return; }
    await reloadGrid();
  } catch (e) { alert('Network error while deleting'); }
}

async function reloadGrid() {
  window.location.reload();
}

function openPreview(path) {
  const modal = document.getElementById('previewModal');
  document.getElementById('previewImg').src = path;
  modal.style.display = 'flex';
}
function hidePreview() { document.getElementById('previewModal').style.display = 'none'; }
function closePreview(e) { if (e.target.id === 'previewModal') hidePreview(); }

enableDrag();
updateIndices();
</script>
</body>
</html>
