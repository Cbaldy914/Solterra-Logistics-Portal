<?php
session_name("logistics_session");
session_start();
// Check if the user is an admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'global_admin') {
    header("Location: unauthorized");
    exit();
}

// Check if project_id is provided
if (!isset($_GET['project_id'])) {
    die("Project ID is missing.");
}

$project_id = intval($_GET['project_id']);

// Database connection
require_once '../config.php';
$conn = getDBConnection();
if (!$conn) {
    die("Connection failed");
}

// Fetch project details
$stmt = $conn->prepare("SELECT * FROM projects WHERE id = ?");
$stmt->bind_param("i", $project_id);
$stmt->execute();
$project_result = $stmt->get_result();
$stmt->close();

if ($project_result->num_rows == 0) {
    die("Project not found.");
}

$project = $project_result->fetch_assoc();

// Fetch wattage orders
$stmt = $conn->prepare("
    SELECT *
    FROM project_wattage_orders
    WHERE project_id = ?
");
$stmt->bind_param("i", $project_id);
$stmt->execute();
$wattage_orders_result = $stmt->get_result();
$stmt->close();

$wattage_orders = [];
while ($row = $wattage_orders_result->fetch_assoc()) {
    $wattage_orders[] = $row;
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Edit Project</title>
    <link rel="stylesheet" href="portal.css">
    <link rel="icon" href="pictures/favicon.png" type="image/x-icon">
    <script>
        function addWattageField() {
            var container = document.getElementById('wattage-container');
            var index = container.children.length;

            var div = document.createElement('div');
            div.className = 'wattage-entry';

            var wattageLabel = document.createElement('label');
            wattageLabel.textContent = 'Wattage:';
            var wattageInput = document.createElement('input');
            wattageInput.type = 'number';
            wattageInput.step = '0.01';
            wattageInput.name = 'new_wattages[' + index + ']';
            wattageInput.required = true;

            var totalOrderLabel = document.createElement('label');
            totalOrderLabel.textContent = 'Total Order Quantity:';
            var totalOrderInput = document.createElement('input');
            totalOrderInput.type = 'number';
            totalOrderInput.name = 'new_total_orders[' + index + ']';
            totalOrderInput.required = true;

            var removeButton = document.createElement('button');
            removeButton.type = 'button';
            removeButton.textContent = 'Remove';
            removeButton.onclick = function() {
                container.removeChild(div);
            };

            div.appendChild(wattageLabel);
            div.appendChild(wattageInput);
            div.appendChild(totalOrderLabel);
            div.appendChild(totalOrderInput);
            div.appendChild(removeButton);

            container.appendChild(div);
        }

        function removeExistingWattage(id) {
            var input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'remove_wattages[]';
            input.value = id;
            document.getElementById('edit-project-form').appendChild(input);
            document.getElementById('wattage-entry-' + id).remove();
        }
    </script>
</head>
<body>
<?php include 'header.php'; ?>
<h1>Edit Project</h1>
<form id="edit-project-form" action="process_edit_project" method="POST" enctype="multipart/form-data">
    <input type="hidden" name="project_id" value="<?php echo $project_id; ?>">

    <label for="user_id">User ID:</label>
    <input type="number" name="user_id" value="<?php echo htmlspecialchars($project['user_id']); ?>" required>
    <br><br>

    <label for="project_name">Project Name:</label>
    <input type="text" name="project_name" value="<?php echo htmlspecialchars($project['project_name']); ?>" required>
    <br><br>

    <label for="project_address">Project Address:</label>
    <input type="text" name="project_address" value="<?php echo htmlspecialchars($project['project_address']); ?>" required>
    <br><br>

    <!-- Image Upload -->
    <label for="image_file">Project Image:</label>
    <?php if (!empty($project['image_url'])): ?>
        <div>
            <img src="<?php echo htmlspecialchars($project['image_url']); ?>" alt="Project Image" style="max-width: 200px;">
        </div>
    <?php endif; ?>
    <input type="file" name="image_file" accept="image/*">
    <br><br>

    <label for="estimated_completion_date">Estimated Completion Date:</label>
    <input type="date" name="estimated_completion_date" value="<?php echo htmlspecialchars($project['estimated_completion_date']); ?>">
    <br><br>

    <!-- Updated field for Solterra Fee (per watt) with 4 decimal places -->
    <label for="solterra_fee">Solterra Fee (per watt):</label>
    <input type="number" step="0.0001" name="solterra_fee"
           value="<?php echo isset($project['solterra_fee']) ? htmlspecialchars($project['solterra_fee']) : '0.0000'; ?>"
           required
    >
    <br><br>

    <!-- Wattage and Total Order Section -->
    <h2>Wattage and Total Order Quantities</h2>
    <div id="wattage-container">
        <?php foreach ($wattage_orders as $index => $order): ?>
            <div class="wattage-entry" id="wattage-entry-<?php echo $order['id']; ?>">
                <label>Wattage:</label>
                <input
                    type="number"
                    step="0.01"
                    name="wattages[<?php echo $order['id']; ?>]"
                    value="<?php echo htmlspecialchars($order['wattage']); ?>"
                    required
                >
                <label>Total Order Quantity:</label>
                <input
                    type="number"
                    name="total_orders[<?php echo $order['id']; ?>]"
                    value="<?php echo htmlspecialchars($order['total_order']); ?>"
                    required
                >
                <button type="button" onclick="removeExistingWattage(<?php echo $order['id']; ?>)">Remove</button>
            </div>
        <?php endforeach; ?>
    </div>
    <button type="button" onclick="addWattageField()">Add Wattage</button>
    <br><br>

    <input type="submit" value="Update Project">
</form>
<br>
<a href="admin_dashboard">Back to Admin Dashboard</a>
</body>
</html>
