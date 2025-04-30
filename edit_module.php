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
$projects_for_account = [];
$account_id_for_admin = null;
$successMessage = "";
$errorMessage   = "";

// --- Fetch Account & Project Info (needed for both GET and POST) ---
if ($role === 'global_admin') {
    // Fetch all accounts
    $sqlAccounts = "SELECT id, name FROM customer_accounts ORDER BY name ASC";
    $resAccounts = $conn->query($sqlAccounts);
    if ($resAccounts) {
        while ($row = $resAccounts->fetch_assoc()) {
            $accounts[] = $row;
        }
    }
    // Fetch all projects
    $sqlAllProj = "SELECT id, project_name, account_id FROM projects ORDER BY account_id, project_name ASC";
    $resAllProj = $conn->query($sqlAllProj);
    if ($resAllProj && $resAllProj->num_rows > 0) {
        while ($proj = $resAllProj->fetch_assoc()) {
            $projects_for_account[] = $proj;
        }
    }
} else { // Role is 'admin'
    // Fetch admin's account ID
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
    // Fetch projects for the admin's account
    $sqlOneProj = "SELECT id, project_name FROM projects WHERE account_id = ? ORDER BY project_name ASC";
    $stmtOneProj = $conn->prepare($sqlOneProj);
    if (!$stmtOneProj) die("Error preparing project lookup: " . $conn->error);
    $stmtOneProj->bind_param("i", $account_id_for_admin);
    $stmtOneProj->execute();
    $resultProj = $stmtOneProj->get_result();
    while ($proj = $resultProj->fetch_assoc()) {
         $projects_for_account[] = $proj;
    }
    $stmtOneProj->close();
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
                $checkStmt = $conn->prepare("SELECT account_id FROM modules WHERE id = ?");
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
            $project_id_input = $_POST['project_id'] ?? '';
            $project_id       = ($project_id_input !== '' && $project_id_input > 0) ? intval($project_id_input) : null;

            if (empty($vendor_name) || empty($initial_location)) {
                throw new Exception("Vendor Name and Initial Location cannot be empty.");
            }
            
            if ($role === 'global_admin' && $project_id !== null) {
                $validProject = false;
                $stmtCheckProj = $conn->prepare("SELECT 1 FROM projects WHERE id = ? AND account_id = ?");
                if ($stmtCheckProj) {
                    $stmtCheckProj->bind_param("ii", $project_id, $account_id_update);
                    $stmtCheckProj->execute();
                    $stmtCheckProj->store_result();
                    if ($stmtCheckProj->num_rows > 0) {
                        $validProject = true;
                    }
                    $stmtCheckProj->close();
                }
                if (!$validProject) {
                     throw new Exception("Selected project does not belong to the selected account.");
                }
            }

            // Update main batch details
            $updateMainStmt = $conn->prepare("UPDATE modules SET account_id=?, vendor_name=?, initial_location=?, project_id=? WHERE id=?");
            if (!$updateMainStmt) throw new Exception("Prepare main update failed: " . $conn->error);
            $updateMainStmt->bind_param("issii", $account_id_update, $vendor_name, $initial_location, $project_id, $batch_id_post);
            if (!$updateMainStmt->execute()) throw new Exception("Execute main update failed: " . $updateMainStmt->error);
            $updateMainStmt->close();

            // Delete existing items
            $deleteItemsStmt = $conn->prepare("DELETE FROM module_items WHERE module_id = ?");
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
                     $insertItemStmt = $conn->prepare("INSERT INTO module_items (module_id, wattage, quantity) VALUES (?, ?, ?)");
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
            header("Location: module_overview.php?batch_id=" . $batch_id_post . "&update_success=1");
            exit();

        } catch (Exception $e) {
            $conn->rollback();
            $errorMessage = "Error updating batch: " . $e->getMessage();
        }
        // Re-fetch data after potential update for display
        $batch_id = $batch_id_post; // Use the ID from the POST
    }
}

// --- Fetch Batch Data for Display (GET request or after POST) ---
if ($batch_id > 0) {
    $stmt = $conn->prepare("SELECT * FROM modules WHERE id = ?");
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
            $errorMessage = "Module batch not found.";
            $batch_data = null; // Ensure batch data is null if not found
        }
        $stmt->close();
    }

    // Fetch items if batch data was found
    if ($batch_data) {
        $stmtItems = $conn->prepare("SELECT id, wattage, quantity FROM module_items WHERE module_id = ? ORDER BY id ASC");
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
    <title>Edit Module Batch</title>
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
        function addWattageField() {
            var container = document.getElementById('wattage-container');
            var index = container.children.length;
            var div = document.createElement('div');
            div.className = 'wattage-entry';
            div.innerHTML = `
                <div>
                    <label for="wattages_${index}">Wattage:</label>
                    <input type="number" step="1" name="wattages[]" id="wattages_${index}" required>
                </div>
                <div>
                    <label for="quantities_${index}">Quantity:</label>
                    <input type="number" step="1" name="quantities[]" id="quantities_${index}" required>
                </div>
                <div class="remove-btn-container">
                    <button type="button" class="remove-wattage-btn" onclick="this.closest('.wattage-entry').remove()">Remove</button>
                </div>
            `;
            container.appendChild(div);
        }

        document.addEventListener('DOMContentLoaded', function() {
            var accountSelect = document.getElementById('account_id');
            var projectSelect = document.getElementById('project_id');
            if (accountSelect && projectSelect && '<?php echo $role; ?>' === 'global_admin') {
                 var allProjectOptions = Array.from(projectSelect.options).slice(1);

                 accountSelect.addEventListener('change', function() {
                     var selectedAccountId = this.value;
                     var previouslySelectedProjectId = projectSelect.value;
                     while (projectSelect.options.length > 1) {
                         projectSelect.remove(1);
                     }

                     var currentProjectStillValid = false;
                     allProjectOptions.forEach(function(option) {
                         var optionAccountId = option.getAttribute('data-account-id');
                         if (selectedAccountId === '' || optionAccountId === selectedAccountId) {
                             var clonedOption = option.cloneNode(true);
                             if(clonedOption.value === previouslySelectedProjectId && optionAccountId === selectedAccountId) {
                                 clonedOption.selected = true;
                                 currentProjectStillValid = true;
                             }
                             projectSelect.add(clonedOption);
                         }
                     });
                     if (!currentProjectStillValid) {
                          projectSelect.value = '';
                     }
                 });
                 if (accountSelect.value !== '') {
                      accountSelect.dispatchEvent(new Event('change'));
                 }
            }
        });
    </script>
</head>
<body>
<?php include 'header.php'; ?>
<main>
    <h1>Edit Module Batch #<?php echo $batch_id; ?></h1>

    <?php if (!empty($successMessage)): ?>
        <div class="success-message"><?php echo htmlspecialchars($successMessage); ?></div>
    <?php endif; ?>
    <?php if (!empty($errorMessage)): ?>
        <div class="error-message"><?php echo htmlspecialchars($errorMessage); ?></div>
    <?php endif; ?>

    <?php if ($batch_data): ?>
        <form action="edit_module.php?batch_id=<?php echo $batch_id; ?>" method="POST">
            <input type="hidden" name="batch_id" value="<?php echo $batch_id; ?>">

            <?php if ($role === 'global_admin'): ?>
                <label for="account_id">Account Name:</label>
                <select name="account_id" id="account_id" required>
                    <option value="">--Select Account--</option>
                    <?php foreach ($accounts as $acc): ?>
                        <option value="<?php echo $acc['id']; ?>" <?php echo ($batch_data['account_id'] == $acc['id']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($acc['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            <?php else: // Admin Role - display name, hidden input ?>
                 <?php
                     $adminAccountName = '[Account Name Not Found]';
                     $connTemp = getDBConnection(); // Temporary connection
                     if ($connTemp) {
                         $stmtAccName = $connTemp->prepare("SELECT name FROM customer_accounts WHERE id = ?");
                         if ($stmtAccName) {
                             $stmtAccName->bind_param("i", $account_id_for_admin);
                             $stmtAccName->execute();
                             $stmtAccName->bind_result($fetchedName);
                             if ($stmtAccName->fetch()) { $adminAccountName = $fetchedName; }
                             $stmtAccName->close();
                         }
                         $connTemp->close();
                     }
                     echo "<p><strong>Account:</strong> " . htmlspecialchars($adminAccountName) . "</p>";
                 ?>
            <?php endif; ?>

            <label for="vendor_name">Vendor Name:</label>
            <input type="text" name="vendor_name" id="vendor_name" value="<?php echo htmlspecialchars($batch_data['vendor_name']); ?>" required>

            <label for="initial_location">Initial Location:</label>
            <input type="text" name="initial_location" id="initial_location" value="<?php echo htmlspecialchars($batch_data['initial_location']); ?>" required>

            <label for="project_id">Assign to Project:</label>
            <select name="project_id" id="project_id">
                 <option value="">-- None (Unassigned Stock) --</option>
                 <?php foreach ($projects_for_account as $proj): ?>
                      <option value="<?php echo $proj['id']; ?>" 
                              <?php if ($role === 'global_admin') echo ' data-account-id="' . $proj['account_id'] . '"'; ?>
                              <?php echo ($batch_data['project_id'] == $proj['id']) ? 'selected' : ''; ?>>
                          <?php echo htmlspecialchars($proj['project_name']); ?>
                          <?php if ($role === 'global_admin') echo ' (Account ID: ' . $proj['account_id'] . ')'; ?>
                      </option>
                 <?php endforeach; ?>
            </select>

            <div class="section-title">Module Wattage and Quantities</div>
            <div id="wattage-container">
                <?php foreach ($batch_items as $index => $item): ?>
                    <div class="wattage-entry">
                        <div>
                            <label for="wattages_<?php echo $index; ?>">Wattage:</label>
                            <input type="number" step="1" name="wattages[]" id="wattages_<?php echo $index; ?>" value="<?php echo htmlspecialchars($item['wattage']); ?>" required>
                        </div>
                        <div>
                            <label for="quantities_<?php echo $index; ?>">Quantity:</label>
                            <input type="number" step="1" name="quantities[]" id="quantities_<?php echo $index; ?>" value="<?php echo htmlspecialchars($item['quantity']); ?>" required>
                        </div>
                        <div class="remove-btn-container">
                            <button type="button" class="remove-wattage-btn" onclick="this.closest('.wattage-entry').remove()">Remove</button>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            <button type="button" class="btn-add-wattage" onclick="addWattageField()">+ Add Wattage/Quantity</button>

            <input type="submit" value="Save Changes" class="btn-submit">
        </form>
    <?php elseif (empty($errorMessage)): ?>
        <p>Loading batch data...</p>
    <?php endif; ?>

    <div class="back-link" style="margin-top: 20px;">
        <a href="module_overview.php?batch_id=<?php echo $batch_id; ?>">&larr; Back to Module Overview</a>
    </div>
</main>
</body>
</html>
