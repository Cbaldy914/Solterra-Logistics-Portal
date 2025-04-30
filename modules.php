<?php
session_name("logistics_session");
session_start();

// Ensure user has role admin or global_admin
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin','global_admin'])) {
    die("Unauthorized: You must be 'admin' or 'global_admin' to manage modules.");
}

// Database connection
require_once '../config.php';
$conn = getDBConnection(); // Keep connection open initially
if (!$conn) {
    die("Database connection failed.");
}

$role    = $_SESSION['role'];
$user_id = $_SESSION['user_id'];

// --- Account Fetching Logic (needed for form and potentially for data fetch) ---
$account_id_for_admin = null;
$accounts             = [];
$projects_for_account = [];

if ($role === 'global_admin') {
    $sqlAllAcc = "SELECT id, name FROM customer_accounts ORDER BY name ASC";
    $resAllAcc = $conn->query($sqlAllAcc);
    if ($resAllAcc && $resAllAcc->num_rows > 0) {
        while ($row = $resAllAcc->fetch_assoc()) {
            $accounts[] = $row;
        }
    }
    $sqlAllProj = "SELECT id, project_name, account_id FROM projects ORDER BY account_id, project_name ASC";
    $resAllProj = $conn->query($sqlAllProj);
    if ($resAllProj && $resAllProj->num_rows > 0) {
        while ($proj = $resAllProj->fetch_assoc()) {
            $projects_for_account[] = $proj;
        }
    }
} else { // admin role
    $sqlOneAcc = "SELECT account_id FROM customer_account_users WHERE user_id = ? AND role = 'admin' LIMIT 1";
    $stmtOneAcc = $conn->prepare($sqlOneAcc);
    if (!$stmtOneAcc) die("Error preparing account lookup: " . $conn->error);
    $stmtOneAcc->bind_param("i", $user_id);
    $stmtOneAcc->execute();
    $stmtOneAcc->bind_result($acctID);
    if ($stmtOneAcc->fetch()) {
        $account_id_for_admin = $acctID;
    }
    $stmtOneAcc->close();

    if ($account_id_for_admin) {
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

    if (!$account_id_for_admin) {
        // Handle case where admin has no assigned account - prevent further action
        // For now, let's allow proceeding, the data fetch below will handle empty results.
    }
}

// Prepare variables to hold user messages:
$successMessage = "";
$errorMessage   = "";

// --- Handle POST for adding new modules --- 
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Re-establish connection if it was closed (it shouldn't be closed yet based on new logic)
    if (!$conn || !$conn->ping()) {
        $conn = getDBConnection();
        if (!$conn) {
             $errorMessage = "Database re-connection failed during POST handling.";
             // We might need to exit or handle this more gracefully
        }
    }
    
    if ($conn) { // Proceed only if connection is valid
        $conn->begin_transaction(); // Start transaction
        try {
            // Determine correct account_id (using previously fetched variables)
            if ($role === 'global_admin') {
                $account_id = isset($_POST['account_id']) ? intval($_POST['account_id']) : 0;
                if ($account_id <= 0) {
                    throw new Exception("Please select a valid Account.");
                }
            } else { // admin
                if (!$account_id_for_admin) { // Double check admin has an account
                   throw new Exception("No valid account found for this admin user.");
                }
                $account_id = $account_id_for_admin;
            }
    
            // Gather main fields
            $vendor_name        = trim($_POST['vendor_name'] ?? '');
            $initial_location   = trim($_POST['initial_location'] ?? '');
            $project_id_input   = $_POST['project_id'] ?? '';
            $project_id         = ($project_id_input !== '' && $project_id_input > 0) ? intval($project_id_input) : null;
    
            if ($vendor_name === '' || $initial_location === '') {
                throw new Exception("Vendor Name and Initial Location are required.");
            }
            
            if ($role === 'global_admin' && $project_id !== null) {
                $validProject = false;
                $stmtCheckProj = $conn->prepare("SELECT 1 FROM projects WHERE id = ? AND account_id = ?");
                if ($stmtCheckProj) {
                    $stmtCheckProj->bind_param("ii", $project_id, $account_id);
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
    
            // Insert into modules (including new project_id)
            $stmt = $conn->prepare("INSERT INTO modules (account_id, vendor_name, initial_location, project_id) VALUES (?, ?, ?, ?)");
            if (!$stmt) throw new Exception("Error preparing main insert: " . $conn->error);
            $stmt->bind_param("issi", $account_id, $vendor_name, $initial_location, $project_id);
            if (!$stmt->execute()) throw new Exception("Error inserting module batch: " . $stmt->error);
            $unassigned_module_id = $stmt->insert_id;
            $stmt->close();
    
            // Insert wattage+quantity items
            $wattage_items_added = 0;
            if (isset($_POST['wattages'], $_POST['quantities'])) {
                $wattages   = $_POST['wattages'];
                $quantities = $_POST['quantities'];
    
                if (count($wattages) !== count($quantities)) throw new Exception("Mismatch between wattage[] and quantities[] arrays.");
                
                $stmt2 = $conn->prepare("INSERT INTO unassigned_module_items (unassigned_module_id, wattage, quantity) VALUES (?, ?, ?)");
                if (!$stmt2) throw new Exception("Error preparing item insert: " . $conn->error);
                
                for ($i=0; $i<count($wattages); $i++) {
                    $w = intval($wattages[$i]);
                    $q = intval($quantities[$i]);
    
                    if ($w <= 0 || $q <= 0) throw new Exception("Wattage and Quantity must be positive integers for all entries.");
                    
                    $stmt2->bind_param("iii", $unassigned_module_id, $w, $q);
                    if (!$stmt2->execute()) throw new Exception("Error inserting wattage/quantity item: " . $stmt2->error);
                    $wattage_items_added++;
                }
                $stmt2->close();
            }
    
            if ($wattage_items_added === 0) throw new Exception("You must add at least one Wattage/Quantity entry.");
    
            $conn->commit();
            $successMessage = "Module batch (ID: {$unassigned_module_id}) and {$wattage_items_added} item(s) added successfully!";
    
        } catch (Exception $ex) {
            $conn->rollback();
            $errorMessage = $ex->getMessage();
        } 
        // Removed finally block with conn->close()
    }
}

// --- Fetch Modules Data for Table Display ---
$modulesData = [];
if ($conn) { // Check connection is still valid
    $sqlModules        = "";
    $paramTypesModules = "";
    $paramsModules     = [];
    
    if ($role === 'global_admin') {
        $sqlModules = "SELECT 
                         um.id, um.vendor_name, um.initial_location, um.project_id,
                         c.name AS account_name,
                         p.project_name 
                       FROM modules um 
                       JOIN customer_accounts c ON um.account_id = c.id
                       LEFT JOIN projects p ON um.project_id = p.id
                       ORDER BY c.name ASC, um.vendor_name ASC";
    } elseif ($role === 'admin' && !empty($account_id_for_admin)) {
         $sqlModules = "SELECT 
                          um.id, um.vendor_name, um.initial_location, um.project_id,
                          c.name AS account_name,
                          p.project_name 
                        FROM modules um 
                        JOIN customer_accounts c ON um.account_id = c.id
                        LEFT JOIN projects p ON um.project_id = p.id
                        WHERE um.account_id = ? 
                        ORDER BY um.vendor_name ASC";
        $paramTypesModules = "i";
        $paramsModules     = [$account_id_for_admin];
    } else {
        // Admin with no account or other roles see no modules
         $sqlModules = "SELECT NULL LIMIT 0";
    }
    
    $stmtModules = $conn->prepare($sqlModules);
    if (!$stmtModules) {
        $errorMessage .= " Error preparing modules query: " . $conn->error;
    } else {
        if (!empty($paramTypesModules)) {
            $stmtModules->bind_param($paramTypesModules, ...$paramsModules);
        }
        $stmtModules->execute();
        $resultModules = $stmtModules->get_result();
        
        // Fetch items for each batch
        $stmtItems = $conn->prepare("SELECT wattage, quantity FROM unassigned_module_items WHERE unassigned_module_id = ?");
        if (!$stmtItems) {
            $errorMessage .= " Error preparing item query: " . $conn->error;
        } else {
            while ($batch = $resultModules->fetch_assoc()) {
                $batch_id = $batch['id'];
                $batch['items'] = [];
                $batch['total_quantity'] = 0;
            
                $stmtItems->bind_param("i", $batch_id);
                $stmtItems->execute();
                $resultItems = $stmtItems->get_result();
                while ($item = $resultItems->fetch_assoc()) {
                    $batch['items'][] = $item;
                    $batch['total_quantity'] += $item['quantity'];
                }
                $modulesData[] = $batch;
            }
            $stmtItems->close();
        }
        $stmtModules->close();
    }
} // end if($conn)

// --- Close connection after all DB operations for the page are done --- 
if ($conn && $conn instanceof mysqli) {
    $conn->close();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Manage Modules</title>
    <link rel="stylesheet" href="portal.css">
    <link rel="icon" href="pictures/favicon.png" type="image/x-icon">
    <link href="https://fonts.googleapis.com/css?family=Poppins:300,400,500,600,700&display=swap" rel="stylesheet">
    <style>
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
        /* Message styling */
        .success-message, .error-message {
            margin: 20px 0;
            padding: 10px;
            border: 1px solid;
            background-color: #e6ffed;
            text-align: center;
            border-radius: 4px;
        }
        .success-message { color: green; border-color: green; background-color: #e6ffed; }
        .error-message { color: red; border-color: red; background-color: #ffe6e6; }

        /* Table Styles (from manage_projects.php) */
        table {
            border-collapse: collapse;
            width: 100%;
            margin-top: 20px;
        }

        .action-button {
            background-color: #488C9A;
            color: #fff;
            text-decoration: none;
            border-radius: 5px;
            padding: 5px 10px; /* Smaller padding for table buttons */
            margin: 2px;
            font-weight: bold;
            cursor: pointer;
            border: none;
            font-size: 0.9em;
        }
        .action-button:hover {
            background-color: #293E4C;
        }
        .action-forms {
            display: flex;
            flex-wrap: wrap;
            gap: 10px; 
            justify-content: center;
        }
        .action-forms button {
             margin: 0; /* Reset margin */
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
            overflow: auto; 
            background-color: rgba(0,0,0,0.5); 
        }
        .modal-content {
            background-color: #fefefe;
            margin: 8% auto; /* Adjusted margin */
            padding: 30px;
            border: 1px solid #888;
            width: 80%;
            max-width: 700px;
            border-radius: 5px;
            position: relative;
        }
        .close-button {
            color: #aaa;
            position: absolute;
            right: 15px;
            top: 5px;
            font-size: 28px;
            font-weight: bold;
        }
        .close-button:hover,
        .close-button:focus {
            color: black;
            text-decoration: none;
            cursor: pointer;
        }
        .modal-content h2 {
            margin-top: 0;
            margin-bottom: 20px;
            text-align: center;
        }
        /* Adjust form styles within modal if needed */
        .modal-content form label { margin-top: 10px; }
        .modal-content .btn-submit { margin-top: 15px; }
        .error-message {
            background-color: #f8d7da;
            border: 1px solid #f5c6cb;
            color: #721c24;
            padding: 10px;
            margin-bottom: 15px;
            border-radius: 4px;
        }
        .success-message {
            background-color: #d4edda;
            border: 1px solid #c3e6cb;
            color: #155724;
            padding: 10px;
            margin-bottom: 15px;
            border-radius: 4px;
        }
    </style>
    <script>
        // Wattage field adder - remains the same
        function addWattageField() {
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

        // Modal handling will be added after DOM is loaded
        document.addEventListener('DOMContentLoaded', function() {
            var modal = document.getElementById('addModuleModal');
            var btn = document.getElementById('openAddModalBtn');
            var span = modal.querySelector('.close-button');

            if(btn) { btn.onclick = function() { modal.style.display = 'block'; } }
            if(span) { span.onclick = function() { modal.style.display = 'none'; } }
            
            window.onclick = function(event) {
                if (event.target == modal) {
                    modal.style.display = 'none';
                }
            }
            
            // If there was a POST error, re-open the modal to show the error message within context
            <?php if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($errorMessage)): ?>
            modal.style.display = 'block';
            <?php endif; ?>

            // NEW: Add initial wattage field if container is empty on modal load
            if (modal && modal.style.display === 'block') {
                var wattageContainer = document.getElementById('wattage-container');
                 if (wattageContainer && wattageContainer.children.length === 0) {
                      addWattageField();
                 }
            }
             // NEW: Logic to filter project dropdown based on selected account (for global admin)
            var accountSelect = document.getElementById('account_id');
            var projectSelect = document.getElementById('project_id');
            if (accountSelect && projectSelect && '<?php echo $role; ?>' === 'global_admin') {
                 // Store all project options initially
                 var allProjectOptions = Array.from(projectSelect.options).slice(1); // Skip placeholder

                 accountSelect.addEventListener('change', function() {
                     var selectedAccountId = this.value;
                     // Clear current project options (except placeholder)
                     while (projectSelect.options.length > 1) {
                         projectSelect.remove(1);
                     }

                     // Add back relevant options
                     allProjectOptions.forEach(function(option) {
                          // Check if the option's data-account-id matches the selected account
                          if (selectedAccountId === '' || option.getAttribute('data-account-id') === selectedAccountId) {
                              projectSelect.add(option.cloneNode(true));
                          }
                     });
                 });
                 // Trigger change on load if an account is pre-selected (e.g., after error)
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
    <h1>Manage Modules</h1>

    <?php
    // Display session message if it exists
    if (isset($_SESSION['del_message']) && !empty($_SESSION['del_message'])) {
        $messageClass = (strpos(strtolower($_SESSION['del_message']), 'error') === false) ? 'success-message' : 'error-message';
        echo '<div class="' . $messageClass . '">' . htmlspecialchars($_SESSION['del_message']) . '</div>';
        unset($_SESSION['del_message']); // Clear the message
    }
    // Display messages from POST handling on this page
    if (!empty($successMessage)) {
        echo '<div class="success-message">' . htmlspecialchars($successMessage) . '</div>';
    }
    if (!empty($errorMessage)) {
        echo '<div class="error-message">' . htmlspecialchars($errorMessage) . '</div>';
    }
    ?>

    <!-- Button to open the modal -->
    <button id="openAddModalBtn" class="action-button" style="font-size: 1em; padding: 10px 20px; margin-bottom: 20px;">Add Module Batch</button>

    <!-- The Modal -->
    <div id="addModuleModal" class="modal">
        <div class="modal-content">
            <span class="close-button">&times;</span>
            <h2>Add New Module Batch</h2>

            <!-- Display error message INSIDE modal if POST failed -->
            <?php if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($errorMessage)): ?>
                <div class="error-message"><strong>Error:</strong> <?php echo htmlspecialchars($errorMessage); ?></div>
            <?php endif; ?>

            <!-- The form moved inside the modal -->
            <form action="modules.php" method="POST">
                <?php if ($role === 'global_admin'): ?>
                    <label for="account_id">Account Name:</label>
                    <select name="account_id" id="account_id" required>
                        <option value="">--Select Account--</option>
                        <?php foreach ($accounts as $acc): ?>
                            <option value="<?php echo $acc['id']; ?>" <?php echo (isset($_POST['account_id']) && $_POST['account_id'] == $acc['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($acc['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                <?php else: // Admin Role ?>
                    <?php
                        // Need to re-establish connection briefly to get account name if needed
                        $adminAccountName = '[Account Name Not Found]';
                        if ($account_id_for_admin) {
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
                        }
                        echo "<p><strong>Account:</strong> " . htmlspecialchars($adminAccountName) . "</p>";
                    ?>
                    <input type="hidden" name="account_id" value="<?php echo $account_id_for_admin; ?>">
                <?php endif; ?>
        
                <label for="vendor_name">Vendor Name:</label>
                <input type="text" name="vendor_name" id="vendor_name" required value="<?php echo htmlspecialchars($_POST['vendor_name'] ?? ''); ?>">
        
                <label for="initial_location">Initial Location:</label>
                <input type="text" name="initial_location" id="initial_location" placeholder="e.g., Warehouse A, Port of Long Beach" required value="<?php echo htmlspecialchars($_POST['initial_location'] ?? ''); ?>">
                
                <!-- NEW: Project Assignment Dropdown -->
                <label for="project_id">Assign to Project (Optional):</label>
                <select name="project_id" id="project_id">
                     <option value="">-- None (Unassigned Stock) --</option>
                     <?php foreach ($projects_for_account as $proj): ?>
                          <option value="<?php echo $proj['id']; ?>" 
                                  <?php if ($role === 'global_admin') echo ' data-account-id="' . $proj['account_id'] . '"'; // Add data attribute for filtering ?>
                                  <?php echo (isset($_POST['project_id']) && $_POST['project_id'] == $proj['id']) ? 'selected' : ''; ?>>
                              <?php echo htmlspecialchars($proj['project_name']); ?>
                              <?php if ($role === 'global_admin') echo ' (Account ID: ' . $proj['account_id'] . ')'; // Show account ID for clarity ?>
                          </option>
                     <?php endforeach; ?>
                </select>

                <div class="section-title">Module Wattage and Quantities</div>
                <div id="wattage-container">
                     <!-- Dynamically added fields go here -->
                     <?php
                     // Re-populate wattage fields on error
                     if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['wattages'])) {
                          for ($i = 0; $i < count($_POST['wattages']); $i++) {
                               $w_val = htmlspecialchars($_POST['wattages'][$i] ?? '');
                               $q_val = htmlspecialchars($_POST['quantities'][$i] ?? '');
                               echo '<div class="wattage-entry">';
                               echo '<div><label>Wattage:</label><input type="number" step="1" name="wattages[]" value="' . $w_val . '" required></div>';
                               echo '<div><label>Quantity:</label><input type="number" step="1" name="quantities[]" value="' . $q_val . '" min="1" required></div>';
                               echo '<div class="remove-btn-container"><button type="button" class="remove-wattage-btn" onclick="this.closest(\'.wattage-entry\').remove()">Remove</button></div>';
                               echo '</div>';
                          }
                     }
                     ?>
                </div>
                <button type="button" class="btn-add-wattage" onclick="addWattageField()">+ Add Wattage/Quantity</button>
        
                <input type="submit" value="Add Batch" class="btn-submit">
            </form>
        </div>
    </div>

    <!-- Modules Table -->
    <h2>Current Module Batches</h2>
     <table id="modulesTable">
        <thead>
            <tr>
                <th>Customer Account</th>
                <th>Vendor Name</th>
                <th>Initial Location</th>
                <th>Assigned Project</th>
                <th>Total Quantity</th>
                <th>Module Details</th>
                <th>Batch Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php if (!empty($modulesData)): ?>
            <?php foreach ($modulesData as $batch): ?>
                <tr>
                    <td><?php echo htmlspecialchars($batch['account_name']); ?></td>
                    <td><?php echo htmlspecialchars($batch['vendor_name']); ?></td>
                    <td><?php echo htmlspecialchars($batch['initial_location']); ?></td>
                    <td>
                        <?php 
                          if (!empty($batch['project_id']) && !empty($batch['project_name'])) {
                              echo htmlspecialchars($batch['project_name']); 
                          } else {
                              echo "<em>Unassigned</em>";
                          }
                        ?>
                    </td>
                    <td><?php echo number_format($batch['total_quantity']); ?></td>
                    <td>
                        <?php
                            $details = [];
                            if (!empty($batch['items'])) {
                                foreach ($batch['items'] as $item) {
                                     $details[] = htmlspecialchars((int)$item['wattage']) . 'W: ' . number_format((int)$item['quantity']);
                                }
                            }
                            echo implode(', ', $details);
                        ?>
                    </td>
                    <td>
                        <div class="action-forms">
                            <!-- Updated links to reflect potential filename changes (assuming overview/edit also renamed) -->
                            <button class="action-button" onclick="window.location.href='module_overview.php?batch_id=<?php echo $batch['id']; ?>'" title="View full details and history for this batch">View Details</button>
                            <button class="action-button" onclick="window.location.href='edit_module.php?batch_id=<?php echo $batch['id']; ?>'" title="Edit vendor, location, assignment, or items in this batch">Edit Batch</button>
                            <!-- Updated delete form action -->
                            <form action="delete_module_batch.php" method="POST" style="display:inline;" onsubmit="return confirm('Are you sure you want to delete this entire batch permanently?');">
                                <input type="hidden" name="batch_id" value="<?php echo $batch['id']; ?>">
                                <button type="submit" class="action-button" style="background-color: #dc3545;" title="Delete this entire batch">Delete Batch</button>
                            </form>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
        <?php else: ?>
            <tr>
                <td colspan="7">No module batches found<?php echo ($role==='admin' && !$account_id_for_admin) ? ' for your assigned account' : ''; ?>.</td>
            </tr>
        <?php endif; ?>
        </tbody>
    </table>

</main>
</body>
</html> 