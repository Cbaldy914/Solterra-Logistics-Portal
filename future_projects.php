<?php
session_name("logistics_session");
session_start();

// Check if the user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login");
    exit();
}

$user_id = $_SESSION['user_id'];

// Database connection
require_once '../config.php';
$conn = getDBConnection();
if (!$conn) {
    die("Connection failed");
}

// Fetch user's future projects
$stmt = $conn->prepare("SELECT id, name, address, size, estimated_start_date, image_path FROM forecast_projects WHERE user_id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();

$future_projects = [];
while ($row = $result->fetch_assoc()) {
    $future_projects[] = $row;
}
$stmt->close();

// Handle form submission for adding a new project
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_project'])) {
    // Retrieve form data
    $name = trim($_POST['name']);
    $address = trim($_POST['address']);
    $size = trim($_POST['size']);
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
        // Use default image
        $image_path = 'pictures/test.png';
    }

    // Save project to database
    $stmt = $conn->prepare("INSERT INTO forecast_projects (user_id, name, address, size, estimated_start_date, modules_data, image_path, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())");
    $stmt->bind_param("issssss", $user_id, $name, $address, $size, $estimated_start_date, $modules_data_json, $image_path);

    if ($stmt->execute()) {
        $stmt->close();
        // Redirect to the same page to refresh the list
        header("Location: future_projects");
        exit();
    } else {
        $error_message = "Error saving project: " . $stmt->error;
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Future Projects</title>
    <link rel="stylesheet" href="portal.css">
    <link rel="icon" href="pictures/favicon.png" type="image/x-icon">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        /* Styles for the Add Project Button */
        #add-project-button {
            background-color: #488C9A;
            color: white;
            padding: 10px 20px;
            font-size: 16px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-weight: bold;
        }

        #add-project-button:hover {
            background-color: #293E4C;
        }

        /* Styles for the action links */
        .action-links {
            text-align: center;
            margin-top: 10px;
        }

        .action-links a {
            color: white;
            padding: 6px 12px;
            font-size: 14px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-weight: bold;
            text-decoration: none;
            margin: 5px 0;
            display: block;
            width: 100%;
            box-sizing: border-box;
        }

        /* Delete Button */
        .action-links .delete-button {
            background-color: #E4572E;
        }

        .action-links .delete-button:hover {
            background-color: #c04525;
        }

        /* Add Project Form Styles */
        .add-project-form {
            display: none;
            position: absolute;
            top: 60px; /* Adjust based on your header height */
            left: 50%;
            transform: translateX(-50%);
            width: 40%;
            background-color: #fff;
            border: 1px solid #ccc;
            padding: 20px;
            z-index: 1000;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.2);
            border-radius: 8px;
        }

        .add-project-form h2 {
            margin-top: 0;
        }

        .add-project-form label {
            display: block;
            margin-top: 10px;
        }

        .add-project-form input,
        .add-project-form select {
            width: 95%;
            padding: 8px;
            margin-top: 5px;
            border-radius: 4px;
            border: 1px solid #ccc;
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

        .modules-container .module-input button {
            background-color: #E4572E;
            color: white;
            border: none;
            padding: 5px 10px;
            border-radius: 4px;
            cursor: pointer;
        }

        .modules-container .module-input button:hover {
            background-color: #c04525;
        }

        .error-message, .success-message {
            color: red;
            margin-top: 15px;
        }

        .required {
            color: red;
        }

        .close-modal {
            color: #293E4C;
            float: right;
            font-size: 28px;
            font-weight: bold;
            margin-top: -10px;
            cursor: pointer;
        }

        .close-modal:hover,
        .close-modal:focus {
            color: #488C9A;
            text-decoration: none;
        }

        .save-project-button {
            background-color: #488C9A;
            color: white;
            padding: 10px 20px;
            margin-top: 20px;
            border: none;
            border-radius: 5px;
            font-size: 16px;
            cursor: pointer;
            font-weight: bold;
        }

        .save-project-button:hover {
            background-color: #293E4C;
        }

        .module-actions {
            margin-top: 10px;
        }

        .module-actions button {
            background-color: #fbb040;
            color: white;
            padding: 8px 12px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-weight: bold;
        }

        .module-actions button:hover {
            background-color: #e0a030;
        }

        /* Additional styles for project items */
        .project-details button {
            border-radius: 5px;
            font-weight: bold;
        }

        /* Responsive adjustments */
        @media (max-width: 768px) {
            .project-item {
                width: 100%;
            }

            .add-project-form {
                width: 90%;
            }

            .modules-container .module-input {
                flex-direction: column;
                align-items: flex-start;
            }

            .modules-container .module-input label,
            .modules-container .module-input input,
            .modules-container .module-input button {
                margin-right: 0;
                margin-top: 5px;
            }
        }
        .info-tooltip {
            display: inline-block;
            width: 18px;
            height: 18px;
            line-height: 18px;
            text-align: center;
            background-color: #488C9A; /* Secondary blue color */
            color: white;
            border-radius: 50%;
            font-weight: bold;
            cursor: pointer;
            margin-left: 5px;
            position: relative;
            vertical-align: middle;
            top: -3px; /* Adjust this value to move the icon up or down */
            font-size:.5em
        }
        .info-tooltip:hover {
            background-color: #293E4C; /* Darker shade on hover */
        }
        .info-tooltip .tooltip-text {
            visibility: hidden;
            background-color: #fff;
            color: #333;
            text-align: left;
            border-radius: 4px;
            padding: 8px;
            position: absolute;
            z-index: 1;
            top: 25px;
            left: -200px;
            box-shadow: 0 0 5px rgba(0,0,0,0.3);
            font-weight: normal;
        }
        .info-tooltip:hover .tooltip-text {
            visibility: visible;
        }
    </style>
</head>
<body>
    <?php include 'header.php'; ?>
    <main>
    <h1>Future Projects
    <span class="info-tooltip">?
        <span class="tooltip-text">Adding a project is for forecasting and mapping logistics expenses. No costs will be incurred by adding a project. </span>
    </span>
    </h1>
    <?php
    if (isset($error_message)) {
        echo '<p class="error-message">' . htmlspecialchars($error_message) . '</p>';
    }
    ?>
    <!-- Add Project Button -->
    <button id="add-project-button">Add Project</button>

    <!-- Add Project Form -->
    <div id="add-project-form" class="add-project-form">
        <span class="close-modal">&times;</span>
        <h2>Add New Project</h2>
        <form method="POST" action="" enctype="multipart/form-data">
            <input type="hidden" name="add_project" value="1">
            <label for="name">Project Name:<span class="required">*</span></label>
            <input type="text" name="name" id="name" required>

            <label for="address">Project Address:</label>
            <input type="text" name="address" id="address">

            <label for="size">Project Size:<span class="required">*</span></label>
            <input type="text" name="size" id="size" required>

            <label for="estimated_start_date">Estimated Start Date:<span class="required">*</span></label>
            <input type="date" name="estimated_start_date" id="estimated_start_date" required>

            <label for="project_image">Upload Picture:</label>
            <input type="file" name="project_image" id="project_image" accept="image/*">

            <!-- Modules Data Input -->
            <h3>Modules per Wattage</h3>
            <div id="modules-container" class="modules-container">
                <div class="module-input">
                    <label>Wattage:</label>
                    <input type="number" name="modules_data[wattage][]" required>
                    <label>Number of Modules:</label>
                    <input type="number" name="modules_data[quantity][]" required>
                    <button type="button" onclick="removeModuleInput(this)">Remove</button>
                </div>
            </div>
            <div class="module-actions">
                <button type="button" onclick="addModuleInput()">Add More Modules</button>
            </div>

            <button type="submit" class="save-project-button">Save Project</button>
        </form>
    </div>
    <!-- List of Future Projects -->
    <?php if (!empty($future_projects)): ?>
        <div class="projects-container">
            <?php foreach ($future_projects as $project): ?>
                <?php
                // Handle cases where estimated_start_date is NULL or empty
                if (!empty($project['estimated_start_date'])) {
                    $date = new DateTime($project['estimated_start_date']);
                    $estimated_start_date_display = $date->format('F j, Y');
                } else {
                    $estimated_start_date_display = 'N/A';
                }
                ?>
                <div class="project-item">
                    <h3><a href="future_projects_details?id=<?php echo $project['id']; ?>"><?php echo htmlspecialchars($project['name']); ?></a></h3>
                    <div class="project-image">
                        <a href="future_projects_details?id=<?php echo $project['id']; ?>">
                            <img src="<?php echo htmlspecialchars($project['image_path']); ?>" alt="Project Image">
                        </a>
                    </div>
                    <div class="project-details">
                        <p><strong>Project Address:</strong> <span><?php echo htmlspecialchars($project['address']); ?></span></p>
                        <p><strong>Project Size:</strong> <span><?php echo htmlspecialchars($project['size']); ?></span></p>
                        <p><strong>Estimated Start Date:</strong> <span><?php echo htmlspecialchars($estimated_start_date_display); ?></span></p>
                        <?php
                        // Fetch associated estimates for this project
                        $stmt = $conn->prepare("SELECT estimate_type, estimate_id FROM forecast_items WHERE forecast_id = ?");
                        $stmt->bind_param("i", $project['id']);
                        $stmt->execute();
                        $result = $stmt->get_result();
                        $warehouse_estimate_ids = [];
                        $freight_estimate_ids = [];
                        while ($row = $result->fetch_assoc()) {
                            if ($row['estimate_type'] == 'warehouse') {
                                $warehouse_estimate_ids[] = $row['estimate_id'];
                            } elseif ($row['estimate_type'] == 'freight') {
                                $freight_estimate_ids[] = $row['estimate_id'];
                            }
                        }
                        $stmt->close();

                        // Fetch estimate costs
                        $total_logistics_cost = 0;

                        // Warehouse Estimates
                        $warehouse_cost = 0;
                        if (!empty($warehouse_estimate_ids)) {
                            $ids_placeholder = implode(',', array_fill(0, count($warehouse_estimate_ids), '?'));
                            $types = str_repeat('i', count($warehouse_estimate_ids));
                            $stmt = $conn->prepare("SELECT estimate_data FROM warehouse_estimates WHERE id IN ($ids_placeholder)");
                            $stmt->bind_param($types, ...$warehouse_estimate_ids);
                            $stmt->execute();
                            $result = $stmt->get_result();
                            while ($row = $result->fetch_assoc()) {
                                $estimate_data = json_decode($row['estimate_data'], true);

                                // Exclude comparison estimates
                                if (isset($estimate_data['grand_totals']) && count($estimate_data['grand_totals']) > 1) {
                                    continue; // Skip comparison estimates
                                }

                                $cost = isset($estimate_data['grand_totals']) ? array_sum($estimate_data['grand_totals']) : 0;
                                $warehouse_cost += $cost;
                            }
                            $stmt->close();
                            $total_logistics_cost += $warehouse_cost;
                        } else {
                            $warehouse_cost = null;
                        }

                        // Freight Estimates
                        $freight_cost = 0;
                        if (!empty($freight_estimate_ids)) {
                            $ids_placeholder = implode(',', array_fill(0, count($freight_estimate_ids), '?'));
                            $types = str_repeat('i', count($freight_estimate_ids));
                            $stmt = $conn->prepare("SELECT estimate_data FROM freight_estimates WHERE id IN ($ids_placeholder)");
                            $stmt->bind_param($types, ...$freight_estimate_ids);
                            $stmt->execute();
                            $result = $stmt->get_result();
                            while ($row = $result->fetch_assoc()) {
                                $estimate_data = json_decode($row['estimate_data'], true);
                                $cost = isset($estimate_data['grand_total']) ? $estimate_data['grand_total'] : 0;
                                $freight_cost += $cost;
                            }
                            $stmt->close();
                            $total_logistics_cost += $freight_cost;
                        } else {
                            $freight_cost = null;
                        }
                        ?>
                        <p>
                            <strong>Estimated Freight Cost:</strong>
                            <span>
                                <?php if ($freight_cost !== null): ?>
                                    $<?php echo number_format($freight_cost, 2); ?>
                                <?php else: ?>
                                    Not Assigned
                                <?php endif; ?>
                            </span>
                        </p>
                        <p>
                            <strong>Estimated Warehousing Cost:</strong>
                            <span>
                                <?php if ($warehouse_cost !== null): ?>
                                    $<?php echo number_format($warehouse_cost, 2); ?>
                                <?php else: ?>
                                    Not Assigned
                                <?php endif; ?>
                            </span>
                        </p>
                        <p>
                            <strong>Estimated Total Logistics Cost:</strong>
                            <span>$<?php echo number_format($total_logistics_cost, 2); ?></span>
                        </p>
                        <div class="action-links">
                            <a href="#" class="delete-button delete-project" data-id="<?php echo $project['id']; ?>">Delete</a>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <p>No Future Projects.</p>
    <?php endif; ?>
    </main>
    <script>
        // Show/Hide Add Project Form
        document.getElementById('add-project-button').addEventListener('click', function() {
            var form = document.getElementById('add-project-form');
            form.style.display = 'block';
        });

        function hideAddProjectForm() {
            document.getElementById('add-project-form').style.display = 'none';
        }

        // Close the Add Project Form when clicking on close button
        document.querySelector('.close-modal').addEventListener('click', function() {
            hideAddProjectForm();
        });

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

        // Handle Deletion of Projects
        var deleteProjectButtons = document.querySelectorAll('.delete-project');
        deleteProjectButtons.forEach(function(button) {
            button.addEventListener('click', function(event) {
                event.preventDefault();
                var projectId = this.getAttribute('data-id');
                if (confirm('Are you sure you want to delete this project?')) {
                    window.location.href = 'delete_future_project?id=' + projectId;
                }
            });
        });

        // Tooltip for mobile
        document.addEventListener('DOMContentLoaded', function() {
            var tooltips = document.querySelectorAll('.info-tooltip');
            tooltips.forEach(function(tooltip) {
                tooltip.addEventListener('click', function(e) {
                    e.stopPropagation();
                    this.classList.toggle('active');
                });
            });

            document.addEventListener('click', function(e) {
                tooltips.forEach(function(tooltip) {
                    if (!tooltip.contains(e.target)) {
                        tooltip.classList.remove('active');
                    }
                });
            });
        });
    </script>
</body>
</html>