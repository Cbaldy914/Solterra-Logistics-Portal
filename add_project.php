<?php
session_name("logistics_session");
session_start();
// Check if the user is an admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'global_admin') {
    header("Location: unauthorized");
    exit();
}

// Database connection
require_once '../config.php';
$conn = getDBConnection();
if (!$conn) {
    die("Connection failed");
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Add Project</title>
    <link rel="stylesheet" href="portal.css">
    <link rel="icon" href="pictures/favicon.png" type="image/x-icon">
    <link href="https://fonts.googleapis.com/css?family=Poppins:300,400,500,600,700&display=swap" rel="stylesheet">
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
            wattageInput.name = 'wattages[' + index + ']';
            wattageInput.required = true;

            var totalOrderLabel = document.createElement('label');
            totalOrderLabel.textContent = 'Total Order Quantity:';
            var totalOrderInput = document.createElement('input');
            totalOrderInput.type = 'number';
            totalOrderInput.name = 'total_orders[' + index + ']';
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
    </script>
</head>
<body>
<?php include 'header.php'; ?>
<h1>Add Project</h1>
<form action="process_add_project" method="POST" enctype="multipart/form-data">
    <!-- Existing project fields -->
    <label for="user_id">User ID:</label>
    <input type="number" name="user_id" required><br><br>

    <label for="project_name">Project Name:</label>
    <input type="text" name="project_name" required><br><br>

    <label for="project_address">Project Address:</label>
    <input type="text" name="project_address" required><br><br>

    <!-- Image File Upload -->
    <label for="image_file">Project Image:</label>
    <input type="file" name="image_file" accept="image/*"><br><br>

    <label for="estimated_completion_date">Estimated Completion Date:</label>
    <input type="date" name="estimated_completion_date"><br><br>

    <!-- New Solterra Fee Field (per watt) -->
    <label for="solterra_fee">Solterra Fee (per watt):</label>
    <input type="number" step="0.0001" name="solterra_fee" value="0.0000" required>
    <br><br>

    <!-- Wattage and Total Order Section -->
    <h2>Wattage and Total Order Quantities</h2>
    <div id="wattage-container">
        <!-- Dynamic wattage-total order fields will be added here -->
    </div>
    <button type="button" onclick="addWattageField()">Add Wattage</button><br><br>

    <input type="submit" value="Add Project">
</form>
<br>
<a href="admin_dashboard">Back to Admin Dashboard</a>
</body>
</html>
