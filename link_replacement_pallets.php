<?php
session_name('logistics_session');
session_start();

if (!isset($_SESSION['user_id'])) { header('Location: login'); exit(); }

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/warranty_helpers.php';

$conn = getDBConnection();

$claimId = isset($_GET['claim_id']) ? (int)$_GET['claim_id'] : 0;
if ($claimId <= 0) { die('Invalid claim'); }

// Load claim and project
$sql = "SELECT w.id, ss.project_id, p.project_name
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
if (!$claim) { die('Claim not found'); }

$role = $_SESSION['role'] ?? 'user';
$userId = (int)($_SESSION['user_id'] ?? 0);
$allowed = getAllowedProjectIds($conn, $userId, $role);
if (is_array($allowed) && !in_array((int)$claim['project_id'], $allowed, true)) { die('Unauthorized'); }
if (!isAdminRole()) { die('Admins only'); }

// Pallets for this project
$pallets = [];
$q = $conn->prepare('SELECT id, COALESCE(pallet_identifier, CONCAT("ID ", id)) label FROM inventory_pallets WHERE assigned_project_id = ? ORDER BY id DESC LIMIT 1000');
$pid = (int)$claim['project_id'];
$q->bind_param('i', $pid);
$q->execute();
$rs = $q->get_result();
while ($row = $rs->fetch_assoc()) { $pallets[] = $row; }
$q->close();

$linkedIds = listLinkedReplacementPalletIds($conn, $claimId);
$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Link Replacement Pallets · Claim #<?php echo (int)$claimId; ?></title>
  <link rel="stylesheet" href="portal.css">
  <style>
    .container { max-width: 1100px; margin:20px auto; background:#fff; border:1px solid #e9ecef; border-radius:14px; box-shadow:0 12px 36px rgba(0,0,0,0.06); }
    .header { padding:16px 18px; border-bottom:1px solid #eef2f6; font-weight:700; }
    .body { padding:18px; }
    .grid { display:grid; grid-template-columns: repeat(auto-fill, minmax(260px,1fr)); gap:12px; }
    .card { border:1px solid #e9ecef; border-radius:10px; padding:10px 12px; display:flex; align-items:center; justify-content:space-between; }
    .actions { display:flex; gap:8px; margin-top:16px; }
    .btn { border:none; border-radius:10px; padding:10px 14px; font-weight:600; cursor:pointer; }
    .btn-primary { background: linear-gradient(135deg, #488C9A, #3A6E7F); color:#fff; }
    .btn-secondary { background:#f8f9fa; border:1px solid #e9ecef; }
  </style>
</head>
<body>
<?php include 'header.php'; ?>
<div class="container">
  <div class="header">Link Replacement Pallets<br><span style="font-weight:400; color:#6c757d;">Project: <?php echo htmlspecialchars($claim['project_name']); ?> · Claim #<?php echo (int)$claimId; ?></span></div>
  <div class="body">
    <form method="post" action="process_warranty_update.php">
      <input type="hidden" name="claim_id" value="<?php echo (int)$claimId; ?>">
      <input type="hidden" name="resolution_type" value="Replacement">
      <div class="grid">
        <?php foreach ($pallets as $p): $checked = in_array((int)$p['id'], $linkedIds, true) ? 'checked' : ''; ?>
          <label class="card">
            <span><?php echo htmlspecialchars($p['label']); ?></span>
            <input type="checkbox" name="replacement_pallets[]" value="<?php echo (int)$p['id']; ?>" <?php echo $checked; ?>>
          </label>
        <?php endforeach; ?>
      </div>
      <div class="actions">
        <a class="btn btn-secondary" href="warranty_detail.php?id=<?php echo (int)$claimId; ?>">Cancel</a>
        <button type="submit" class="btn btn-primary">Save Links</button>
      </div>
    </form>
  </div>
</div>
</body>
</html>
