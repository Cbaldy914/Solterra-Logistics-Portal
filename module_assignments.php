<?php
session_name("logistics_session");
session_start();

// Enable error reporting for debugging (remove in production)


// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login");
    exit();
}

// Database connection
require_once '../config.php';
$conn = getDBConnection();
if (!$conn) {
    die("Connection failed");
}

// Get user role
$user_id = $_SESSION['user_id'];

if (isset($_SESSION['role'])) {
    $role = $_SESSION['role'];
} else {
    // Fetch role from database
    $stmt = $conn->prepare("SELECT role FROM users WHERE id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $stmt->bind_result($role);
    $stmt->fetch();
    $stmt->close();

    // Store role in session
    $_SESSION['role'] = $role;
}

/* --- Form Handling Section --- */

// Handle Add New Vendor Commitment
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_vendor'])) {
    $vendor_name = trim($_POST['vendor_name']);
    $contact_info = trim($_POST['contact_info']);
    $committed_volume = intval($_POST['committed_volume']);
    $commitment_start_date = $_POST['commitment_start_date'];
    $commitment_end_date = $_POST['commitment_end_date'];
    $module_cost = floatval($_POST['module_cost']);

    // Input validation can be added here

    // Insert into vendors table
    $stmt = $conn->prepare("
        INSERT INTO vendors (name, contact_info, committed_volume, commitment_start_date, commitment_end_date, module_cost)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    $stmt->bind_param("ssissd", $vendor_name, $contact_info, $committed_volume, $commitment_start_date, $commitment_end_date, $module_cost);
    $stmt->execute();
    $stmt->close();

    // Redirect to avoid form resubmission
    header("Location: module_assignments");
    exit();
}

// Handle Update Vendor Commitment
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['edit_vendor'])) {
    $vendor_id = intval($_POST['vendor_id_edit']);
    $vendor_name = trim($_POST['vendor_name_edit']);
    $contact_info = trim($_POST['contact_info_edit']);
    $committed_volume = intval($_POST['committed_volume_edit']);
    $commitment_start_date = $_POST['commitment_start_date_edit'];
    $commitment_end_date = $_POST['commitment_end_date_edit'];
    $module_cost = floatval($_POST['module_cost_edit']);

    // Input validation can be added here

    // Update vendors table
    $stmt = $conn->prepare("
        UPDATE vendors
        SET name = ?, contact_info = ?, committed_volume = ?, commitment_start_date = ?, commitment_end_date = ?, module_cost = ?
        WHERE id = ?
    ");
    $stmt->bind_param("ssissdi", $vendor_name, $contact_info, $committed_volume, $commitment_start_date, $commitment_end_date, $module_cost, $vendor_id);
    $stmt->execute();
    $stmt->close();

    // Redirect to avoid form resubmission
    header("Location: module_assignments");
    exit();
}

// Handle Add Modules to Inventory
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_inventory'])) {
    $vendor_id = intval($_POST['vendor_id_inventory']);
    $wattage = floatval($_POST['wattage_inventory']);
    $quantity = intval($_POST['quantity_inventory']);
    $date_added = date('Y-m-d');

    // Input validation can be added here

    // Insert into module_inventory
    $stmt = $conn->prepare("
        INSERT INTO module_inventory (vendor_id, wattage, quantity, status, date_assigned)
        VALUES (?, ?, ?, 'In Inventory', ?)
    ");
    $stmt->bind_param("idis", $vendor_id, $wattage, $quantity, $date_added);
    $stmt->execute();
    $stmt->close();

    // Redirect to avoid form resubmission
    header("Location: module_assignments");
    exit();
}

// Handle Update Module Inventory
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['edit_inventory'])) {
    $inventory_id = intval($_POST['inventory_id_edit']);
    $vendor_id = intval($_POST['vendor_id_edit_inventory']);
    $wattage = floatval($_POST['wattage_edit_inventory']);
    $quantity = intval($_POST['quantity_edit_inventory']);
    $status = $_POST['status_edit_inventory'];
    $project_id = isset($_POST['project_id_edit_inventory']) ? intval($_POST['project_id_edit_inventory']) : NULL;

    // Input validation can be added here

    // Update module_inventory table
    $stmt = $conn->prepare("
        UPDATE module_inventory
        SET vendor_id = ?, wattage = ?, quantity = ?, status = ?, project_id = ?
        WHERE id = ?
    ");
    $stmt->bind_param("idissi", $vendor_id, $wattage, $quantity, $status, $project_id, $inventory_id);
    $stmt->execute();
    $stmt->close();

    // Redirect to avoid form resubmission
    header("Location: module_assignments");
    exit();
}

// Handle Assign Modules to Project
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['assign_modules'])) {
    $vendor_id = intval($_POST['vendor_id']);
    $wattage = floatval($_POST['wattage']);
    $quantity = intval($_POST['quantity']);
    $project_id = intval($_POST['project_id']);
    $date_assigned = date('Y-m-d');

    // Check if the assigned quantity exceeds the remaining volume
    // Fetch total assigned volume from this vendor
    $stmt = $conn->prepare("
        SELECT SUM(quantity) AS assigned_volume
        FROM module_inventory
        WHERE vendor_id = ? AND status = 'Assigned to Project'
    ");
    $stmt->bind_param("i", $vendor_id);
    $stmt->execute();
    $stmt->bind_result($assigned_volume);
    $stmt->fetch();
    $stmt->close();

    $assigned_volume = $assigned_volume ? $assigned_volume : 0;

    // Fetch committed volume from vendor
    $stmt = $conn->prepare("SELECT committed_volume FROM vendors WHERE id = ?");
    $stmt->bind_param("i", $vendor_id);
    $stmt->execute();
    $stmt->bind_result($committed_volume);
    $stmt->fetch();
    $stmt->close();

    $remaining_volume = $committed_volume - $assigned_volume;

    if ($quantity > $remaining_volume) {
        die("Cannot assign more modules than the remaining committed volume.");
    }

    // Insert into module_inventory
    $stmt = $conn->prepare("
        INSERT INTO module_inventory (vendor_id, wattage, quantity, status, project_id, date_assigned)
        VALUES (?, ?, ?, 'Assigned to Project', ?, ?)
    ");
    $stmt->bind_param("idiis", $vendor_id, $wattage, $quantity, $project_id, $date_assigned);
    $stmt->execute();
    $module_inventory_id = $stmt->insert_id;
    $stmt->close();

    // Insert into module_movements
    $stmt = $conn->prepare("
        INSERT INTO module_movements (module_inventory_id, from_project_id, to_project_id, quantity, movement_date)
        VALUES (?, NULL, ?, ?, ?)
    ");
    $stmt->bind_param("iiis", $module_inventory_id, $project_id, $quantity, $date_assigned);
    $stmt->execute();
    $stmt->close();

    // Redirect to avoid form resubmission
    header("Location: module_assignments");
    exit();
}

// Handle Move Modules Between Projects
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['move_modules'])) {
    $from_project_id = intval($_POST['from_project_id']);
    $to_project_id = intval($_POST['to_project_id']);
    $wattage = floatval($_POST['wattage_move']);
    $quantity = intval($_POST['quantity_move']);
    $date_moved = date('Y-m-d');

    if ($from_project_id == $to_project_id) {
        die("Cannot move modules to the same project.");
    }

    // Begin transaction
    $conn->begin_transaction();

    try {
        // Subtract quantity from from_project_id
        $stmt = $conn->prepare("
            UPDATE module_inventory
            SET quantity = quantity - ?
            WHERE project_id = ? AND wattage = ? AND quantity >= ? AND status = 'Assigned to Project'
        ");
        $stmt->bind_param("iidi", $quantity, $from_project_id, $wattage, $quantity);
        $stmt->execute();
        $affected_rows = $stmt->affected_rows;
        $stmt->close();

        if ($affected_rows > 0) {
            // Add quantity to to_project_id
            // Check if an entry already exists
            $stmt = $conn->prepare("
                SELECT id FROM module_inventory
                WHERE project_id = ? AND wattage = ? AND status = 'Assigned to Project'
            ");
            $stmt->bind_param("id", $to_project_id, $wattage);
            $stmt->execute();
            $stmt->bind_result($existing_inventory_id);
            $stmt->fetch();
            $stmt->close();

            if ($existing_inventory_id) {
                // Update existing entry
                $stmt = $conn->prepare("
                    UPDATE module_inventory
                    SET quantity = quantity + ?
                    WHERE id = ?
                ");
                $stmt->bind_param("ii", $quantity, $existing_inventory_id);
                $stmt->execute();
                $stmt->close();
                $module_inventory_id = $existing_inventory_id;
            } else {
                // Insert new entry
                $stmt = $conn->prepare("
                    INSERT INTO module_inventory (vendor_id, wattage, quantity, status, project_id, date_assigned)
                    VALUES (NULL, ?, ?, 'Assigned to Project', ?, ?)
                ");
                $stmt->bind_param("diis", $wattage, $quantity, $to_project_id, $date_moved);
                $stmt->execute();
                $module_inventory_id = $stmt->insert_id;
                $stmt->close();
            }

            // Insert into module_movements
            $stmt = $conn->prepare("
                INSERT INTO module_movements (module_inventory_id, from_project_id, to_project_id, quantity, movement_date)
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->bind_param("iiiis", $module_inventory_id, $from_project_id, $to_project_id, $quantity, $date_moved);
            $stmt->execute();
            $stmt->close();

            // Commit transaction
            $conn->commit();
        } else {
            // Rollback transaction
            $conn->rollback();
            die("Insufficient modules to move or invalid data.");
        }
    } catch (Exception $e) {
        // Rollback transaction on error
        $conn->rollback();
        die("An error occurred: " . $e->getMessage());
    }

    // Redirect to avoid form resubmission
    header("Location: module_assignments");
    exit();
}

/* --- Data Fetching Section --- */

// Fetch Vendor Commitments
$stmt = $conn->prepare("
    SELECT id, name, committed_volume, commitment_start_date, commitment_end_date, module_cost
    FROM vendors
");
$stmt->execute();
$vendors_result = $stmt->get_result();
$vendors = [];

while ($row = $vendors_result->fetch_assoc()) {
    $vendors[] = $row;
}

$stmt->close();

// Fetch Module Inventory
$stmt = $conn->prepare("
    SELECT mi.id, mi.vendor_id, v.name AS vendor_name, mi.wattage, mi.quantity, mi.status, mi.project_id, mi.date_assigned
    FROM module_inventory mi
    LEFT JOIN vendors v ON mi.vendor_id = v.id
");
$stmt->execute();
$inventory_result = $stmt->get_result();
$module_inventory = [];

while ($row = $inventory_result->fetch_assoc()) {
    $module_inventory[] = $row;
}

$stmt->close();

// Fetch Projects List
$stmt = $conn->prepare("
    SELECT id, project_name
    FROM projects
");
$stmt->execute();
$projects_result = $stmt->get_result();
$projects = [];

while ($row = $projects_result->fetch_assoc()) {
    $projects[] = $row;
}

$stmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Module Assignments</title>
    <link rel="stylesheet" href="portal.css">
    <link rel="icon" href="pictures/favicon.png" type="image/x-icon">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <!-- Include any additional CSS or JS here -->
</head>
<body>
<?php include 'header.php'; ?>
<main>
    <h1>Module Assignments</h1>

    <!-- Vendor Commitments Section -->
    <section>
        <h2>Vendor Commitments</h2>
        <button id="addVendorBtn">Add New Vendor Commitment</button>

        <table>
            <thead>
                <tr>
                    <th>Vendor Name</th>
                    <th>Committed Volume</th>
                    <th>Remaining Volume</th>
                    <th>Commitment Timeframe</th>
                    <th>Module Cost</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($vendors as $vendor): ?>
                    <?php
                    // Calculate Remaining Volume
                    // Fetch total assigned volume from this vendor
                    $stmt = $conn->prepare("
                        SELECT SUM(quantity) AS assigned_volume
                        FROM module_inventory
                        WHERE vendor_id = ? AND status = 'Assigned to Project'
                    ");
                    $stmt->bind_param("i", $vendor['id']);
                    $stmt->execute();
                    $stmt->bind_result($assigned_volume);
                    $stmt->fetch();
                    $stmt->close();

                    $assigned_volume = $assigned_volume ? $assigned_volume : 0;
                    $remaining_volume = $vendor['committed_volume'] - $assigned_volume;
                    ?>
                    <tr>
                        <td><?php echo htmlspecialchars($vendor['name']); ?></td>
                        <td><?php echo number_format($vendor['committed_volume']); ?></td>
                        <td><?php echo number_format($remaining_volume); ?></td>
                        <td>
                            <?php
                            echo htmlspecialchars($vendor['commitment_start_date']);
                            echo " - ";
                            echo htmlspecialchars($vendor['commitment_end_date']);
                            ?>
                        </td>
                        <td>$<?php echo number_format($vendor['module_cost'], 2); ?></td>
                        <td>
                            <button class="editVendorBtn" data-vendor-id="<?php echo $vendor['id']; ?>">Edit</button>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </section>

    <!-- Module Inventory Section -->
    <section>
        <h2>Module Inventory</h2>
        <button id="addInventoryBtn">Add Modules to Inventory</button>

        <table>
            <thead>
                <tr>
                    <th>Vendor</th>
                    <th>Wattage</th>
                    <th>Quantity</th>
                    <th>Status</th>
                    <th>Assigned Project</th>
                    <th>Date Assigned</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($module_inventory as $module): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($module['vendor_name']); ?></td>
                        <td><?php echo htmlspecialchars($module['wattage']); ?> W</td>
                        <td><?php echo number_format($module['quantity']); ?></td>
                        <td><?php echo htmlspecialchars($module['status']); ?></td>
                        <td>
                            <?php
                            if ($module['project_id']) {
                                // Get project name
                                $project_name = '';
                                foreach ($projects as $project) {
                                    if ($project['id'] == $module['project_id']) {
                                        $project_name = $project['project_name'];
                                        break;
                                    }
                                }
                                echo htmlspecialchars($project_name);
                            } else {
                                echo 'N/A';
                            }
                            ?>
                        </td>
                        <td><?php echo htmlspecialchars($module['date_assigned']); ?></td>
                        <td>
                            <button class="editInventoryBtn" data-inventory-id="<?php echo $module['id']; ?>">Edit</button>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </section>

    <!-- Assign Modules to Project Section -->
    <section>
        <h2>Assign Modules to Project</h2>
        <form action="module_assignments" method="post" class="module-form assign-modules-form">
            <label for="vendor_id">Select Vendor:</label>
            <select name="vendor_id" id="vendor_id" required>
                <option value="">--Select Vendor--</option>
                <?php foreach ($vendors as $vendor): ?>
                    <option value="<?php echo $vendor['id']; ?>">
                        <?php echo htmlspecialchars($vendor['name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <label for="wattage">Wattage:</label>
            <input type="number" name="wattage" id="wattage" step="0.01" required>

            <label for="quantity">Quantity:</label>
            <input type="number" name="quantity" id="quantity" required>

            <label for="project_id">Assign to Project:</label>
            <select name="project_id" id="project_id" required>
                <option value="">--Select Project--</option>
                <?php foreach ($projects as $project): ?>
                    <option value="<?php echo $project['id']; ?>">
                        <?php echo htmlspecialchars($project['project_name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <button type="submit" name="assign_modules">Assign Modules</button>
        </form>
    </section>

    <!-- Move Modules Between Projects Section -->
    <section>
        <h2>Move Modules Between Projects</h2>
        <form action="module_assignments" method="post" class="module-form move-modules-form">
            <label for="from_project_id">From Project:</label>
            <select name="from_project_id" id="from_project_id" required>
                <option value="">--Select Project--</option>
                <?php foreach ($projects as $project): ?>
                    <option value="<?php echo $project['id']; ?>">
                        <?php echo htmlspecialchars($project['project_name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <label for="to_project_id">To Project:</label>
            <select name="to_project_id" id="to_project_id" required>
                <option value="">--Select Project--</option>
                <?php foreach ($projects as $project): ?>
                    <option value="<?php echo $project['id']; ?>">
                        <?php echo htmlspecialchars($project['project_name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <label for="wattage_move">Wattage:</label>
            <input type="number" name="wattage_move" id="wattage_move" step="0.01" required>

            <label for="quantity_move">Quantity:</label>
            <input type="number" name="quantity_move" id="quantity_move" required>

            <button type="submit" name="move_modules">Move Modules</button>
        </form>
    </section>

    <!-- Modals -->

    <!-- Modal for Adding New Vendor -->
    <div id="addVendorModal" class="modal">
        <div class="modal-content">
            <span class="close" id="closeVendorModal">&times;</span>
            <h3>Add New Vendor Commitment</h3>
            <form action="module_assignments" method="post" class="module-form add-vendor-form">
                <label for="vendor_name">Vendor Name:</label>
                <input type="text" name="vendor_name" id="vendor_name" required>

                <label for="contact_info">Contact Info:</label>
                <input type="text" name="contact_info" id="contact_info">

                <label for="committed_volume">Committed Volume:</label>
                <input type="number" name="committed_volume" id="committed_volume" required>

                <label for="commitment_start_date">Commitment Start Date:</label>
                <input type="date" name="commitment_start_date" id="commitment_start_date">

                <label for="commitment_end_date">Commitment End Date:</label>
                <input type="date" name="commitment_end_date" id="commitment_end_date">

                <label for="module_cost">Module Cost:</label>
                <input type="number" name="module_cost" id="module_cost" step="0.01" required>

                <button type="submit" name="add_vendor">Add Vendor</button>
            </form>
        </div>
    </div>

    <!-- Modal for Editing Vendor -->
    <div id="editVendorModal" class="modal">
        <div class="modal-content">
            <span class="close" id="closeEditVendorModal">&times;</span>
            <h3>Edit Vendor Commitment</h3>
            <form action="module_assignments" method="post" class="module-form edit-vendor-form">
                <input type="hidden" name="vendor_id_edit" id="vendor_id_edit">
                <label for="vendor_name_edit">Vendor Name:</label>
                <input type="text" name="vendor_name_edit" id="vendor_name_edit" required>

                <label for="contact_info_edit">Contact Info:</label>
                <input type="text" name="contact_info_edit" id="contact_info_edit">

                <label for="committed_volume_edit">Committed Volume:</label>
                <input type="number" name="committed_volume_edit" id="committed_volume_edit" required>

                <label for="commitment_start_date_edit">Commitment Start Date:</label>
                <input type="date" name="commitment_start_date_edit" id="commitment_start_date_edit">

                <label for="commitment_end_date_edit">Commitment End Date:</label>
                <input type="date" name="commitment_end_date_edit" id="commitment_end_date_edit">

                <label for="module_cost_edit">Module Cost:</label>
                <input type="number" name="module_cost_edit" id="module_cost_edit" step="0.01" required>

                <button type="submit" name="edit_vendor">Update Vendor</button>
            </form>
        </div>
    </div>

    <!-- Modal for Adding Modules to Inventory -->
    <div id="addInventoryModal" class="modal">
        <div class="modal-content">
            <span class="close" id="closeInventoryModal">&times;</span>
            <h3>Add Modules to Inventory</h3>
            <form action="module_assignments" method="post" class="module-form add-inventory-form">
                <label for="vendor_id_inventory">Select Vendor:</label>
                <select name="vendor_id_inventory" id="vendor_id_inventory" required>
                    <option value="">--Select Vendor--</option>
                    <?php foreach ($vendors as $vendor): ?>
                        <option value="<?php echo $vendor['id']; ?>">
                            <?php echo htmlspecialchars($vendor['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <label for="wattage_inventory">Wattage:</label>
                <input type="number" name="wattage_inventory" id="wattage_inventory" step="0.01" required>

                <label for="quantity_inventory">Quantity:</label>
                <input type="number" name="quantity_inventory" id="quantity_inventory" required>

                <button type="submit" name="add_inventory">Add to Inventory</button>
            </form>
        </div>
    </div>

    <!-- Modal for Editing Module Inventory -->
    <div id="editInventoryModal" class="modal">
        <div class="modal-content">
            <span class="close" id="closeEditInventoryModal">&times;</span>
            <h3>Edit Module Inventory</h3>
            <form action="module_assignments" method="post" class="module-form edit-inventory-form">
                <input type="hidden" name="inventory_id_edit" id="inventory_id_edit">

                <label for="vendor_id_edit_inventory">Vendor:</label>
                <select name="vendor_id_edit_inventory" id="vendor_id_edit_inventory" required>
                    <option value="">--Select Vendor--</option>
                    <?php foreach ($vendors as $vendor): ?>
                        <option value="<?php echo $vendor['id']; ?>">
                            <?php echo htmlspecialchars($vendor['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <label for="wattage_edit_inventory">Wattage:</label>
                <input type="number" name="wattage_edit_inventory" id="wattage_edit_inventory" step="0.01" required>

                <label for="quantity_edit_inventory">Quantity:</label>
                <input type="number" name="quantity_edit_inventory" id="quantity_edit_inventory" required>

                <label for="status_edit_inventory">Status:</label>
                <select name="status_edit_inventory" id="status_edit_inventory" required>
                    <option value="In Inventory">In Inventory</option>
                    <option value="Assigned to Project">Assigned to Project</option>
                </select>

                <label for="project_id_edit_inventory">Assigned Project:</label>
                <select name="project_id_edit_inventory" id="project_id_edit_inventory">
                    <option value="">--Select Project--</option>
                    <?php foreach ($projects as $project): ?>
                        <option value="<?php echo $project['id']; ?>">
                            <?php echo htmlspecialchars($project['project_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <button type="submit" name="edit_inventory">Update Inventory</button>
            </form>
        </div>
    </div>

</main>

<!-- JavaScript Section -->
<script>
    // Function to close modals when clicking outside
    window.onclick = function(event) {
        if (event.target.classList.contains('modal')) {
            event.target.style.display = "none";
        }
    }

    // Add Vendor Modal
    var addVendorModal = document.getElementById('addVendorModal');
    var addVendorBtn = document.getElementById('addVendorBtn');
    var closeVendorModal = document.getElementById('closeVendorModal');

    addVendorBtn.onclick = function() {
        addVendorModal.style.display = "block";
    }
    closeVendorModal.onclick = function() {
        addVendorModal.style.display = "none";
    }

    // Edit Vendor Modal
    var editVendorModal = document.getElementById('editVendorModal');
    var closeEditVendorModal = document.getElementById('closeEditVendorModal');

    document.querySelectorAll('.editVendorBtn').forEach(function(button) {
        button.addEventListener('click', function() {
            var vendorId = this.getAttribute('data-vendor-id');

            // Make an AJAX call to get vendor data
            var xhr = new XMLHttpRequest();
            xhr.open('GET', 'get_vendor?vendor_id=' + vendorId, true);
            xhr.onload = function() {
                if (xhr.status === 200) {
                    var vendor = JSON.parse(xhr.responseText);
                    // Populate the form fields
                    document.getElementById('vendor_id_edit').value = vendor.id;
                    document.getElementById('vendor_name_edit').value = vendor.name;
                    document.getElementById('contact_info_edit').value = vendor.contact_info;
                    document.getElementById('committed_volume_edit').value = vendor.committed_volume;
                    document.getElementById('commitment_start_date_edit').value = vendor.commitment_start_date;
                    document.getElementById('commitment_end_date_edit').value = vendor.commitment_end_date;
                    document.getElementById('module_cost_edit').value = vendor.module_cost;

                    // Show the modal
                    editVendorModal.style.display = "block";
                } else {
                    alert('Error fetching vendor data.');
                }
            };
            xhr.send();
        });
    });

    closeEditVendorModal.onclick = function() {
        editVendorModal.style.display = "none";
    }

    // Add Inventory Modal
    var addInventoryModal = document.getElementById('addInventoryModal');
    var addInventoryBtn = document.getElementById('addInventoryBtn');
    var closeInventoryModal = document.getElementById('closeInventoryModal');

    addInventoryBtn.onclick = function() {
        addInventoryModal.style.display = "block";
    }
    closeInventoryModal.onclick = function() {
        addInventoryModal.style.display = "none";
    }

    // Edit Inventory Modal
    var editInventoryModal = document.getElementById('editInventoryModal');
    var closeEditInventoryModal = document.getElementById('closeEditInventoryModal');

    document.querySelectorAll('.editInventoryBtn').forEach(function(button) {
        button.addEventListener('click', function() {
            var inventoryId = this.getAttribute('data-inventory-id');

            // Make an AJAX call to get inventory data
            var xhr = new XMLHttpRequest();
            xhr.open('GET', 'get_inventory?inventory_id=' + inventoryId, true);
            xhr.onload = function() {
                if (xhr.status === 200) {
                    var inventory = JSON.parse(xhr.responseText);
                    // Populate the form fields
                    document.getElementById('inventory_id_edit').value = inventory.id;
                    document.getElementById('vendor_id_edit_inventory').value = inventory.vendor_id;
                    document.getElementById('wattage_edit_inventory').value = inventory.wattage;
                    document.getElementById('quantity_edit_inventory').value = inventory.quantity;
                    document.getElementById('status_edit_inventory').value = inventory.status;
                    document.getElementById('project_id_edit_inventory').value = inventory.project_id;

                    // Show or hide the project field based on status
                    if (inventory.status === 'Assigned to Project') {
                        document.getElementById('project_id_edit_inventory').parentElement.style.display = 'block';
                    } else {
                        document.getElementById('project_id_edit_inventory').parentElement.style.display = 'none';
                    }

                    // Show the modal
                    editInventoryModal.style.display = "block";
                } else {
                    alert('Error fetching inventory data.');
                }
            };
            xhr.send();
        });
    });

    closeEditInventoryModal.onclick = function() {
        editInventoryModal.style.display = "none";
    }

    // Show/hide project field based on status selection in Edit Inventory Modal
    document.getElementById('status_edit_inventory').addEventListener('change', function() {
        if (this.value === 'Assigned to Project') {
            document.getElementById('project_id_edit_inventory').parentElement.style.display = 'block';
        } else {
            document.getElementById('project_id_edit_inventory').parentElement.style.display = 'none';
            document.getElementById('project_id_edit_inventory').value = '';
        }
    });
</script>
</body>
</html>
