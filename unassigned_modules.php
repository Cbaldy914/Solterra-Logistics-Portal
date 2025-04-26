<?php
session_name("logistics_session");
session_start();

// Ensure user has role admin or global_admin
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin','global_admin'])) {
    die("Unauthorized: You must be 'admin' or 'global_admin' to manage unassigned modules.");
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

if ($role === 'global_admin') {
    $sqlAll = "SELECT id, name FROM customer_accounts ORDER BY name ASC";
    $resAll = $conn->query($sqlAll);
    if ($resAll && $resAll->num_rows > 0) {
        while ($row = $resAll->fetch_assoc()) {
            $accounts[] = $row;
        }
    }
} else { // admin role
    $sqlOne = "SELECT account_id FROM customer_account_users WHERE user_id = ? AND role = 'admin' LIMIT 1";
    $stmtOne = $conn->prepare($sqlOne);
    if (!$stmtOne) die("Error preparing account lookup: " . $conn->error);
    $stmtOne->bind_param("i", $user_id);
    $stmtOne->execute();
    $stmtOne->bind_result($acctID);
    if ($stmtOne->fetch()) {
        $account_id_for_admin = $acctID;
    }
    $stmtOne->close();

    if (!$account_id_for_admin) {
        // Handle case where admin has no assigned account - prevent further action
        // We might still want to show the page but with an error and no table/form
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
    
            if ($vendor_name === '' || $initial_location === '') {
                throw new Exception("Vendor Name and Initial Location are required.");
            }
    
            // Insert into unassigned_modules
            $stmt = $conn->prepare("INSERT INTO unassigned_modules (account_id, vendor_name, initial_location) VALUES (?, ?, ?)");
            if (!$stmt) throw new Exception("Error preparing main insert: " . $conn->error);
            $stmt->bind_param("iss", $account_id, $vendor_name, $initial_location);
            if (!$stmt->execute()) throw new Exception("Error inserting unassigned module batch: " . $stmt->error);
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
            $successMessage = "Unassigned module batch (ID: {$unassigned_module_id}) and {$wattage_items_added} item(s) added successfully!";
    
        } catch (Exception $ex) {
            $conn->rollback();
            $errorMessage = $ex->getMessage();
        } 
        // Removed finally block with conn->close()
    }
}

// --- Fetch Unassigned Modules Data for Table Display (moved from manage_projects.php) ---
$unassignedModulesData = [];
if ($conn) { // Check connection is still valid
    $sqlUnassigned        = "";
    $paramTypesUnassigned = "";
    $paramsUnassigned     = [];
    
    if ($role === 'global_admin') {
        $sqlUnassigned = "SELECT um.id, um.vendor_name, um.initial_location, c.name AS account_name FROM unassigned_modules um JOIN customer_accounts c ON um.account_id = c.id ORDER BY c.name ASC, um.vendor_name ASC";
    } elseif ($role === 'admin' && !empty($account_id_for_admin)) {
         $sqlUnassigned = "SELECT um.id, um.vendor_name, um.initial_location, c.name AS account_name FROM unassigned_modules um JOIN customer_accounts c ON um.account_id = c.id WHERE um.account_id = ? ORDER BY um.vendor_name ASC";
        $paramTypesUnassigned = "i";
        $paramsUnassigned     = [$account_id_for_admin];
    } else {
        // Admin with no account or other roles see no unassigned modules
         $sqlUnassigned = "SELECT NULL LIMIT 0";
    }
    
    $stmtUnassigned = $conn->prepare($sqlUnassigned);
    if (!$stmtUnassigned) {
        $errorMessage .= " Error preparing unassigned modules query: " . $conn->error;
    } else {
        if (!empty($paramTypesUnassigned)) {
            $stmtUnassigned->bind_param($paramTypesUnassigned, ...$paramsUnassigned);
        }
        $stmtUnassigned->execute();
        $resultUnassigned = $stmtUnassigned->get_result();
        
        // Fetch items for each batch
        $stmtItems = $conn->prepare("SELECT wattage, quantity FROM unassigned_module_items WHERE unassigned_module_id = ?");
        if (!$stmtItems) {
            $errorMessage .= " Error preparing item query: " . $conn->error;
        } else {
            while ($batch = $resultUnassigned->fetch_assoc()) {
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
                $unassignedModulesData[] = $batch;
            }
            $stmtItems->close();
        }
        $stmtUnassigned->close();
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
    <title>Manage Unassigned Modules</title>
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
        });
    </script>
</head>
<body>
<?php include 'header.php'; ?>
<main>
    <h1>Manage Unassigned Modules</h1>

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
    <button id="openAddModalBtn" class="action-button" style="font-size: 1em; padding: 10px 20px; margin-bottom: 20px;">Add Unassigned Modules</button>

    <!-- The Modal -->
    <div id="addModuleModal" class="modal">
        <div class="modal-content">
            <span class="close-button">&times;</span>
            <h2>Add New Unassigned Module Batch</h2>

            <!-- Display error message INSIDE modal if POST failed -->
            <?php if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($errorMessage)): ?>
                <div class="error-message"><strong>Error:</strong> <?php echo htmlspecialchars($errorMessage); ?></div>
            <?php endif; ?>

            <!-- The form moved inside the modal -->
            <form action="unassigned_modules.php" method="POST">
                <?php if ($role === 'global_admin'): ?>
                    <label for="account_id">Account Name:</label>
                    <select name="account_id" id="account_id" required>
                        <option value="">--Select Account--</option>
                        <?php foreach ($accounts as $acc): ?>
                            <option value="<?php echo $acc['id']; ?>">
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
                <input type="text" name="vendor_name" id="vendor_name" required>
        
                <label for="initial_location">Initial Location:</label>
                <input type="text" name="initial_location" id="initial_location" placeholder="e.g., Warehouse A, Port of Long Beach" required>
        
                <div class="section-title">Module Wattage and Quantities</div>
                <div id="wattage-container">
                     <!-- Dynamically added fields go here -->
                </div>
                <button type="button" class="btn-add-wattage" onclick="addWattageField()">+ Add Wattage/Quantity</button>
        
                <input type="submit" value="Add Batch" class="btn-submit">
            </form>
        </div>
    </div>

    <!-- Unassigned Modules Table (copied from manage_projects.php) -->
    <h2>Current Unassigned Modules</h2>
     <table id="unassignedModulesTable">
        <thead>
            <tr>
                <th>Customer Account</th>
                <th>Vendor Name</th>
                <th>Initial Location</th>
                <th>Total Quantity</th>
                <th>Module Details</th>
                <th>Batch Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php if (!empty($unassignedModulesData)): ?>
            <?php foreach ($unassignedModulesData as $batch): ?>
                <tr>
                    <td><?php echo htmlspecialchars($batch['account_name']); ?></td>
                    <td><?php echo htmlspecialchars($batch['vendor_name']); ?></td>
                    <td><?php echo htmlspecialchars($batch['initial_location']); ?></td>
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
                            <!-- Links need refinement - directing to overview/edit/delete pages for unassigned modules -->
                            <button class="action-button" onclick="window.location.href='unassigned_module_overview.php?batch_id=<?php echo $batch['id']; ?>'" title="View full details and history for this batch">View Details</button>
                            <button class="action-button" onclick="window.location.href='edit_unassigned_module.php?batch_id=<?php echo $batch['id']; ?>'" title="Edit vendor, location, or items in this batch">Edit Batch</button>
                            <!-- Add a proper delete form/confirmation -->
                            <form action="delete_unassigned_batch.php" method="POST" style="display:inline;" onsubmit="return confirm('Are you sure you want to delete this entire batch permanently?');">
                                <input type="hidden" name="batch_id" value="<?php echo $batch['id']; ?>">
                                <button type="submit" class="action-button" style="background-color: #dc3545;" title="Delete this entire batch">Delete Batch</button>
                            </form>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
        <?php else: ?>
            <tr>
                <td colspan="6">No unassigned module batches found<?php echo ($role==='admin' && !$account_id_for_admin) ? ' for your assigned account' : ''; ?>.</td>
            </tr>
        <?php endif; ?>
        </tbody>
    </table>

</main>
</body>
</html> 