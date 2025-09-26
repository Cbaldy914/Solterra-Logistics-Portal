<?php
session_name('logistics_session');
session_start();

if (!isset($_SESSION['user_id'])) { header('Location: login'); exit(); }

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/warranty_helpers.php';

$conn = getDBConnection();

$claimId = isset($_GET['claim_id']) ? (int)$_GET['claim_id'] : 0;
if ($claimId <= 0) { $conn->close(); die('Invalid claim'); }

// Admin only
if (!isAdminRole()) { $conn->close(); header('Location: unauthorized.php'); exit(); }

// Load claim and related project
$stmt = $conn->prepare('SELECT w.*, ss.project_id, p.project_name FROM warranty_claims w JOIN site_scheduling ss ON ss.id = w.scheduling_id JOIN projects p ON p.id = ss.project_id WHERE w.id = ?');
$stmt->bind_param('i', $claimId);
$stmt->execute();
$res = $stmt->get_result();
$claim = $res->fetch_assoc();
$stmt->close();
if (!$claim) { $conn->close(); die('Claim not found'); }

$projectId = (int)$claim['project_id'];
$projectName = (string)$claim['project_name'];

// Parse notes to discover damaged pallets by wattage and typical modules-per-pallet
$notes = jsonToArray($claim['notes'] ?? '');
$palletNotes = isset($notes['pallets']) && is_array($notes['pallets']) ? $notes['pallets'] : [];

$damagedByGroup = []; // key: wattage
$palletIds = [];
foreach ($palletNotes as $row) {
    $pid = (int)($row['pallet_id'] ?? 0);
    $watt = (int)($row['wattage'] ?? 0);
    $dam = (int)($row['damaged'] ?? 0);
    if ($pid > 0 && $watt > 0 && $dam > 0) {
        $palletIds[] = $pid;
        if (!isset($damagedByGroup[$watt])) { $damagedByGroup[$watt] = [ 'total_damaged' => 0, 'quantities' => [], 'manufacturers' => [], 'locations' => [] ]; }
        $damagedByGroup[$watt]['total_damaged'] += $dam;
    }
}

// Load additional data for the referenced pallets (quantity per pallet, manufacturer, manufacturer_location)
if (!empty($palletIds)) {
    $ph = implode(',', array_fill(0, count($palletIds), '?'));
    $types = str_repeat('i', count($palletIds));
    $q = $conn->prepare("SELECT id, wattage, quantity, manufacturer, manufacturer_location_id FROM inventory_pallets WHERE id IN ($ph)");
    $q->bind_param($types, ...$palletIds);
    $q->execute();
    $rs = $q->get_result();
    while ($r = $rs->fetch_assoc()) {
        $w = (int)$r['wattage'];
        if (!isset($damagedByGroup[$w])) continue;
        $damagedByGroup[$w]['quantities'][] = (int)$r['quantity'];
        if (!empty($r['manufacturer'])) { $damagedByGroup[$w]['manufacturers'][] = (string)$r['manufacturer']; }
        $damagedByGroup[$w]['locations'][] = is_null($r['manufacturer_location_id']) ? null : (int)$r['manufacturer_location_id'];
    }
    $q->close();
}

// Build quick plan per wattage
$quickPlan = [];
foreach ($damagedByGroup as $watt => $info) {
    $total = (int)$info['total_damaged'];
    $mpp = 0;
    if (!empty($info['quantities'])) {
        // Use statistical mode of quantities; fallback to first
        $counts = array_count_values($info['quantities']);
        arsort($counts);
        $mpp = (int)array_key_first($counts);
    }
    if ($mpp <= 0) { $mpp = 30; }
    $full = intdiv(max(0, $total), $mpp);
    $rem = max(0, $total - ($full * $mpp));
    $manufacturer = '';
    if (!empty($info['manufacturers'])) {
        $countsM = array_count_values($info['manufacturers']);
        arsort($countsM);
        $manufacturer = (string)array_key_first($countsM);
    }
    $locationId = null;
    if (!empty($info['locations'])) {
        $countsL = array_count_values($info['locations']);
        arsort($countsL);
        $locationId = array_key_first($countsL);
        $locationId = ($locationId === '') ? null : (int)$locationId; // handle null keys
    }
    $quickPlan[] = [
        'wattage' => (int)$watt,
        'modules_per_pallet' => (int)$mpp,
        'full_pallets' => (int)$full,
        'partial_modules' => (int)$rem,
        'manufacturer' => $manufacturer,
        'manufacturer_location_id' => $locationId,
    ];
}

// CSRF token
if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); }
$csrf = $_SESSION['csrf_token'];

// Manufacturers and locations for manual builder
$manufacturers = [];
$locations = [];
$resM = $conn->query("SELECT id, name FROM manufacturers WHERE is_active = 1 ORDER BY name ASC");
if ($resM) { while ($r = $resM->fetch_assoc()) { $manufacturers[] = ['id'=>(int)$r['id'], 'name'=>(string)$r['name']]; } }
$resL = $conn->query("SELECT id, manufacturer_id, location_name FROM manufacturer_locations ORDER BY location_name ASC");
if ($resL) { while ($r = $resL->fetch_assoc()) { $locations[] = ['id'=>(int)$r['id'], 'manufacturer_id'=>(int)$r['manufacturer_id'], 'name'=>(string)$r['location_name']]; } }

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Create Replacement Pallets · Claim #<?php echo (int)$claimId; ?></title>
  <link rel="stylesheet" href="portal.css">
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <style>
    .container { background:#fff; border:1px solid #e9ecef; border-radius:14px; box-shadow:0 12px 36px rgba(0,0,0,0.06); }
    .header { 
      padding:20px; 
    }
    .header h1 {
    }
    .header p {
      margin: 10px 0 0 0;
      opacity: 0.9;
    }
    .body { padding:40px; }
    .plan-table { width:100%; border-collapse: collapse; margin-bottom: 20px; }
    .plan-table th, .plan-table td { padding:10px 12px; border-bottom:1px solid #eef2f6; text-align:left; }
    .actions { display:flex; gap:10px; margin-top:14px; }
    .btn { border:none; border-radius:10px; padding:12px 24px; font-weight:600; cursor:pointer; transition: all 0.3s ease; }
    .btn-primary { background: linear-gradient(135deg, #488C9A, #3A6E7F); color:#fff; }
    .btn-primary:hover { background: linear-gradient(135deg, #3A6E7F, #2F5D6A); }
    .btn-secondary { background:#f8f9fa; border:1px solid #e9ecef; color: #6c757d; }
    .btn-secondary:hover { background:#e9ecef; }
    .notice { background:#f8fafc; border:1px solid #e6edf1; padding:15px 20px; border-radius:10px; margin-bottom:20px; color:#243947; }
    
    .form-section {
      margin-bottom: 40px;
      margin-top: 30px;
      padding: 30px;
      background: #f8f9fa;
      border-radius: 12px;
      border-left: 4px solid #488C9A;
    }
    
    .form-section h2 {
      color: #293E4C;
      margin-bottom: 20px;
      font-size: 1.3em;
      font-weight: 600;
    }
    
    .manual-container {
      background: white;
      padding: 20px;
      border-radius: 8px;
      border: 1px solid #dee2e6;
    }
    
    .replacement-entry {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
      gap: 20px;
      align-items: end;
      margin-bottom: 20px;
      padding: 20px;
      background: #fff;
      border: 2px solid #e9ecef;
      border-radius: 10px;
      transition: border-color 0.3s ease;
    }
    
    .replacement-entry:hover {
      border-color: #488C9A;
    }
    
    .form-group {
      margin-bottom: 0;
    }
    
    .form-group label {
      display: block;
      font-weight: 600;
      color: #293E4C;
      margin-bottom: 8px;
      font-size: 0.9em;
    }
    
    .form-group input,
    .form-group select {
      width: 100%;
      padding: 12px;
      border: 2px solid #e9ecef;
      border-radius: 8px;
      font-size: 1rem;
      transition: border-color 0.3s ease;
      box-sizing: border-box;
    }
    
    .form-group input:focus,
    .form-group select:focus {
      outline: none;
      border-color: #488C9A;
    }
    
    .add-entry-btn,
    .remove-entry-btn {
      padding: 12px 18px;
      border: none;
      border-radius: 8px;
      cursor: pointer;
      font-weight: 600;
      transition: all 0.3s ease;
    }
    
    .add-entry-btn {
      background: #488C9A;
      color: white;
      margin-bottom: 20px;
    }
    
    .add-entry-btn:hover {
      background: #3A6E7F;
    }
    
    .remove-entry-btn {
      background: #dc3545;
      color: white;
      height: fit-content;
    }
    
    .remove-entry-btn:hover {
      background: #c82333;
    }
    
    .pallet-preview {
      background: #e8f5f8;
      padding: 10px 15px;
      border-radius: 6px;
      margin-top: 10px;
      font-size: 0.9em;
      color: #2c5a66;
      border: 1px solid #b8e3ec;
    }
    
    /* Modal Styles */
    .modal {
      display: none;
      position: fixed;
      z-index: 1000;
      left: 0;
      top: 0;
      width: 100%;
      height: 100%;
      background-color: rgba(0,0,0,0.5);
    }
    
    .modal-content {
      background-color: #fefefe;
      margin: 10% auto;
      padding: 30px;
      border-radius: 12px;
      width: 90%;
      max-width: 600px;
      box-shadow: 0 4px 20px rgba(0,0,0,0.3);
    }
    
    .modal-header {
      text-align: center;
      margin-bottom: 20px;
    }
    
    .modal-header h2 {
      color: #293E4C;
      margin: 0;
    }
    
    .modal-body {
      margin-bottom: 20px;
    }
    
    .pallet-summary {
      background: #f8f9fa;
      padding: 20px;
      border-radius: 8px;
      margin: 15px 0;
      border-left: 4px solid #488C9A;
    }
    
    .modal-footer {
      display: flex;
      justify-content: space-between;
      gap: 15px;
    }
    
    .modal-btn {
      flex: 1;
      padding: 12px 20px;
      border: none;
      border-radius: 8px;
      font-weight: 600;
      cursor: pointer;
      transition: all 0.3s ease;
    }
    
    .modal-btn-cancel {
      background: #6c757d;
      color: white;
    }
    
    .modal-btn-cancel:hover {
      background: #5a6268;
    }
    
    .modal-btn-confirm {
      background: #488C9A;
      color: white;
    }
    
    .modal-btn-confirm:hover {
      background: #3A6E7F;
    }
    
    @media (max-width: 768px) {
      .container {
        margin: 10px;
      }
      
      .body {
        padding: 20px;
      }
      
      .form-section {
        padding: 20px;
      }
      
      .replacement-entry {
        grid-template-columns: 1fr;
        gap: 15px;
      }
      
      .modal-content {
        margin: 5% auto;
        padding: 20px;
      }
      
      .modal-footer {
        flex-direction: column;
      }
    }
  </style>
</head>

<body>
<?php include 'header.php'; ?>
<main>
<?php
    require_once 'components/breadcrumbs.php';
    echo slp_render_breadcrumbs([
        'current_label' => 'Create Replacement Pallets',
        'project_id' => (int)$projectId,
        'extra' => [ ['label' => 'Exceptions Report', 'url' => 'warranty.php?project_id='.(int)$projectId] ]
    ]);
?>
<div class="container">
  <div class="header">
    <h1>Create Replacement Pallets</h1>
    <p>Project: <?php echo htmlspecialchars($projectName); ?> · Claim #<?php echo (int)$claimId; ?></p>
  </div>
  <div class="body">
    <div class="notice">Quick Replace will create pallets with status <strong>At Manufacturer</strong>. Manufacturer location is taken from the damaged pallets; pallets are packed as full pallets first with a partial pallet for any remaining modules.</div>
    <?php if (empty($quickPlan)) : ?>
      <div class="notice" style="background:#fff3cd; border-color:#ffe69c;">No damaged pallet details available on this claim. Quick Replace is unavailable.</div>
    <?php else: ?>
      <table class="plan-table">
        <thead>
          <tr>
            <th>Wattage</th>
            <th>Modules per Pallet</th>
            <th>Full Pallets</th>
            <th>Partial Modules</th>
            <th>Manufacturer</th>
            <th>Location ID</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($quickPlan as $row): ?>
          <tr>
            <td><?php echo (int)$row['wattage']; ?> W</td>
            <td><?php echo (int)$row['modules_per_pallet']; ?></td>
            <td><?php echo (int)$row['full_pallets']; ?></td>
            <td><?php echo (int)$row['partial_modules']; ?></td>
            <td><?php echo htmlspecialchars((string)$row['manufacturer']); ?></td>
            <td><?php echo is_null($row['manufacturer_location_id']) ? '—' : (int)$row['manufacturer_location_id']; ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <form method="post" action="process_create_replacements.php">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
        <input type="hidden" name="claim_id" value="<?php echo (int)$claimId; ?>">
        <input type="hidden" name="mode" value="quick">
        <input type="hidden" name="plan_json" value='<?php echo json_encode($quickPlan, JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_TAG | JSON_UNESCAPED_SLASHES); ?>'>
        <div class="actions">
          <a class="btn btn-secondary" href="warranty_detail.php?id=<?php echo (int)$claimId; ?>">Cancel</a>
          <button type="submit" class="btn btn-primary">Quick Replace</button>
        </div>
      </form>
      
      <!-- Manual Builder Section -->
      <div class="form-section">
        <h2>Manual Builder</h2>
        <div class="notice">Add one or more replacement entries. Enter wattage, total module quantity, and modules per pallet. Full pallets will be generated automatically with a partial pallet for any remainder.</div>
        
        <form method="post" action="process_create_replacements.php" onsubmit="return showConfirmationModal(event);" id="manualForm">
          <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
          <input type="hidden" name="claim_id" value="<?php echo (int)$claimId; ?>">
          <input type="hidden" name="mode" value="manual">
          
          <div class="manual-container">
            <div id="replacementEntries">
              <div class="replacement-entry">
                <div class="form-group">
                  <label>Wattage (W) *</label>
                  <input type="number" name="wattage[]" min="1" required onchange="updatePalletPreview(this)">
                </div>
                <div class="form-group">
                  <label>Total Modules *</label>
                  <input type="number" name="quantity[]" min="1" required onchange="updatePalletPreview(this)">
                </div>
                <div class="form-group">
                  <label>Modules per Pallet *</label>
                  <input type="number" name="modules_per_pallet[]" min="1" required onchange="updatePalletPreview(this)">
                </div>
                <div class="form-group">
                  <label>Manufacturer *</label>
                  <select name="manufacturer_id[]" onchange="syncLocationOptions(this)" required>
                    <option value="">Select manufacturer</option>
                    <?php foreach ($manufacturers as $m): ?>
                      <option value="<?php echo (int)$m['id']; ?>"><?php echo htmlspecialchars($m['name']); ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="form-group">
                  <label>Location *</label>
                  <select name="location_id[]" required>
                    <option value="">Select location</option>
                    <?php foreach ($locations as $loc): ?>
                      <option value="<?php echo (int)$loc['id']; ?>" data-manufacturer-id="<?php echo (int)$loc['manufacturer_id']; ?>"><?php echo htmlspecialchars($loc['name']); ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="pallet-preview" style="grid-column: 1 / -1;"></div>
              </div>
            </div>
            
            <button type="button" class="add-entry-btn" onclick="addReplacementEntry()">+ Add Another Entry</button>
          </div>
          
          <div class="actions">
            <a class="btn btn-secondary" href="warranty_detail.php?id=<?php echo (int)$claimId; ?>">Cancel</a>
            <button type="submit" class="btn btn-primary">Create Replacements</button>
          </div>
        </form>
      </div>
    <?php endif; ?>
  </div>
</div>

<!-- Confirmation Modal -->
<div id="confirmationModal" class="modal">
  <div class="modal-content">
    <div class="modal-header">
      <h2>Confirm Replacement Creation</h2>
    </div>
    <div class="modal-body">
      <p>You are about to create the following replacement pallets:</p>
      <div id="modalSummary"></div>
      <p><strong>Are you sure you want to proceed?</strong></p>
    </div>
    <div class="modal-footer">
      <button type="button" class="modal-btn modal-btn-cancel" onclick="closeConfirmationModal()">Cancel</button>
      <button type="button" class="modal-btn modal-btn-confirm" onclick="confirmSubmission()">Create Pallets</button>
    </div>
  </div>
</div>

<script>
// Add new replacement entry
function addReplacementEntry() {
  const container = document.getElementById('replacementEntries');
  const newEntry = document.createElement('div');
  newEntry.className = 'replacement-entry';
  newEntry.innerHTML = `
    <div class="form-group">
      <label>Wattage (W) *</label>
      <input type="number" name="wattage[]" min="1" required onchange="updatePalletPreview(this)">
    </div>
    <div class="form-group">
      <label>Total Modules *</label>
      <input type="number" name="quantity[]" min="1" required onchange="updatePalletPreview(this)">
    </div>
    <div class="form-group">
      <label>Modules per Pallet *</label>
      <input type="number" name="modules_per_pallet[]" min="1" required onchange="updatePalletPreview(this)">
    </div>
    <div class="form-group">
      <label>Manufacturer *</label>
      <select name="manufacturer_id[]" onchange="syncLocationOptions(this)" required>
        <option value="">Select manufacturer</option>
        <?php foreach ($manufacturers as $m): ?>
          <option value="<?php echo (int)$m['id']; ?>"><?php echo htmlspecialchars($m['name']); ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-group">
      <label>Location *</label>
      <select name="location_id[]" required>
        <option value="">Select location</option>
        <?php foreach ($locations as $loc): ?>
          <option value="<?php echo (int)$loc['id']; ?>" data-manufacturer-id="<?php echo (int)$loc['manufacturer_id']; ?>"><?php echo htmlspecialchars($loc['name']); ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-group" style="display: flex; align-items: end;">
      <button type="button" class="remove-entry-btn" onclick="removeReplacementEntry(this)">Remove</button>
    </div>
    <div class="pallet-preview" style="grid-column: 1 / -1;"></div>
  `;
  container.appendChild(newEntry);
}

// Remove replacement entry
function removeReplacementEntry(button) {
  const container = document.getElementById('replacementEntries');
  if (container.children.length > 1) {
    button.closest('.replacement-entry').remove();
  } else {
    alert('At least one replacement entry is required.');
  }
}

// Update pallet preview for an entry
function updatePalletPreview(input) {
  const entry = input.closest('.replacement-entry');
  const wattage = parseInt(entry.querySelector('input[name="wattage[]"]').value) || 0;
  const quantity = parseInt(entry.querySelector('input[name="quantity[]"]').value) || 0;
  const modulesPerPallet = parseInt(entry.querySelector('input[name="modules_per_pallet[]"]').value) || 0;
  const preview = entry.querySelector('.pallet-preview');
  
  if (quantity > 0 && modulesPerPallet > 0) {
    const fullPallets = Math.floor(quantity / modulesPerPallet);
    const partialModules = quantity % modulesPerPallet;
    
    let text = `${wattage}W: `;
    if (fullPallets > 0) {
      text += `${fullPallets} full pallet${fullPallets > 1 ? 's' : ''} (${fullPallets * modulesPerPallet} modules)`;
    }
    if (partialModules > 0) {
      if (fullPallets > 0) text += ' + ';
      text += `1 partial pallet (${partialModules} modules)`;
    }
    
    preview.textContent = text;
    preview.style.display = 'block';
  } else {
    preview.style.display = 'none';
  }
}

// Filter locations when manufacturer changes
function syncLocationOptions(manufacturerSelect) {
  const entry = manufacturerSelect.closest('.replacement-entry');
  const manuId = manufacturerSelect.value;
  const locSel = entry.querySelector('select[name="location_id[]"]');
  if (!locSel) return;
  
  Array.from(locSel.options).forEach(opt => {
    if (!opt.value) return;
    const mid = opt.getAttribute('data-manufacturer-id');
    opt.style.display = (!manuId || mid === manuId) ? '' : 'none';
  });
  
  if (locSel.selectedIndex > 0) {
    const selOpt = locSel.options[locSel.selectedIndex];
    if (selOpt.style.display === 'none') locSel.value = '';
  }
}

// Show confirmation modal before submission
function showConfirmationModal(event) {
  event.preventDefault();
  
  const entries = document.querySelectorAll('.replacement-entry');
  let summaryHtml = '';
  let totalFullPallets = 0;
  let totalPartialPallets = 0;
  let totalModules = 0;
  
  for (const entry of entries) {
    const wattage = parseInt(entry.querySelector('input[name="wattage[]"]').value) || 0;
    const quantity = parseInt(entry.querySelector('input[name="quantity[]"]').value) || 0;
    const modulesPerPallet = parseInt(entry.querySelector('input[name="modules_per_pallet[]"]').value) || 0;
    const manufacturer = entry.querySelector('select[name="manufacturer_id[]"]').selectedOptions[0]?.text || '';
    const location = entry.querySelector('select[name="location_id[]"]').selectedOptions[0]?.text || '';
    
    if (quantity > 0 && modulesPerPallet > 0) {
      const fullPallets = Math.floor(quantity / modulesPerPallet);
      const partialModules = quantity % modulesPerPallet;
      
      totalFullPallets += fullPallets;
      if (partialModules > 0) totalPartialPallets++;
      totalModules += quantity;
      
      summaryHtml += `
        <div class="pallet-summary">
          <strong>${wattage}W Modules</strong><br>
          Manufacturer: ${manufacturer}<br>
          Location: ${location}<br>
          Total Modules: ${quantity}<br>
          ${fullPallets > 0 ? `Full Pallets: ${fullPallets} (${fullPallets * modulesPerPallet} modules)<br>` : ''}
          ${partialModules > 0 ? `Partial Pallet: 1 (${partialModules} modules)<br>` : ''}
        </div>
      `;
    }
  }
  
  summaryHtml += `
    <div class="pallet-summary" style="border-left-color: #28a745; font-weight: bold;">
      <strong>Total Summary:</strong><br>
      ${totalFullPallets} full pallets + ${totalPartialPallets} partial pallet${totalPartialPallets !== 1 ? 's' : ''}<br>
      ${totalModules} total modules
    </div>
  `;
  
  document.getElementById('modalSummary').innerHTML = summaryHtml;
  document.getElementById('confirmationModal').style.display = 'block';
  
  return false;
}

// Close confirmation modal
function closeConfirmationModal() {
  document.getElementById('confirmationModal').style.display = 'none';
}

// Confirm and submit the form
function confirmSubmission() {
  closeConfirmationModal();
  document.getElementById('manualForm').submit();
}

// Close modal when clicking outside
window.onclick = function(event) {
  const modal = document.getElementById('confirmationModal');
  if (event.target === modal) {
    closeConfirmationModal();
  }
}
</script>
</main>
</body>
</html>


