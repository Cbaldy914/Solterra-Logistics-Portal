<?php
session_name("logistics_session");
session_start();

// Ensure user has role admin or global_admin
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin','global_admin'])) {
    die("Unauthorized access.");
}

// Database connection
require_once '../config.php';
$conn = getDBConnection();
if (!$conn) {
    die("Database connection failed.");
}

$role    = $_SESSION['role'];
$user_id = $_SESSION['user_id'];
$batch_id = isset($_GET['batch_id']) ? intval($_GET['batch_id']) : 0;

// Variables for form data and messages
$batch_data = null;
$batch_items = [];
$accounts = [];
$account_id_for_admin = null;
$successMessage = "";
$errorMessage   = "";

// --- Fetch Account Info (needed for both GET and POST if admin) ---
if ($role === 'global_admin') {
    $sqlAccounts = "SELECT id, name FROM customer_accounts ORDER BY name ASC";
    $resAccounts = $conn->query($sqlAccounts);
    if ($resAccounts) {
        while ($row = $resAccounts->fetch_assoc()) {
            $accounts[] = $row;
        }
    }
} else { // Role is 'admin'
    $sqlAdminAcc = "SELECT account_id FROM customer_account_users WHERE user_id = ? AND role = 'admin' LIMIT 1";
    $stmtAdminAcc = $conn->prepare($sqlAdminAcc);
    if ($stmtAdminAcc) {
        $stmtAdminAcc->bind_param("i", $user_id);
        $stmtAdminAcc->execute();
        $stmtAdminAcc->bind_result($account_id_for_admin);
        $stmtAdminAcc->fetch();
        $stmtAdminAcc->close();
    }
    if (!$account_id_for_admin) {
        die("Admin user not associated with any account.");
    }
}

// --- Handle POST Request (Save Changes) --- 
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $batch_id_post = isset($_POST['batch_id']) ? intval($_POST['batch_id']) : 0;
    if ($batch_id_post <= 0) {
        $errorMessage = "Invalid Batch ID provided for update.";
    } else {
        $conn->begin_transaction();
        try {
            // Validate account access for admin
            if ($role === 'admin') {
                $checkStmt = $conn->prepare("SELECT account_id FROM unassigned_modules WHERE id = ?");
                $checkStmt->bind_param("i", $batch_id_post);
                $checkStmt->execute();
                $checkStmt->bind_result($current_account_id);
                if (!$checkStmt->fetch() || $current_account_id != $account_id_for_admin) {
                    $checkStmt->close();
                    throw new Exception("Access denied: You do not have permission to edit this batch.");
                }
                $checkStmt->close();
            }
            
            // Get account_id for update
            $account_id_update = ($role === 'global_admin') 
                ? (isset($_POST['account_id']) ? intval($_POST['account_id']) : 0)
                : $account_id_for_admin;
            if ($account_id_update <= 0) {
                throw new Exception("Invalid Account selected.");
            }

            // Get other main fields
            $vendor_name      = trim($_POST['vendor_name'] ?? '');
            $initial_location = trim($_POST['initial_location'] ?? '');
            if (empty($vendor_name) || empty($initial_location)) {
                throw new Exception("Vendor Name and Initial Location cannot be empty.");
            }

            // Update main batch details
            $updateMainStmt = $conn->prepare("UPDATE unassigned_modules SET account_id=?, vendor_name=?, initial_location=? WHERE id=?");
            if (!$updateMainStmt) throw new Exception("Prepare main update failed: " . $conn->error);
            $updateMainStmt->bind_param("issi", $account_id_update, $vendor_name, $initial_location, $batch_id_post);
            if (!$updateMainStmt->execute()) throw new Exception("Execute main update failed: " . $updateMainStmt->error);
            $updateMainStmt->close();

            // Delete existing items
            $deleteItemsStmt = $conn->prepare("DELETE FROM unassigned_module_items WHERE unassigned_module_id = ?");
            if (!$deleteItemsStmt) throw new Exception("Prepare delete items failed: " . $conn->error);
            $deleteItemsStmt->bind_param("i", $batch_id_post);
            if (!$deleteItemsStmt->execute()) throw new Exception("Execute delete items failed: " . $deleteItemsStmt->error);
            $deleteItemsStmt->close();

            // Insert new items
            $items_added_count = 0;
            if (isset($_POST['wattages'], $_POST['quantities'])) {
                $wattages = $_POST['wattages'];
                $quantities = $_POST['quantities'];

                if (count($wattages) === count($quantities)) {
                     $insertItemStmt = $conn->prepare("INSERT INTO unassigned_module_items (unassigned_module_id, wattage, quantity) VALUES (?, ?, ?)");
                     if (!$insertItemStmt) throw new Exception("Prepare insert item failed: " . $conn->error);

                    for ($i = 0; $i < count($wattages); $i++) {
                        $w = intval($wattages[$i]);
                        $q = intval($quantities[$i]);
                        if ($w > 0 && $q > 0) {
                            $insertItemStmt->bind_param("iii", $batch_id_post, $w, $q);
                            if (!$insertItemStmt->execute()) throw new Exception("Execute insert item failed: " . $insertItemStmt->error);
                            $items_added_count++;
                        }
                    }
                    $insertItemStmt->close();
                } else {
                     throw new Exception("Mismatch between wattage and quantity inputs.");
                }
            }
            if ($items_added_count === 0) {
                 throw new Exception("At least one valid Wattage/Quantity item must be provided.");
            }

            $conn->commit();
            $successMessage = "Batch updated successfully!";
            // Optional: Redirect after success
            // header("Location: manage_projects.php?update_success=1");
            // exit();

        } catch (Exception $e) {
            $conn->rollback();
            $errorMessage = "Error updating batch: " . $e->getMessage();
        }
        // Re-fetch data after potential update for display
        $batch_id = $batch_id_post; // Use the ID from the POST
    }
}

// --- Fetch Batch Data for Display (GET request or after POST) ---
if ($batch_id > 0 && empty($errorMessage)) { // Only fetch if ID is valid and no POST error stopped us
$stmt = $conn->prepare("SELECT * FROM unassigned_modules WHERE id = ?");
    if (!$stmt) {
        $errorMessage = "Error preparing batch fetch: " . $conn->error;
    } else {
        $stmt->bind_param("i", $batch_id);
$stmt->execute();
$result = $stmt->get_result();
        if ($result->num_rows > 0) {
            $batch_data = $result->fetch_assoc();
            // Security check for admin role
            if ($role === 'admin' && $batch_data['account_id'] != $account_id_for_admin) {
                $batch_data = null; // Clear data if admin doesn't own this batch
                die("Access Denied: You do not have permission to view this batch.");
            }
        } else {
            $errorMessage = "Unassigned module batch not found.";
        }
        $stmt->close();
    }

    // Fetch items if batch data was found
    if ($batch_data) {
        $stmtItems = $conn->prepare("SELECT id, wattage, quantity FROM unassigned_module_items WHERE unassigned_module_id = ? ORDER BY id ASC");
        if (!$stmtItems) {
            $errorMessage = "Error preparing items fetch: " . $conn->error;
        } else {
            $stmtItems->bind_param("i", $batch_id);
            $stmtItems->execute();
            $resultItems = $stmtItems->get_result();
            while ($row = $resultItems->fetch_assoc()) {
                $batch_items[] = $row;
            }
            $stmtItems->close();
        }
    }
} elseif ($batch_id <= 0 && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $errorMessage = "No Batch ID provided.";
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Edit Unassigned Module Batch</title>
    <link rel="stylesheet" href="portal.css">
    <link rel="icon" href="pictures/favicon.png" type="image/x-icon">
    <link href="https://fonts.googleapis.com/css?family=Poppins:300,400,500,600,700&display=swap" rel="stylesheet">
    <style>
        main {
            max-width: 800px;
        }
        form label {
            display: block;
            margin-top: 15px;
            font-weight: 600;
        }
        form input[type="text"],
        form input[type="number"],
        form input[type="date"],
        form input[type="file"],
        form select {
            width: 100%;
            padding: 8px;
            margin-top: 5px;
            border-radius: 4px;
            border: 1px solid #ccc;
            box-sizing: border-box;
        }
        .wattage-entry {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-bottom: 10px;
            align-items: flex-end; 
        }
        .wattage-entry > div {
            flex: 1; 
            min-width: 150px; 
        }
         .wattage-entry > div label {
             margin-top: 0;
         }
        .wattage-entry .remove-btn-container {
             flex: 0 0 auto; 
             margin-left: 10px; 
        }
        .wattage-entry button {
            background: #dc3545; 
            color: #fff;
            border: none;
            padding: 8px 14px;
            cursor: pointer;
            border-radius: 4px;
            height: 36px; 
        }
         .wattage-entry button:hover {
             background: #c82333;
         }
         .btn-add-wattage {
             background: #488C9A;
             color: #fff;
             border: none;
             padding: 8px 14px;
             cursor: pointer;
             border-radius: 4px;
             margin-top: 10px;
        }
        .btn-add-wattage:hover {
            background: #293E4C;
        }
        .btn-submit {
            background: #293E4C;
            color: #fff;
            border: none;
            padding: 12px 20px;
            cursor: pointer;
            border-radius: 4px;
            font-size: 1rem;
            margin-top: 20px;
            display: block;
        }
        .btn-submit:hover {
            background: #488C9A;
        }
        .section-title {
            margin-top: 30px;
            margin-bottom: 10px;
            font-size: 1.1rem;
            font-weight: 600;
        }
        .success-message {
            color: green;
            margin: 20px 0;
            padding: 10px;
            border: 1px solid green;
            background-color: #e6ffed;
            text-align: center;
            border-radius: 4px;
        }
        .error-message {
            color: red;
            margin: 20px 0;
            padding: 10px;
            border: 1px solid red;
            background-color: #ffe6e6;
            text-align: center;
            border-radius: 4px;
        }
    </style>
    <script>
        function addWattageField(wattage = '', quantity = '') {
            var container = document.getElementById('wattage-container');
            var index = container.children.length;

            var div = document.createElement('div');
            div.className = 'wattage-entry';

            var wattageDiv = document.createElement('div');
            var wattageLabel = document.createElement('label');
            wattageLabel.textContent = 'Wattage:';
            wattageLabel.htmlFor = 'wattages_' + index;
            var wattageInput = document.createElement('input');
            wattageInput.type = 'number';
            wattageInput.step = '1';
            wattageInput.name = 'wattages[' + index + ']';
            wattageInput.id = 'wattages_' + index;
            wattageInput.value = wattage; // Pre-fill value
            wattageInput.required = true;
            wattageDiv.appendChild(wattageLabel);
            wattageDiv.appendChild(wattageInput);

            var quantityDiv = document.createElement('div');
            var quantityLabel = document.createElement('label');
            quantityLabel.textContent = 'Quantity:';
            quantityLabel.htmlFor = 'quantities_' + index;
            var quantityInput = document.createElement('input');
            quantityInput.type = 'number';
            quantityInput.step = '1';
            quantityInput.name = 'quantities[' + index + ']';
            quantityInput.id = 'quantities_' + index;
            quantityInput.value = quantity; // Pre-fill value
            quantityInput.required = true;
            quantityDiv.appendChild(quantityLabel);
            quantityDiv.appendChild(quantityInput);

            var removeBtnDiv = document.createElement('div');
            removeBtnDiv.className = 'remove-btn-container';
            var removeButton = document.createElement('button');
            removeButton.type = 'button';
            removeButton.textContent = 'Remove';
            removeButton.onclick = function() {
                container.removeChild(div);
            };
            removeBtnDiv.appendChild(removeButton);

            div.appendChild(wattageDiv);
            div.appendChild(quantityDiv);
            div.appendChild(removeBtnDiv);

            container.appendChild(div);
        }

        // Function to pre-populate existing items on page load
        function populateExistingItems() {
            <?php foreach ($batch_items as $item): ?>
            addWattageField('<?php echo htmlspecialchars(isset($item["wattage"]) ? $item["wattage"] : '', ENT_QUOTES); ?>', '<?php echo htmlspecialchars(isset($item["quantity"]) ? $item["quantity"] : '', ENT_QUOTES); ?>');
            <?php endforeach; ?>
        }
    </script>
</head>
<body <?php if (!empty($batch_items)) echo 'onload="populateExistingItems()"' ?>>
<?php include 'header.php'; ?>
<main>
    <h1>Edit Unassigned Module Batch</h1>

    <?php if (!empty($successMessage)): ?>
        <div class="success-message"><strong><?php echo htmlspecialchars($successMessage); ?></strong></div>
    <?php endif; ?>

    <?php if (!empty($errorMessage)): ?>
        <div class="error-message"><strong>Error:</strong> <?php echo htmlspecialchars($errorMessage); ?></div>
    <?php endif; ?>

    <?php if ($batch_data): ?>
        <form action="edit_unassigned_module.php?batch_id=<?php echo $batch_id; ?>" method="POST">
            <input type="hidden" name="batch_id" value="<?php echo $batch_id; ?>">

            <label for="account_id">Account Name:</label>
            <?php if ($role === 'global_admin'): ?>
                <select name="account_id" id="account_id" required>
                    <option value="">--Select Account--</option>
                    <?php foreach ($accounts as $acc): ?>
                        <option value="<?php echo $acc['id']; ?>" <?php echo ($acc['id'] == $batch_data['account_id']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($acc['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            <?php else: ?>
                 <?php
                    // Find and display the account name for the admin
                    $connDisplay = getDBConnection(); // Need a connection again if closed after POST
                    $stmtAccName = $connDisplay->prepare("SELECT name FROM customer_accounts WHERE id = ?");
                    $adminAccountName = 'N/A';
                    if ($stmtAccName) {
                        $stmtAccName->bind_param("i", $batch_data['account_id']);
                        $stmtAccName->execute();
                        $stmtAccName->bind_result($adminAccountName);
                        $stmtAccName->fetch();
                        $stmtAccName->close();
                    }
                    $connDisplay->close(); 
                 ?>
                 <input type="text" value="<?php echo htmlspecialchars($adminAccountName); ?>" disabled>
                 <input type="hidden" name="account_id" value="<?php echo $batch_data['account_id']; ?>">
            <?php endif; ?>

            <label for="vendor_name">Vendor Name:</label>
            <input type="text" name="vendor_name" id="vendor_name" value="<?php echo htmlspecialchars($batch_data['vendor_name']); ?>" required>

            <label for="initial_location">Initial Location:</label>
            <input type="text" name="initial_location" id="initial_location" value="<?php echo htmlspecialchars($batch_data['initial_location']); ?>" required>

            <div class="section-title">Module Wattage and Quantities</div>
            <div id="wattage-container">
                <!-- Items will be added here by JS -->
            </div>
            <button type="button" class="btn-add-wattage" onclick="addWattageField()">+ Add Wattage/Quantity</button>

            <input type="submit" value="Save Changes" class="btn-submit">
    </form>
    <?php elseif (empty($errorMessage)): ?>
        <p>Loading batch data...</p> 
    <?php endif; ?> 
    <br>
    <a href="manage_projects.php">Back to Manage Projects</a>
</main>
</body>
</html>
