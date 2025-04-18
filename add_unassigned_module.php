<?php
session_name("logistics_session");
session_start();

// Ensure user has role admin or global_admin
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin','global_admin'])) {
    die("Unauthorized: You must be 'admin' or 'global_admin' to add unassigned modules.");
}

// Database connection
require_once '../config.php';
$conn = getDBConnection();
if (!$conn) {
    die("Database connection failed.");
}

$role    = $_SESSION['role'];
$user_id = $_SESSION['user_id'];

// We'll find the admin's single account or list of accounts for a dropdown
$account_id_for_admin = null;
$accounts             = [];

// If global_admin, load all accounts for a dropdown
if ($role === 'global_admin') {
    $sqlAll = "SELECT id, name FROM customer_accounts ORDER BY name ASC";
    $resAll = $conn->query($sqlAll);
    if ($resAll && $resAll->num_rows > 0) {
        while ($row = $resAll->fetch_assoc()) {
            $accounts[] = $row;
        }
    }
} else {
    // If admin, fetch exactly one account_id from bridging table
    $sqlOne = "
        SELECT account_id
        FROM customer_account_users
        WHERE user_id = ?
          AND role = 'admin'
        LIMIT 1
    ";
    $stmtOne = $conn->prepare($sqlOne);
    if (!$stmtOne) {
        die("Error preparing account lookup: " . $conn->error);
    }
    $stmtOne->bind_param("i", $user_id);
    $stmtOne->execute();
    $stmtOne->bind_result($acctID);
    if ($stmtOne->fetch()) {
        $account_id_for_admin = $acctID;
    }
    $stmtOne->close();

    if (!$account_id_for_admin) {
        die("No valid account found for this admin user.");
    }
}

// Prepare variables to hold user messages:
$successMessage = "";
$errorMessage   = "";

// If POST, handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $conn->begin_transaction(); // Start transaction
    try {
        // Determine correct account_id
        if ($role === 'global_admin') {
            $account_id = isset($_POST['account_id']) ? intval($_POST['account_id']) : 0;
            if ($account_id <= 0) {
                throw new Exception("Please select a valid Account.");
            }
        } else {
            // Admin => single known account
            $account_id = $account_id_for_admin;
        }

        // Gather main fields
        $vendor_name        = trim($_POST['vendor_name'] ?? '');
        $initial_location   = trim($_POST['initial_location'] ?? '');

        if ($vendor_name === '' || $initial_location === '') {
            throw new Exception("Vendor Name and Initial Location are required.");
        }

        // Insert into unassigned_modules
        $stmt = $conn->prepare("
            INSERT INTO unassigned_modules (account_id, vendor_name, initial_location)
            VALUES (?, ?, ?)
        ");
        if (!$stmt) {
            throw new Exception("Error preparing main insert: " . $conn->error);
        }
        $stmt->bind_param("iss", $account_id, $vendor_name, $initial_location);
        if (!$stmt->execute()) {
            throw new Exception("Error inserting unassigned module batch: " . $stmt->error);
        }
        $unassigned_module_id = $stmt->insert_id; // Get the ID of the batch we just inserted
        $stmt->close();

        // Insert wattage+quantity items if provided
        $wattage_items_added = 0;
        if (isset($_POST['wattages'], $_POST['quantities'])) {
            $wattages   = $_POST['wattages'];
            $quantities = $_POST['quantities'];

            if (count($wattages) !== count($quantities)) {
                throw new Exception("Mismatch between wattage[] and quantities[] arrays.");
            }
            for ($i=0; $i<count($wattages); $i++) {
                $w = intval($wattages[$i]); // Process as integer
                $q = intval($quantities[$i]); // Make sure quantity is an integer

                if ($w <= 0 || $q <= 0) {
                    // Skip invalid entries or throw error? Let's throw an error for now.
                    throw new Exception("Wattage and Quantity must be greater than 0 for all entries.");
                }

                $stmt2 = $conn->prepare("
                    INSERT INTO unassigned_module_items (unassigned_module_id, wattage, quantity)
                    VALUES (?, ?, ?)
                ");
                if (!$stmt2) {
                    throw new Exception("Error preparing item insert: " . $conn->error);
                }
                $stmt2->bind_param("iii", $unassigned_module_id, $w, $q); // Bind wattage as integer (i)
                if (!$stmt2->execute()) {
                    throw new Exception("Error inserting wattage/quantity item: " . $stmt2->error);
                }
                $stmt2->close();
                $wattage_items_added++;
            }
        }

        // We require at least one wattage item to be added
        if ($wattage_items_added === 0) {
            throw new Exception("You must add at least one Wattage/Quantity entry.");
        }

        // If all went well, commit the transaction
        $conn->commit();
        $successMessage = "Unassigned module batch (ID: {$unassigned_module_id}) and {$wattage_items_added} item(s) added successfully!";

    } catch (Exception $ex) {
        // If anything went wrong, roll back the transaction
        $conn->rollback();
        $errorMessage = $ex->getMessage();
    } finally {
        // Close connection if it's open
        if ($conn && $conn instanceof mysqli) {
            $conn->close();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Add Unassigned Modules</title>
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
            align-items: flex-end; /* Align items to the bottom */
        }
        .wattage-entry > div { /* Target direct children divs for labels/inputs */
            flex: 1; /* Allow flex grow/shrink */
            min-width: 150px; /* Minimum width before wrapping */
        }
         .wattage-entry > div label { /* Adjust label margin within flex item */
             margin-top: 0;
         }
        .wattage-entry .remove-btn-container {
             flex: 0 0 auto; /* Don't grow or shrink, use content size */
             margin-left: 10px; /* Add space before remove button */
        }
        .wattage-entry button {
            background: #dc3545; /* Red color for remove */
            color: #fff;
            border: none;
            padding: 8px 14px;
            cursor: pointer;
            border-radius: 4px;
            height: 36px; /* Match input height */
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

            // Wattage Input Group
            var wattageDiv = document.createElement('div');
            var wattageLabel = document.createElement('label');
            wattageLabel.textContent = 'Wattage:';
            wattageLabel.htmlFor = 'wattages_' + index;
            var wattageInput = document.createElement('input');
            wattageInput.type = 'number';
            wattageInput.step = '1'; // Step by whole numbers
            wattageInput.name = 'wattages[' + index + ']';
            wattageInput.id = 'wattages_' + index;
            wattageInput.required = true;
            wattageDiv.appendChild(wattageLabel);
            wattageDiv.appendChild(wattageInput);

            // Quantity Input Group
            var quantityDiv = document.createElement('div');
            var quantityLabel = document.createElement('label');
            quantityLabel.textContent = 'Quantity:'; // Changed Label
            quantityLabel.htmlFor = 'quantities_' + index;
            var quantityInput = document.createElement('input');
            quantityInput.type = 'number';
            quantityInput.step = '1'; // Ensure whole numbers
            quantityInput.name = 'quantities[' + index + ']';
            quantityInput.id = 'quantities_' + index;
            quantityInput.required = true;
            quantityDiv.appendChild(quantityLabel);
            quantityDiv.appendChild(quantityInput);

            // Remove Button Group
            var removeBtnDiv = document.createElement('div');
            removeBtnDiv.className = 'remove-btn-container';
            var removeButton = document.createElement('button');
            removeButton.type = 'button';
            removeButton.textContent = 'Remove';
            removeButton.onclick = function() {
                container.removeChild(div);
            };
            removeBtnDiv.appendChild(removeButton);

            // Append groups to the main div
            div.appendChild(wattageDiv);
            div.appendChild(quantityDiv);
            div.appendChild(removeBtnDiv);

            container.appendChild(div);
        }
    </script>
</head>
<body>
<?php include 'header.php'; ?>
<main>
    <h1>Add Unassigned Module Batch</h1>

    <!-- Display success or error messages if any -->
    <?php if (!empty($successMessage)): ?>
        <div class="success-message"><strong><?php echo htmlspecialchars($successMessage); ?></strong></div>
    <?php endif; ?>

    <?php if (!empty($errorMessage)): ?>
        <div class="error-message"><strong>Error:</strong> <?php echo htmlspecialchars($errorMessage); ?></div>
    <?php endif; ?>

    <!-- The form -->
    <form action="add_unassigned_module.php" method="POST">
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
        <?php else: ?>
            <!-- Hidden input for admin's single account -->
            <input type="hidden" name="account_id" value="<?php echo $account_id_for_admin; ?>">
            <?php
                // Fetch and display the account name for the admin
                $stmtAccName = $conn->prepare("SELECT name FROM customer_accounts WHERE id = ?");
                if ($stmtAccName) {
                    $stmtAccName->bind_param("i", $account_id_for_admin);
                    $stmtAccName->execute();
                    $stmtAccName->bind_result($adminAccountName);
                    if ($stmtAccName->fetch()) {
                        echo "<p><strong>Account:</strong> " . htmlspecialchars($adminAccountName) . "</p>";
                    }
                    $stmtAccName->close();
                }
            ?>
        <?php endif; ?>

        <label for="vendor_name">Vendor Name:</label>
        <input type="text" name="vendor_name" id="vendor_name" required>

        <label for="initial_location">Initial Location:</label>
        <input type="text" name="initial_location" id="initial_location" placeholder="e.g., Warehouse A, Port of Long Beach" required>

        <div class="section-title">Module Wattage and Quantities</div>
        <div id="wattage-container">
             <!-- Initial entry added by default? Or force user to click Add? -->
             <!-- Let's start empty and force user click -->
        </div>
        <button type="button" class="btn-add-wattage" onclick="addWattageField()">+ Add Wattage/Quantity</button>

        <input type="submit" value="Add Unassigned Modules" class="btn-submit">
    </form>
    <br>
    <a href="manage_projects.php">Back to Manage Projects</a>
</main>
</body>
</html> 