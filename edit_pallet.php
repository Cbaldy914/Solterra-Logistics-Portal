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
            // Wattage is no longer editable - use current value
            $current_wattage = $pallet['wattage'];
            // $new_quantity = isset($_POST['quantity']) ? intval($_POST['quantity']) : 0; // No longer editable
            // $new_status = trim($_POST['status'] ?? ''); // No longer editable
            
            // Get current quantity and status from the loaded pallet data
            $current_quantity = $pallet['quantity'];
            $current_status = $pallet['status'];

            /* --- Basic Validation --- */
            if (empty($new_identifier)) throw new Exception("Pallet Identifier cannot be empty.");
            // No need to validate wattage/quantity/status from POST anymore

            /* --- Handle Flash Test Data --- */
            $current_flash_test_path = $pallet['flash_test_data'];
            $new_flash_test_path = $current_flash_test_path; // Assume no change initially
            $remove_current = isset($_POST['remove_flash_test']) && $_POST['remove_flash_test'] == '1';
            $file_uploaded = isset($_FILES['flash_test_file']) && $_FILES['flash_test_file']['error'] === UPLOAD_ERR_OK;

            // 1. Handle Removal Request
            if ($remove_current && !empty($current_flash_test_path) && file_exists($current_flash_test_path)) {
                if (!unlink($current_flash_test_path)) {
                    error_log("Failed to delete old flash test file: {$current_flash_test_path}");
                    // Decide if this is a critical error or just a warning
                }
                $new_flash_test_path = null; // Clear the path
            }

            // 2. Handle New Upload
            if ($file_uploaded) {
                $upload_dir = 'uploads/flash_tests/';
                $allowed_types = ['application/pdf', 'text/csv', 'text/plain', 'image/jpeg', 'image/png']; // Example allowed types
                $max_size = 5 * 1024 * 1024; // 5 MB

                $file = $_FILES['flash_test_file'];
                $file_type = mime_content_type($file['tmp_name']); // More reliable type check
                $file_size = $file['size'];
                $file_ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

                if (!in_array($file_type, $allowed_types) && !in_array($file_ext, ['pdf','csv','txt','jpg','jpeg','png'])) { // Check extension as fallback
                    throw new Exception("Invalid file type ({$file_type}/{$file_ext}). Allowed types: PDF, CSV, TXT, JPG, PNG.");
                }
                if ($file_size > $max_size) {
                    throw new Exception("File size exceeds the limit of 5MB.");
                }

                // Create unique filename
                $unique_filename = "flash_P{$pallet_id}_" . time() . "." . $file_ext;
                $destination = $upload_dir . $unique_filename;

                // Ensure upload directory exists
                if (!is_dir($upload_dir)) {
                    if (!mkdir($upload_dir, 0755, true)) {
                        throw new Exception("Failed to create upload directory.");
                    }
                }

                // Move the file
                if (move_uploaded_file($file['tmp_name'], $destination)) {
                    // Delete old file ONLY IF new one uploaded successfully AND remove wasn't checked
                    if (!$remove_current && !empty($current_flash_test_path) && file_exists($current_flash_test_path)) {
                        if (!unlink($current_flash_test_path)) {
                             error_log("Failed to delete old flash test file after new upload: {$current_flash_test_path}");
                             // Log warning, but proceed with update
                        }
                    }
                    $new_flash_test_path = $destination; // Update path to new file
                } else {
                    throw new Exception("Failed to move uploaded file.");
                }
            } elseif ($remove_current) {
                 $new_flash_test_path = null; // Ensure path is cleared if remove was checked and no new file uploaded
            }

            /* --- Prepare and Execute Update --- */
            // Update only identifier and flash_test_data (wattage is now read-only)
            $sql_update = "UPDATE inventory_pallets SET 
                                pallet_identifier = ?, 
                                flash_test_data = ? 
                           WHERE id = ?";
            $stmt_update = $conn->prepare($sql_update);
            if (!$stmt_update) throw new Exception("Failed to prepare update statement: " . $conn->error);
            
            $stmt_update->bind_param("ssi", 
                $new_identifier, 
                $new_flash_test_path, 
                $pallet_id
            );

            if ($stmt_update->execute()) {
                $conn->commit();
                $successMessage = "Pallet '{$new_identifier}' (ID: {$pallet_id}) updated successfully.";
                $_SESSION['manage_pallets_message'] = $successMessage;
                
                // No longer need to check for wattage changes since wattage is read-only
                header("Location: manage_pallets.php");
                exit();
            } else {
                throw new Exception("Failed to execute update: " . $stmt_update->error);
            }

        } catch (Exception $e) {
            $conn->rollback();
            $errorMessage = $e->getMessage();
            // If file was uploaded but DB failed, attempt to delete the newly uploaded file
            if ($file_uploaded && isset($destination) && file_exists($destination)) {
                 @unlink($destination);
            }
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
  main { max-width: 700px; padding: 20px; background-color: #fff; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
  h1 { color: #293E4C; margin-bottom: 25px; text-align: center; }
  form label { display: block; margin-bottom: 8px; font-weight: 600; color: #555; }
  form input[type=text],
  form input[type=number],
  form select,
  form input[type=file] {
      width: 100%;
      padding: 10px;
      margin-bottom: 20px;
      border: 1px solid #ccc;
      border-radius: 4px;
      box-sizing: border-box;
      font-size: 1rem;
  }
  fieldset {
      border: 1px solid #ddd;
      padding: 20px;
      margin-bottom: 25px;
      border-radius: 5px;
  }
  legend { font-weight: bold; color: #488C9A; padding: 0 10px; margin-left: 10px; font-size: 1.1em; }
  button[type=submit] {
      background: #488C9A;
      color: #fff;
      padding: 12px 25px;
      border: none;
      border-radius: 4px;
      cursor: pointer;
      font-size: 1.1em;
      transition: background-color 0.3s ease;
  }
  button[type=submit]:hover { background: #3A6E7F; }
  .back-link { text-align: center; margin-top: 30px; }
  .back-link a { color: #488C9A; text-decoration: none; font-weight: 500; }
  .back-link a:hover { text-decoration: underline; }
  .flash-test-info { margin-bottom: 15px; }
  .flash-test-info span { font-weight: bold; margin-right: 10px; }
  .flash-test-info a { color: #007bff; }
  .remove-label { font-weight: normal; display: inline-block !important; margin-left: 10px;}
  .remove-label input { width: auto !important; margin-right: 5px; vertical-align: middle;}
  .error-message, .success-message { /* Shared style */
    padding: 10px;
    margin-bottom: 20px;
    border-radius: 4px;
    border: 1px solid;
  }
  .error-message { background-color: #f8d7da; border-color: #f5c6cb; color: #721c24; }
  .success-message { background-color: #d4edda; border-color: #c3e6cb; color: #155724; }
</style>
</head>
<body>
<?php include 'header.php'; ?>

<main>
    <h1>Edit Pallet <?php echo $pallet ? '- ' . htmlspecialchars($pallet['pallet_identifier']) : ''; ?></h1>

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
    <form action="edit_pallet.php?pallet_id=<?php echo $pallet_id; ?>" method="post" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">

        <fieldset>
            <legend>Pallet Information</legend>
            <label for="pallet_identifier">Pallet Identifier:</label>
            <input type="text" id="pallet_identifier" name="pallet_identifier" value="<?php echo htmlspecialchars($pallet['pallet_identifier'] ?? ''); ?>" required>

            <label for="wattage">Wattage:</label>
            <input type="text" id="wattage" name="wattage" value="<?php echo htmlspecialchars($pallet['wattage'] ?? ''); ?>" readonly style="background-color: #e9ecef;">
            <small>Wattage is inherited from the module batch and cannot be changed here. To change wattage, edit the original module batch in "Modules" section.</small>

            <label for="quantity">Quantity:</label>
            <input type="number" id="quantity" name="quantity" value="<?php echo htmlspecialchars($pallet['quantity'] ?? ''); ?>" readonly style="background-color: #e9ecef;">
            <small>Quantity cannot be changed here. Adjustments must reflect actual inventory changes, potentially impacting the original module batch.</small>

            <label for="status">Status:</label>
            <input type="text" id="status" name="status" value="<?php echo htmlspecialchars($pallet['status'] ?? ''); ?>" readonly style="background-color: #e9ecef;">
            <small>Status reflects the pallet's condition (e.g., Good, Damaged) and cannot be changed here. Logistical status is derived from location and deliveries.</small>

        </fieldset>

        <fieldset>
            <legend>Flash Test Data</legend>
            
            <?php if (!empty($pallet['flash_test_data'])): ?>
                <div class="flash-test-info">
                    <span>Current File:</span> 
                    <?php 
                        // Basic display - Link should point to a secure download script later
                        echo htmlspecialchars(basename($pallet['flash_test_data'])); 
                        // echo ' <a href="view_flash_test.php?pallet_id=' . $pallet_id . '" target="_blank">(View/Download)</a>'; 
                    ?>
                    <label class="remove-label">
                        <input type="checkbox" name="remove_flash_test" value="1"> Remove current file
                    </label>
                </div>
            <?php else: ?>
                <p>No flash test data currently uploaded.</p>
            <?php endif; ?>

            <label for="flash_test_file">Upload New Flash Test File (Optional):</label>
            <input type="file" id="flash_test_file" name="flash_test_file" accept=".pdf,.csv,.txt,.jpg,.jpeg,.png">
            <small>Allowed types: PDF, CSV, TXT, JPG, PNG. Max size: 5MB.</small>

        </fieldset>

        <button type="submit" name="update_pallet">Update Pallet</button>
    </form>
    <?php endif; // End if($pallet) ?>

    <div class="back-link">
        
    </div>
</main>

</body>
</html>
<?php if ($conn) $conn->close(); ?> 
