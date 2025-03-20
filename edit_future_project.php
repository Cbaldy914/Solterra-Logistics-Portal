<?php
session_name("logistics_session");
session_start();

// Check if the user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login");
    exit();
}

$user_id = $_SESSION['user_id'];

// Check if project ID is provided
if (!isset($_GET['id'])) {
    die("Project ID not specified.");
}

$project_id = intval($_GET['id']);

// Database connection
$servername = "localhost";
$db_username = "SolterraSolutions";
$db_password = "CompanyAdmin!";
$dbname = "solterra_portal";

$conn = new mysqli($servername, $db_username, $db_password, $dbname);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Fetch project details
$stmt = $conn->prepare("SELECT name, estimated_start_date, modules_data, image_path FROM forecast_projects WHERE id = ? AND user_id = ?");
$stmt->bind_param("ii", $project_id, $user_id);
$stmt->execute();
$stmt->bind_result($name, $estimated_start_date, $modules_data_json, $image_path);
if ($stmt->fetch()) {
    $modules_data = json_decode($modules_data_json, true);
} else {
    die("Project not found or you do not have permission to edit it.");
}
$stmt->close();

// Fetch associated estimates
$warehouse_estimate_id = null;
$freight_estimate_id = null;

$stmt = $conn->prepare("SELECT estimate_type, estimate_id FROM forecast_items WHERE forecast_id = ?");
$stmt->bind_param("i", $project_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    if ($row['estimate_type'] == 'warehouse') {
        $warehouse_estimate_id = $row['estimate_id'];
    } elseif ($row['estimate_type'] == 'freight') {
        $freight_estimate_id = $row['estimate_id'];
    }
}
$stmt->close();

// Fetch user's saved estimates
// Warehouse estimates
$warehouse_estimates = [];
$stmt = $conn->prepare("SELECT id, name FROM warehouse_estimates WHERE user_id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$warehouse_result = $stmt->get_result();
while ($row = $warehouse_result->fetch_assoc()) {
    $warehouse_estimates[] = $row;
}
$stmt->close();

// Freight estimates
$freight_estimates = [];
$stmt = $conn->prepare("SELECT id, name FROM freight_estimates WHERE user_id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$freight_result = $stmt->get_result();
while ($row = $freight_result->fetch_assoc()) {
    $freight_estimates[] = $row;
}
$stmt->close();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['edit_project'])) {
    // Retrieve form data
    $name = trim($_POST['name']);
    $estimated_start_date = $_POST['estimated_start_date'];
    $modules_data = [
        'wattage' => $_POST['modules_data']['wattage'],
        'quantity' => $_POST['modules_data']['quantity']
    ];
    $modules_data_json = json_encode($modules_data);

    // Handle image upload
    if (isset($_FILES['project_image']) && $_FILES['project_image']['error'] == UPLOAD_ERR_OK) {
        $upload_dir = 'uploads/project_images/';
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }
        $image_name = basename($_FILES['project_image']['name']);
        $image_path = $upload_dir . time() . '_' . $image_name;
        move_uploaded_file($_FILES['project_image']['tmp_name'], $image_path);
    } else {
        // Keep existing image
        $image_path = $_POST['existing_image'];
    }

    // Update project in database
    $stmt = $conn->prepare("UPDATE forecast_projects SET name = ?, estimated_start_date = ?, modules_data = ?, image_path = ? WHERE id = ? AND user_id = ?");
    $stmt->bind_param("ssssii", $name, $estimated_start_date, $modules_data_json, $image_path, $project_id, $user_id);

    if ($stmt->execute()) {
        // Update associated estimates
        // Remove existing associations
        $stmt = $conn->prepare("DELETE FROM forecast_items WHERE forecast_id = ?");
        $stmt->bind_param("i", $project_id);
        $stmt->execute();

        // Associate warehouse estimate
        if (!empty($_POST['warehouse_estimate'])) {
            $warehouse_estimate_id = intval($_POST['warehouse_estimate']);
            $stmt = $conn->prepare("INSERT INTO forecast_items (forecast_id, estimate_type, estimate_id) VALUES (?, 'warehouse', ?)");
            $stmt->bind_param("ii", $project_id, $warehouse_estimate_id);
            $stmt->execute();
        }

        // Associate freight estimate
        if (!empty($_POST['freight_estimate'])) {
            $freight_estimate_id = intval($_POST['freight_estimate']);
            $stmt = $conn->prepare("INSERT INTO forecast_items (forecast_id, estimate_type, estimate_id) VALUES (?, 'freight', ?)");
            $stmt->bind_param("ii", $project_id, $freight_estimate_id);
            $stmt->execute();
        }

        $stmt->close();

        // Redirect back to future_projects
        header("Location: future_projects");
        exit();
    } else {
        $error_message = "Error updating project: " . $stmt->error;
    }
}

$conn->close();
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Edit Future Project</title>
    <link rel="stylesheet" href="portal.css">
    <!-- Include your preferred fonts and styles -->
    <style>
        /* Add your styles here */
        .edit-project-form {
            margin-top: 30px;
        }
        .edit-project-form label {
            display: block;
            margin-top: 10px;
        }
        .edit-project-form input, .edit-project-form select {
            width: 95%;
            padding: 8px;
            margin-top: 5px;
        }
        .modules-container .module-input {
            display: flex;
            align-items: center;
            margin-top: 10px;
        }
        .modules-container .module-input label {
            margin-right: 10px;
        }
        .modules-container .module-input input {
            margin-right: 10px;
            width: auto;
        }
        .error-message, .success-message {
            color: red;
            margin-top: 15px;
        }
        .current-image {
            margin-top: 10px;
        }
    </style>
</head>
<body>
    <?php include 'header.php'; ?>
    <h1>Edit Future Project</h1>

    <?php
    if (isset($error_message)) {
        echo '<p class="error-message">' . htmlspecialchars($error_message) . '</p>';
    }
    ?>

    <!-- Edit Project Form -->
    <div class="edit-project-form">
        <form method="POST" action="" enctype="multipart/form-data">
            <input type="hidden" name="edit_project" value="1">
            <input type="hidden" name="existing_image" value="<?php echo htmlspecialchars($image_path); ?>">
            <label for="name">Project Name:</label>
            <input type="text" name="name" id="name" required value="<?php echo htmlspecialchars($name); ?>">

            <label for="estimated_start_date">Estimated Start Date:</label>
            <input type="date" name="estimated_start_date" id="estimated_start_date" required value="<?php echo htmlspecialchars($estimated_start_date); ?>">

            <label for="project_image">Upload New Picture (optional):</label>
            <input type="file" name="project_image" id="project_image" accept="image/*">
            <div class="current-image">
                <p>Current Image:</p>
                <img src="<?php echo htmlspecialchars($image_path); ?>" alt="Project Image" style="max-width: 200px;">
            </div>

            <!-- Modules Data Input -->
            <h3>Modules per Wattage</h3>
            <div id="modules-container" class="modules-container">
                <?php
                for ($i = 0; $i < count($modules_data['wattage']); $i++) {
                    ?>
                    <div class="module-input">
                        <label>Wattage:</label>
                        <input type="number" name="modules_data[wattage][]" required value="<?php echo htmlspecialchars($modules_data['wattage'][$i]); ?>">
                        <label>Number of Modules:</label>
                        <input type="number" name="modules_data[quantity][]" required value="<?php echo htmlspecialchars($modules_data['quantity'][$i]); ?>">
                        <button type="button" onclick="removeModuleInput(this)">Remove</button>
                    </div>
                    <?php
                }
                ?>
            </div>
            <button type="button" onclick="addModuleInput()">Add More Modules</button>

            <!-- Associate Estimates -->
            <h3>Associate Estimates</h3>
            <label for="warehouse_estimate">Select Warehouse Estimate:</label>
            <select name="warehouse_estimate" id="warehouse_estimate">
                <option value="">--Select--</option>
                <?php foreach ($warehouse_estimates as $estimate): ?>
                    <option value="<?php echo $estimate['id']; ?>" <?php if ($estimate['id'] == $warehouse_estimate_id) echo 'selected'; ?>>
                        <?php echo htmlspecialchars($estimate['name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <label for="freight_estimate">Select Freight Estimate:</label>
            <select name="freight_estimate" id="freight_estimate">
                <option value="">--Select--</option>
                <?php foreach ($freight_estimates as $estimate): ?>
                    <option value="<?php echo $estimate['id']; ?>" <?php if ($estimate['id'] == $freight_estimate_id) echo 'selected'; ?>>
                        <?php echo htmlspecialchars($estimate['name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <button type="submit">Update Project</button>
            <a href="future_projects">Cancel</a>
        </form>
    </div>

    <script>
        // Modules Input Functions
        function addModuleInput() {
            var container = document.getElementById('modules-container');
            var div = document.createElement('div');
            div.className = 'module-input';
            div.innerHTML = `
                <label>Wattage:</label>
                <input type="number" name="modules_data[wattage][]" required>
                <label>Number of Modules:</label>
                <input type="number" name="modules_data[quantity][]" required>
                <button type="button" onclick="removeModuleInput(this)">Remove</button>
            `;
            container.appendChild(div);
        }

        function removeModuleInput(button) {
            button.parentElement.remove();
        }
    </script>
</body>
</html>
