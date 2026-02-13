<?php
session_name("logistics_session");
session_start();

/* ───────────────────────────── CSRF TOKEN ────────────────────────────── */
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

/* ─────────────────────────── SECURITY / AUTH ─────────────────────────── */
if (!isset($_SESSION['user_id']) ||
    !in_array($_SESSION['role'], ['global_admin', 'admin'])) {
    header("Location: unauthorized.php");
    exit();
}

/* ───────────────────────── PARAMS & CONNECTION ───────────────────────── */
if (!isset($_GET['pallet_id']) || !ctype_digit((string)$_GET['pallet_id'])) {
    die("Invalid or missing Pallet ID.");
}
$pallet_id = (int)$_GET['pallet_id'];

require_once '../config.php';
$conn = getDBConnection();
if (!$conn) die("Connection failed");

$pallet = null;
$errorMessage = '';
$successMessage = '';
$breadcrumbProjectId = 0;

/* ───────────────── FETCH CURRENT PALLET DETAILS ───────────────── */
try {
    // Fetch pallet details AND the unassigned_module_id if linked
    $stmt = $conn->prepare("SELECT ip.*, umi.unassigned_module_id 
                           FROM inventory_pallets ip
                           LEFT JOIN unassigned_module_items umi ON ip.unassigned_module_item_id = umi.id
                           WHERE ip.id = ?");
    if (!$stmt) throw new Exception("Failed to prepare pallet query: " . $conn->error);
    $stmt->bind_param("i", $pallet_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows === 0) {
        throw new Exception("Pallet with ID {$pallet_id} not found.");
    }
    $pallet = $result->fetch_assoc();
    // Derive project id for breadcrumbs
    if ($pallet) {
        $breadcrumbProjectId = (int)($pallet['current_project_id'] ?? 0);
        if ($breadcrumbProjectId <= 0) { $breadcrumbProjectId = (int)($pallet['assigned_project_id'] ?? 0); }
        if ($breadcrumbProjectId <= 0 && !empty($pallet['unassigned_module_id'])) {
            $stmtProj = $conn->prepare("SELECT project_id FROM modules WHERE id = ? LIMIT 1");
            if ($stmtProj) {
                $unassignedModuleId = (int)$pallet['unassigned_module_id'];
                $stmtProj->bind_param("i", $unassignedModuleId);
                $stmtProj->execute();
                $stmtProj->bind_result($projIdTmp);
                if ($stmtProj->fetch()) { $breadcrumbProjectId = (int)$projIdTmp; }
                $stmtProj->close();
            }
        }
    }
    $stmt->close();
} catch (Exception $e) {
    $errorMessage = $e->getMessage();
    // Optionally redirect or display a cleaner error page if pallet not found
    // For now, we let the script continue to show the error message
}


/* ─────────────────────── HANDLE FORM SUBMISSION (POST) ─────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_pallet'])) {

    /* --- CSRF Check --- */
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $errorMessage = "Invalid request (CSRF token mismatch).";
    } elseif (!$pallet) {
        $errorMessage = "Cannot update: Pallet data not loaded.";
    } else {
        $conn->begin_transaction();
        try {
            /* --- Collect Basic Inputs --- */
            $new_identifier = trim($_POST['pallet_identifier'] ?? '');

            /* --- Basic Validation --- */
            if (empty($new_identifier)) throw new Exception("Pallet Identifier cannot be empty.");

            /* --- Prepare and Execute Update --- */
            // Edit Pallet now only updates identifier. Flash test data lives at module document level.
            $sql_update = "UPDATE inventory_pallets SET 
                                pallet_identifier = ? 
                           WHERE id = ?";
            $stmt_update = $conn->prepare($sql_update);
            if (!$stmt_update) throw new Exception("Failed to prepare update statement: " . $conn->error);
            
            $stmt_update->bind_param("si", 
                $new_identifier,
                $pallet_id
            );

            if ($stmt_update->execute()) {
                $conn->commit();
                $successMessage = "Pallet '{$new_identifier}' (ID: {$pallet_id}) updated successfully.";
                $_SESSION['manage_pallets_message'] = $successMessage;
                $_SESSION['create_shipment_message'] = $successMessage;
                
                $redirectAfterSave = 'create_shipment.php';
                if ($breadcrumbProjectId > 0) {
                    $redirectAfterSave .= '?project_id=' . (int)$breadcrumbProjectId;
                }
                header("Location: " . $redirectAfterSave);
                exit();
            } else {
                throw new Exception("Failed to execute update: " . $stmt_update->error);
            }

        } catch (Exception $e) {
            $conn->rollback();
            $errorMessage = $e->getMessage();
        }
    }
}


/* ──────────────────────────── VIEW (HTML FORM) ──────────────────────────── */
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Edit Pallet <?php echo $pallet ? '- ' . htmlspecialchars($pallet['pallet_identifier']) : ''; ?></title>
<link rel="stylesheet" href="portal.css">
<link rel="icon" href="pictures/favicon.png">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;500;600;700&display=swap" rel="stylesheet">
<style>
  .edit-pallet-page { max-width: 980px; margin: 0 auto; padding: 0 20px 26px; }
  .form-header {
      background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%);
      border-radius: 24px;
      padding: 28px 30px;
      margin: 8px 0 18px;
      box-shadow: 0 8px 32px rgba(0, 0, 0, 0.06);
      border: 1px solid rgba(72, 140, 154, 0.08);
      position: relative;
      overflow: hidden;
  }
  .form-header::before {
      content: '';
      position: absolute;
      top: 0;
      left: 0;
      right: 0;
      height: 4px;
      background: linear-gradient(90deg, #488C9A 0%, #293E4C 100%);
  }
  .header-title {
      margin: 0 0 6px;
      color: #293E4C;
      font-size: 1.8rem;
      font-weight: 700;
  }
  .header-subtitle {
      margin: 0;
      color: #6c757d;
      font-size: 0.95rem;
  }
  .card {
      background: #fff;
      border-radius: 14px;
      padding: 20px;
      border: 1px solid #e9ecef;
      box-shadow: 0 2px 10px rgba(0,0,0,0.04);
      margin-bottom: 16px;
  }
  .card h2 {
      margin: 0 0 14px;
      color: #293E4C;
      font-size: 1.05rem;
      font-weight: 700;
  }
  .field-label {
      display: block;
      margin-bottom: 8px;
      font-weight: 600;
      color: #495057;
  }
  .text-input {
      width: 100%;
      padding: 11px 12px;
      border: 1px solid #ced4da;
      border-radius: 8px;
      font-size: 1rem;
      color: #293E4C;
      transition: border-color 0.2s ease, box-shadow 0.2s ease;
      box-sizing: border-box;
  }
  .text-input:focus {
      outline: none;
      border-color: #488C9A;
      box-shadow: 0 0 0 3px rgba(72, 140, 154, 0.15);
  }
  .helper-text {
      margin: 8px 0 0;
      font-size: 0.84rem;
      color: #6c757d;
      line-height: 1.35;
  }
  .readonly-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
      gap: 12px;
  }
  .readonly-item {
      background: #f8f9fa;
      border: 1px solid #e9ecef;
      border-radius: 10px;
      padding: 12px;
  }
  .readonly-k {
      display: block;
      font-size: 0.78rem;
      color: #6c757d;
      margin-bottom: 4px;
      text-transform: uppercase;
      letter-spacing: 0.03em;
  }
  .readonly-v {
      display: block;
      font-weight: 700;
      color: #293E4C;
      font-size: 1rem;
  }
  .actions {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 12px;
      margin-top: 18px;
      flex-wrap: wrap;
  }
  .btn-primary {
      background: linear-gradient(135deg, #488C9A 0%, #3b7683 100%);
      color: #fff;
      padding: 11px 20px;
      border: none;
      border-radius: 8px;
      cursor: pointer;
      font-size: 0.95rem;
      font-weight: 600;
      transition: transform 0.2s ease, box-shadow 0.2s ease;
  }
  .btn-primary:hover { transform: translateY(-1px); box-shadow: 0 6px 16px rgba(72,140,154,0.25); }
  .btn-link {
      color: #5f6f7a;
      text-decoration: none;
      font-weight: 600;
      font-size: 0.92rem;
  }
  .btn-link:hover { color: #293E4C; text-decoration: underline; }
  .error-message, .success-message {
      padding: 12px 14px;
      margin-bottom: 16px;
      border-radius: 8px;
      border: 1px solid;
  }
  .error-message { background-color: #f8d7da; border-color: #f5c6cb; color: #721c24; }
  .success-message { background-color: #d4edda; border-color: #c3e6cb; color: #155724; }
  @media (max-width: 768px) {
      .edit-pallet-page { padding: 0 12px 20px; }
      .form-header { padding: 20px; border-radius: 16px; }
      .header-title { font-size: 1.4rem; }
  }
</style>
</head>
<body>
<?php include 'header.php'; ?>

<main class="edit-pallet-page">
    <?php
        require_once 'components/breadcrumbs.php';
        $mpUrl = 'create_shipment.php' . ($breadcrumbProjectId > 0 ? ('?project_id='.(int)$breadcrumbProjectId) : '');
        echo slp_render_breadcrumbs([
            'current_label' => 'Edit Pallet',
            'project_id' => (int)$breadcrumbProjectId,
            'extra' => [ ['label' => 'Manage Pallets', 'url' => $mpUrl] ]
        ]);
    ?>
    <?php
        $backUrl = $mpUrl;
    ?>

    <div class="form-header">
        <h1 class="header-title">Edit Pallet</h1>
        <p class="header-subtitle">
            Update the internal pallet identifier. Wattage, quantity, and status are derived from module and shipment workflow.
        </p>
    </div>

    <?php if (!empty($errorMessage)): ?>
        <div class="error-message">
            <strong>Error:</strong> <?php echo htmlspecialchars($errorMessage); ?>
        </div>
    <?php endif; ?>
    <?php if (isset($_SESSION['manage_pallets_message'])): ?>
        <div class="success-message">
            <?php echo htmlspecialchars($_SESSION['manage_pallets_message']); unset($_SESSION['manage_pallets_message']); ?>
        </div>
    <?php endif; ?>
    <?php if ($pallet): // Only show form if pallet was loaded ?>
    <form action="edit_pallet.php?pallet_id=<?php echo $pallet_id; ?>" method="post">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">

        <div class="card">
            <h2>Pallet Information</h2>
            <label class="field-label" for="pallet_identifier">Internal Pallet Identifier</label>
            <input
                class="text-input"
                type="text"
                id="pallet_identifier"
                name="pallet_identifier"
                value="<?php echo htmlspecialchars($pallet['pallet_identifier'] ?? ''); ?>"
                required
            >
            <p class="helper-text">
                This is the internal Solterra label. Manufacturer pallet IDs should be linked separately through import/reconciliation workflow.
            </p>
        </div>

        <div class="card">
            <h2>Read-Only Context</h2>
            <div class="readonly-grid">
                <div class="readonly-item">
                    <span class="readonly-k">Pallet ID</span>
                    <span class="readonly-v"><?php echo (int)$pallet_id; ?></span>
                </div>
                <div class="readonly-item">
                    <span class="readonly-k">Wattage</span>
                    <span class="readonly-v"><?php echo (int)($pallet['wattage'] ?? 0); ?>W</span>
                </div>
                <div class="readonly-item">
                    <span class="readonly-k">Quantity</span>
                    <span class="readonly-v"><?php echo number_format((int)($pallet['quantity'] ?? 0)); ?></span>
                </div>
                <div class="readonly-item">
                    <span class="readonly-k">Status</span>
                    <span class="readonly-v"><?php echo htmlspecialchars((string)($pallet['status'] ?? 'N/A')); ?></span>
                </div>
            </div>
            <p class="helper-text">
                Wattage, quantity, and status are managed by batch/palletization and shipment state, so they are intentionally read-only here.
            </p>
        </div>

        <div class="actions">
            <a class="btn-link" href="<?php echo htmlspecialchars($backUrl); ?>">Cancel &amp; Go Back</a>
            <button class="btn-primary" type="submit" name="update_pallet">Save Identifier</button>
        </div>
    </form>
    <?php endif; // End if($pallet) ?>
</main>

</body>
</html>
<?php if ($conn) $conn->close(); ?> 
