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
  <style>
    .container { max-width: 1100px; margin:20px auto; background:#fff; border:1px solid #e9ecef; border-radius:14px; box-shadow:0 12px 36px rgba(0,0,0,0.06); }
    .header { padding:16px 18px; border-bottom:1px solid #eef2f6; font-weight:700; }
    .body { padding:18px; }
    .plan-table { width:100%; border-collapse: collapse; }
    .plan-table th, .plan-table td { padding:10px 12px; border-bottom:1px solid #eef2f6; text-align:left; }
    .actions { display:flex; gap:10px; margin-top:14px; }
    .btn { border:none; border-radius:10px; padding:10px 14px; font-weight:600; cursor:pointer; }
    .btn-primary { background: linear-gradient(135deg, #488C9A, #3A6E7F); color:#fff; }
    .btn-secondary { background:#f8f9fa; border:1px solid #e9ecef; }
    .notice { background:#f8fafc; border:1px solid #e6edf1; padding:10px 12px; border-radius:10px; margin-bottom:10px; color:#243947; }
  </style>
</head>
<body>
<?php include 'header.php'; ?>
<div class="container">
  <div class="header">Create Replacement Pallets<br><span style="font-weight:400; color:#6c757d;">Project: <?php echo htmlspecialchars($projectName); ?> · Claim #<?php echo (int)$claimId; ?></span></div>
  <div class="body">
    <div class="notice">Quick Replace will create pallets with status <strong>At Manufacturer</strong>. Manufacturer location is taken from the damaged pallets; pallets are packed as full pallets first with a partial pallet for any remaining modules.</div>
    <?php if (empty($quickPlan)) : ?>
      <div class="notice" style="background:#fff3cd; border-color:#ffe69c;">No damaged pallet details available on this claim. Quick Replace is unavailable.</div>
      <div class="actions">
        <a class="btn btn-secondary" href="warranty_detail.php?id=<?php echo (int)$claimId; ?>">Back</a>
      </div>
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
      <hr style="margin:16px 0; border:none; border-top:1px solid #eef2f6;">
      <form method="post" action="process_create_replacements.php" onsubmit="return validateManual();">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
        <input type="hidden" name="claim_id" value="<?php echo (int)$claimId; ?>">
        <input type="hidden" name="mode" value="manual">
        <div class="notice">Manual Builder: add one or more rows. Select Manufacturer and Location. Enter wattage and a comma-separated list of modules-per-pallet values (e.g., 30,30,28) to generate pallets.</div>
        <table class="plan-table" id="manualTable">
          <thead>
            <tr>
              <th>Wattage</th>
              <th>Modules per Pallet (comma-separated)</th>
              <th>Manufacturer</th>
              <th>Location</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <tr>
              <td><input type="number" name="wattage[]" class="form-control" style="width:120px" required></td>
              <td><input type="text" name="mpp_list[]" class="form-control" style="width:280px" placeholder="e.g., 30,30,28" required></td>
              <td>
                <select name="manufacturer_id[]" class="form-control" style="width:220px" onchange="syncLocationOptions(this)" required>
                  <option value="">Select manufacturer</option>
                  <?php foreach ($manufacturers as $m): ?>
                    <option value="<?php echo (int)$m['id']; ?>"><?php echo htmlspecialchars($m['name']); ?></option>
                  <?php endforeach; ?>
                </select>
              </td>
              <td>
                <select name="location_id[]" class="form-control" style="width:220px" required>
                  <option value="">Select location</option>
                  <?php foreach ($locations as $loc): ?>
                    <option value="<?php echo (int)$loc['id']; ?>" data-manufacturer-id="<?php echo (int)$loc['manufacturer_id']; ?>"><?php echo htmlspecialchars($loc['name']); ?></option>
                  <?php endforeach; ?>
                </select>
              </td>
              <td><button type="button" class="btn btn-secondary" onclick="addManualRow()">＋</button></td>
            </tr>
          </tbody>
        </table>
        <div class="actions">
          <a class="btn btn-secondary" href="warranty_detail.php?id=<?php echo (int)$claimId; ?>">Cancel</a>
          <button type="submit" class="btn btn-primary">Create (Manual)</button>
        </div>
      </form>
    <?php endif; ?>
  </div>
</div>
<script>
function addManualRow(){
  const tb = document.getElementById('manualTable').querySelector('tbody');
  const tr = document.createElement('tr');
  tr.innerHTML = `
    <td><input type=\"number\" name=\"wattage[]\" class=\"form-control\" style=\"width:120px\" required></td>
    <td><input type=\"text\" name=\"mpp_list[]\" class=\"form-control\" style=\"width:280px\" placeholder=\"e.g., 30,30,28\" required></td>
    <td>
      <select name=\"manufacturer_id[]\" class=\"form-control\" style=\"width:220px\" onchange=\"syncLocationOptions(this)\" required>
        <option value=\"\">Select manufacturer</option>
        <?php foreach ($manufacturers as $m): ?>
          <option value=\"<?php echo (int)$m['id']; ?>\"><?php echo htmlspecialchars($m['name']); ?></option>
        <?php endforeach; ?>
      </select>
    </td>
    <td>
      <select name=\"location_id[]\" class=\"form-control\" style=\"width:220px\" required>
        <option value=\"\">Select location</option>
        <?php foreach ($locations as $loc): ?>
          <option value=\"<?php echo (int)$loc['id']; ?>\" data-manufacturer-id=\"<?php echo (int)$loc['manufacturer_id']; ?>\"><?php echo htmlspecialchars($loc['name']); ?></option>
        <?php endforeach; ?>
      </select>
    </td>
    <td><button type="button" class="btn btn-secondary" onclick="this.closest('tr').remove()">—</button></td>
  `;
  tb.appendChild(tr);
}

function validateManual(){
  const rows = document.querySelectorAll('#manualTable tbody tr');
  for (const r of rows){
    const mpp = r.querySelector('input[name="mpp_list[]"]').value.trim();
    if (!mpp) { alert('Each row must include modules-per-pallet values.'); return false; }
    const nums = mpp.split(',').map(s=>parseInt(s.trim(),10)).filter(n=>!isNaN(n) && n>0);
    if (nums.length === 0) { alert('Modules per pallet values must be positive integers.'); return false; }
  }
  return true;
}
// Filter locations when manufacturer changes
function syncLocationOptions(manufacturerSelect){
  const tr = manufacturerSelect.closest('tr');
  const manuId = manufacturerSelect.value;
  const locSel = tr.querySelector('select[name="location_id[]"]');
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
</script>
</body>
</html>


