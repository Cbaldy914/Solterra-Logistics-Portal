<?php
session_name("logistics_session");
session_start();

// Include error reporting for debugging (remove or comment out in production)
// ini_set('display_errors', 1);
// ini_set('display_startup_errors', 1);
// error_reporting(E_ALL);

// Check if the user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login");
    exit();
}

$user_id = $_SESSION['user_id'];

// Check if project ID is provided
if (!isset($_GET['id']) || empty($_GET['id'])) {
    die("Project ID is missing.");
}

$project_id = intval($_GET['id']);

// Database connection
$servername = "localhost";
$db_username = "SolterraSolutions"; // Replace with your database username
$db_password = "CompanyAdmin!";      // Replace with your database password
$dbname = "solterra_portal";

$conn = new mysqli($servername, $db_username, $db_password, $dbname);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Handle "Activate Project" button submission
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['make_active'])) {
    // Fetch project name
    $stmt = $conn->prepare("SELECT name FROM forecast_projects WHERE id = ? AND user_id = ?");
    $stmt->bind_param("ii", $project_id, $user_id);
    $stmt->execute();
    $stmt->bind_result($project_name);
    $stmt->fetch();
    $stmt->close();

    // Send email to cbaldy@solterrasol.com
    $to = 'cbaldy@solterrasol.com';
    $subject = 'Request to Make Project Active';
    $message = "User with ID $user_id has requested to make project '$project_name' (ID: $project_id) active.";
    $headers = 'From: noreply@solterrasol.com' . "\r\n" .
               'Reply-To: noreply@solterrasol.com' . "\r\n" .
               'X-Mailer: PHP/' . phpversion();
    // Use mail function to send email
    mail($to, $subject, $message, $headers);

    // Set a success message to display to the user
    $success_message = 'Your request has been sent. Solterra Solutions will be with you shortly.';
}

// Handle editing project details
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['save_project_details'])) {
    $new_address = $_POST['edit_address'];
    $new_size = $_POST['edit_size'];
    $new_estimated_start_date = $_POST['edit_estimated_start_date'];

    // Update the project details in the database
    $stmt = $conn->prepare("UPDATE forecast_projects SET address = ?, size = ?, estimated_start_date = ? WHERE id = ? AND user_id = ?");
    $stmt->bind_param("sssii", $new_address, $new_size, $new_estimated_start_date, $project_id, $user_id);
    if ($stmt->execute()) {
        $stmt->close();
        // Update the variables used in the page
        $address = $new_address;
        $size = $new_size;
        $estimated_start_date = $new_estimated_start_date;
        $display_estimated_start_date = date("F j, Y", strtotime($estimated_start_date));
        $success_message = 'Project details updated successfully.';
    } else {
        $error_message = 'Error updating project details: ' . $stmt->error;
        $stmt->close();
    }
}

// Fetch project details
$stmt = $conn->prepare("SELECT name, address, size, estimated_start_date, image_path FROM forecast_projects WHERE id = ? AND user_id = ?");
$stmt->bind_param("ii", $project_id, $user_id);
$stmt->execute();
$stmt->bind_result($name, $address, $size, $estimated_start_date, $image_path);
$stmt->fetch();
$stmt->close();

if (empty($name)) {
    die("Project not found or you do not have access to this project.");
}

// Format the estimated start date
if (!empty($estimated_start_date)) {
    $display_estimated_start_date = date("F j, Y", strtotime($estimated_start_date));
} else {
    $display_estimated_start_date = 'N/A';
}

// Handle assigning estimates
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['assign_estimates'])) {
    $estimate_type = $_POST['estimate_type'];
    $selected_estimates = isset($_POST['estimates']) ? array_map('intval', $_POST['estimates']) : [];

    // Delete existing estimates of this type for this project
    $stmt = $conn->prepare("DELETE FROM forecast_items WHERE forecast_id = ? AND estimate_type = ?");
    $stmt->bind_param("is", $project_id, $estimate_type);
    $stmt->execute();
    $stmt->close();

    // Insert new estimates
    if (!empty($selected_estimates)) {
        $stmt = $conn->prepare("INSERT INTO forecast_items (forecast_id, estimate_type, estimate_id) VALUES (?, ?, ?)");
        foreach ($selected_estimates as $estimate_id) {
            $stmt->bind_param("isi", $project_id, $estimate_type, $estimate_id);
            $stmt->execute();
        }
        $stmt->close();
    }

    // Redirect to refresh the page
    header("Location: future_projects_details?id=$project_id");
    exit();
}

// Handle removing estimates
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['remove_estimate'])) {
    $estimate_type = $_POST['estimate_type'];
    $estimate_id = intval($_POST['estimate_id']);

    // Delete the estimate from forecast_items
    $stmt = $conn->prepare("DELETE FROM forecast_items WHERE forecast_id = ? AND estimate_type = ? AND estimate_id = ?");
    $stmt->bind_param("isi", $project_id, $estimate_type, $estimate_id);
    $stmt->execute();
    $stmt->close();

    // Redirect to refresh the page
    header("Location: future_projects_details?id=$project_id");
    exit();
}

// Fetch assigned warehouse estimates
$assigned_warehouse_estimates = [];
$stmt = $conn->prepare("SELECT estimate_id FROM forecast_items WHERE forecast_id = ? AND estimate_type = 'warehouse'");
$stmt->bind_param("i", $project_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $assigned_warehouse_estimates[] = intval($row['estimate_id']);
}
$stmt->close();

// Fetch assigned freight estimates
$assigned_freight_estimates = [];
$stmt = $conn->prepare("SELECT estimate_id FROM forecast_items WHERE forecast_id = ? AND estimate_type = 'freight'");
$stmt->bind_param("i", $project_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $assigned_freight_estimates[] = intval($row['estimate_id']);
}
$stmt->close();

// Fetch user's saved warehouse estimates (exclude comparison estimates)
$warehouse_estimates = [];
$stmt = $conn->prepare("SELECT id, name, estimate_data FROM warehouse_estimates WHERE user_id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $estimate_data = json_decode($row['estimate_data'], true);

    // Exclude comparison estimates
    if (isset($estimate_data['grand_totals']) && count($estimate_data['grand_totals']) > 1) {
        continue; // Skip this estimate as it is a comparison estimate
    }

    // Update cost calculation
    $cost = isset($estimate_data['grand_totals']) ? array_sum($estimate_data['grand_totals']) : 0;
    $row['cost'] = $cost;
    $warehouse_estimates[] = $row;
}
$stmt->close();

// Fetch user's saved freight estimates
$freight_estimates = [];
$stmt = $conn->prepare("SELECT id, name, estimate_data FROM freight_estimates WHERE user_id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $estimate_data = json_decode($row['estimate_data'], true);
    $cost = isset($estimate_data['grand_total']) ? $estimate_data['grand_total'] : 0;
    $row['cost'] = $cost;
    $freight_estimates[] = $row;
}
$stmt->close();

// Calculate total costs
$total_warehouse_cost = 0;
$total_freight_cost = 0;

// Get costs for assigned warehouse estimates
$assigned_warehouse_estimate_details = [];
if (!empty($assigned_warehouse_estimates)) {
    $ids = array_map('intval', $assigned_warehouse_estimates);
    $ids_list = implode(',', $ids);
    $sql = "SELECT id, name, estimate_data FROM warehouse_estimates WHERE id IN ($ids_list)";
    $result = $conn->query($sql);
    while ($row = $result->fetch_assoc()) {
        $estimate_data = json_decode($row['estimate_data'], true);

        // Exclude comparison estimates
        if (isset($estimate_data['grand_totals']) && count($estimate_data['grand_totals']) > 1) {
            continue; // Skip this estimate as it is a comparison estimate
        }

        // Update cost calculation
        $cost = isset($estimate_data['grand_totals']) ? array_sum($estimate_data['grand_totals']) : 0;
        $total_warehouse_cost += $cost;
        $row['cost'] = $cost;
        $assigned_warehouse_estimate_details[] = $row;
    }
}

// Get costs for assigned freight estimates
$assigned_freight_estimate_details = [];
if (!empty($assigned_freight_estimates)) {
    $ids = array_map('intval', $assigned_freight_estimates);
    $ids_list = implode(',', $ids);
    $sql = "SELECT id, name, estimate_data FROM freight_estimates WHERE id IN ($ids_list)";
    $result = $conn->query($sql);
    while ($row = $result->fetch_assoc()) {
        $estimate_data = json_decode($row['estimate_data'], true);
        $cost = isset($estimate_data['grand_total']) ? $estimate_data['grand_total'] : 0;
        $total_freight_cost += $cost;
        $row['cost'] = $cost;
        $assigned_freight_estimate_details[] = $row;
    }
}

$total_logistics_cost = $total_warehouse_cost + $total_freight_cost;

?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Project Details - <?php echo htmlspecialchars($name); ?></title>
    <link rel="stylesheet" href="portal.css">
    <link rel="icon" href="pictures/favicon.png" type="image/x-icon">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        /* Project Overview Container */
        .project-container {
            display: flex;
            align-items: center;
            justify-content: flex-start;
            margin-top: 20px;
        }

        .project-image {
            flex: 0 0 300px;
        }

        .project-image img {
            max-width: 100%;
            height: auto;
            border: 1px solid #ccc;
            border-radius: 8px;
        }

        .project-details-wrapper {
            flex: 1;
            margin-left: 20px;
        }

        .project-details h1 {
            margin-bottom: 10px;
        }

        .project-details p {
            margin-bottom: 5px;
        }

        .project-actions {
            display: flex;
            gap: 20%;
        }


        .activate-project-button {
            background-color: #fbb040;
            color: white;
            padding: 8px 16px;
            border: none;
            cursor: pointer;
            border-radius: 4px;
            font-weight: bold;
            font-size: 1.4em;
        }

        .activate-project-button:hover {
            background-color: #e0a030;
        }

        .activate-project-form {
            display: inline-block;

        }

        .estimated-cost-wrapper {
            flex: 0 0 auto;
            margin-left: 20px;
            margin-right: 150px;
        }

        .estimated-cost {
            background-color: #f0f0f0;
            padding: 20px;
            width: 450px; /* Adjust this value to make it wider */
            border: 1px solid #ccc;
            border-radius: 8px;
            text-align: center;
            box-sizing: border-box; /* Include padding and border in width */
        }


        .estimated-cost h2 {
            margin-bottom: 10px;
            font-size: 24px;
        }

        .estimated-cost h3 {
            font-size: 28px;
            color: #e0a030;
            margin: 0;
        }

        /* Logistics Section */
        .logistics-section {
            margin-top: 40px;
        }

        .logistics-section h1 {
            margin-bottom: 20px;
        }

        .logistics-container {
            display: flex;
            flex-wrap: wrap;
            gap: 50px;
        }

        .logistics-subsection {
            flex: 1;
            min-width: 250px;
            border: 1px solid #ccc;
            padding: 15px;
            box-sizing: border-box;
            background-color: #f9f9f9;
            height:250px;
        }

        .logistics-subsection h3 {
            margin-bottom: 10px;
            font-size: 1.4em;
        }

        .logistics-subsection p {
            font-size: 1.1em;
        }

        .estimate-list {
            list-style-type: none;
            padding-left: 0;
            font-size: 1.1em;
        }

        .estimate-list li {
            margin-bottom: 5px;
        }

        .estimate-list li a {
            margin-left: 10px;
            color: #488C9A;
            text-decoration: none;
        }

        .estimate-list li a:hover {
            text-decoration: underline;
        }

        .assign-estimate-button,
        .create-estimate-button {
            display: inline-block;
            padding: 8px 16px;
            margin: 0;
            text-align: center;
            font-size: 1em;
            font-weight: bold;
            cursor: pointer;
            border-radius: 4px;
            border: none;
            color: white;
            box-sizing: border-box;
            text-decoration: none;
        }

        .assign-estimate-button {
            background-color: #BFBFBF;
        }

        .assign-estimate-button:hover {
            background-color: #A6A6A6;
        }

        .create-estimate-button {
            background-color: #488C9A;
            margin-left: 10px;
        }

        .create-estimate-button:hover {
            background-color: #293E4C;
        }

        .remove-estimate-button {
            background-color: transparent;
            border: none;
            color: red;
            cursor: pointer;
            font-size: 1em;
            margin-left: 10px;
        }

        .remove-estimate-button:hover {
            text-decoration: underline;
        }

        /* Modal Styles */
        .modal {
            display: none;
            position: fixed;
            z-index: 999;
            padding-top: 60px;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            overflow: auto;
            background-color: rgba(0,0,0,0.5);
        }

        .modal-content {
            background-color: #fefefe;
            margin: auto;
            padding: 20px;
            border: 1px solid #888;
            width: 90%;
            max-width: 400px;
            border-radius: 8px;
        }

        .close-modal {
            color: #aaa;
            float: right;
            font-size: 28px;
            font-weight: bold;
            margin-top: -10px;
            cursor: pointer;
        }

        .close-modal:hover,
        .close-modal:focus {
            color: black;
            text-decoration: none;
            cursor: pointer;
        }

        .modal form label {
            display: block;
            margin-top: 15px;
            font-weight: bold;
        }

        .modal form input,
        .modal form select {
            width: 100%;
            padding: 8px;
            margin-top: 5px;
            border-radius: 4px;
            border: 1px solid #ccc;
        }

        .modal-actions {
            margin-top: 15px;
        }

        .modal-button {
            background-color: #fbb040;
            color: white;
            padding: 8px 12px;
            text-decoration: none;
            display: inline-block;
            margin-right: 10px;
            border-radius: 4px;
            font-weight: bold;
        }

        .modal-button:hover {
            background-color: #e0a030;
        }

        .modal form button[type="submit"] {
            background-color: #488C9A;
            color: white;
            padding: 10px 20px;
            margin: 20px 0 0 0;
            border: none;
            border-radius: 4px;
            font-size: 1em;
            cursor: pointer;
            font-weight: bold;
        }

        .modal form button[type="submit"]:hover {
            background-color: #293E4C;
        }

        /* Responsive adjustments */
        @media (max-width: 768px) {
            .project-container {
                flex-direction: column;
                align-items: flex-start;
            }

            .project-details-wrapper {
                margin-left: 0;
                margin-top: 20px;
            }

            .project-actions {
                justify-content: flex-start;
            }

            .estimated-cost-wrapper {
                margin-left: 0;
                margin-top: 20px;
                margin-right: 0;
            }

            .logistics-container {
                flex-direction: column;
            }
        }

        /* Success and Error Messages */
        .success-message, .error-message {
            margin-top: 10px;
            padding: 10px;
            border-radius: 5px;
            max-width: 600px;
        }

        .success-message {
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .error-message {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        /* Tooltip Styles */
        .tooltip-container {
            position: relative;
            display: inline-block;
        }

        .tooltip-container .tooltip-text {
            visibility: hidden;
            width: 300px;
            background-color: #555;
            color: #fff;
            text-align: left;
            padding: 10px;
            border-radius: 6px;
            position: absolute;
            z-index: 1;
            bottom: 125%; /* Position above the button */
            left: 50%;
            margin-left: -150px; /* Center the tooltip */
            opacity: 0;
            transition: opacity 0.3s;
        }

        .tooltip-container .tooltip-text::after {
            content: '';
            position: absolute;
            top: 100%; /* At the bottom of the tooltip */
            left: 50%;
            margin-left: -5px;
            border-width: 5px;
            border-style: solid;
            border-color: #555 transparent transparent transparent;
        }

        .tooltip-container:hover .tooltip-text {
            visibility: visible;
            opacity: 1;
        }
    </style>
</head>
<body>
<?php include 'header.php'; ?>
<main>
    <a href="future_projects" class="back-icon">
        <!-- SVG for Back Arrow -->
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24">
            <path d="M10 19c-.39 0-.78-.15-1.06-.44L3.5 13.06a1.5 1.5 0 010-2.12l5.44-5.5a1.5 1.5 0 012.12 2.12L7.12 11H19a1.5 1.5 0 010 3H7.12l3.44 3.44a1.5 1.5 0 01-1.06 2.56z"/>
        </svg>
        Back
    </a>
    <div class="project-container">
        <div class="project-image">
            <img src="<?php echo htmlspecialchars($image_path); ?>" alt="Project Image">
        </div>
        <div class="project-details-wrapper">
            <div class="project-details">
                <h1><?php echo htmlspecialchars($name); ?></h1>
                <p><strong>Project Address:</strong> <?php echo htmlspecialchars($address); ?></p>
                <p><strong>Project Size:</strong> <?php echo htmlspecialchars($size); ?></p>
                <p><strong>Estimated Start Date:</strong> <?php echo htmlspecialchars($display_estimated_start_date); ?></p>

                <?php if (isset($success_message)): ?>
                    <div class="success-message">
                        <?php echo htmlspecialchars($success_message); ?>
                    </div>
                <?php endif; ?>

                <?php if (isset($error_message)): ?>
                    <div class="error-message">
                        <?php echo htmlspecialchars($error_message); ?>
                    </div>
                <?php endif; ?>
            </div>
            <div class="project-actions">
                <button type="button" class="edit-project-button">Edit</button>
                <form method="POST" action="" class="activate-project-form">
                    <div class="tooltip-container">
                        <button type="submit" name="make_active" class="activate-project-button">Activate Project</button>
                        <span class="tooltip-text">
                            Clicking 'Activate Project' will send a message to Solterra Solutions letting them know that you would like to add this project to the main dashboard as an 'Active Project'. You will not incur any charges by making a project active.
                        </span>
                    </div>
                </form>
            </div>
        </div>
        <div class="estimated-cost-wrapper">
            <div class="estimated-cost">
                <h2>Estimated Cost</h2>
                <h3>$<?php echo number_format($total_logistics_cost, 2); ?></h3>
            </div>
        </div>
    </div>

    <!-- Edit Project Modal -->
    <div id="editProjectModal" class="modal">
        <div class="modal-content">
            <span class="close-modal">&times;</span>
            <h3>Edit Project Details</h3>
            <form method="POST" action="">
                <label for="edit_address">Project Address:</label>
                <input type="text" name="edit_address" id="edit_address" value="<?php echo htmlspecialchars($address); ?>" required>

                <label for="edit_size">Project Size:</label>
                <input type="text" name="edit_size" id="edit_size" value="<?php echo htmlspecialchars($size); ?>" required>

                <label for="edit_estimated_start_date">Estimated Start Date:</label>
                <input type="date" name="edit_estimated_start_date" id="edit_estimated_start_date" value="<?php echo htmlspecialchars($estimated_start_date); ?>" required>

                <button type="submit" name="save_project_details">Save Changes</button>
            </form>
        </div>
    </div>

    <!-- Logistics Section -->
    <div class="logistics-section">
        <h1>Create or Assign Estimate</h1>
        <div class="logistics-container">
            <!-- Warehousing Cost Section -->
            <div class="logistics-subsection">
                <h3>Warehousing Cost</h3>
                <p><strong>Total Warehousing Cost:</strong> $<?php echo number_format($total_warehouse_cost, 2); ?></p>
                <?php if (!empty($assigned_warehouse_estimate_details)): ?>
                    <ul class="estimate-list">
                        <?php foreach ($assigned_warehouse_estimate_details as $estimate): ?>
                            <li>
                                <?php echo htmlspecialchars($estimate['name']); ?> - $<?php echo number_format($estimate['cost'], 2); ?>
                                <a href="view_estimate?id=<?php echo $estimate['id']; ?>">View</a>
                                <form method="POST" action="" style="display:inline;">
                                    <input type="hidden" name="remove_estimate" value="1">
                                    <input type="hidden" name="estimate_type" value="warehouse">
                                    <input type="hidden" name="estimate_id" value="<?php echo $estimate['id']; ?>">
                                    <button type="submit" class="remove-estimate-button">Remove</button>
                                </form>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php else: ?>
                    <p>No warehousing estimates assigned.</p>
                <?php endif; ?>
                <div class="assign-estimate-actions">
                    <button class="assign-estimate-button" data-type="warehouse">Assign Estimate</button>
                    <button class="create-estimate-button" onclick="window.location.href='cost_estimate_calculator'">Create Estimate</button>
                </div>
            </div>
            <!-- Freight Cost Section -->
            <div class="logistics-subsection">
                <h3>Freight Cost</h3>
                <p><strong>Total Freight Cost:</strong> $<?php echo number_format($total_freight_cost, 2); ?></p>
                <?php if (!empty($assigned_freight_estimate_details)): ?>
                    <ul class="estimate-list">
                        <?php foreach ($assigned_freight_estimate_details as $estimate): ?>
                            <li>
                                <?php echo htmlspecialchars($estimate['name']); ?> - $<?php echo number_format($estimate['cost'], 2); ?>
                                <a href="view_freight_estimate?id=<?php echo $estimate['id']; ?>">View</a>
                                <form method="POST" action="" style="display:inline;">
                                    <input type="hidden" name="remove_estimate" value="1">
                                    <input type="hidden" name="estimate_type" value="freight">
                                    <input type="hidden" name="estimate_id" value="<?php echo $estimate['id']; ?>">
                                    <button type="submit" class="remove-estimate-button">Remove</button>
                                </form>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php else: ?>
                    <p>No freight estimates assigned.</p>
                <?php endif; ?>
                <div class="assign-estimate-actions">
                    <button class="assign-estimate-button" data-type="freight">Assign Estimate</button>
                    <button class="create-estimate-button" onclick="window.location.href='freight_estimate'">Create Estimate</button>
                </div>
            </div>
        </div>
    </div>
</main>

<!-- Assign Estimate Modal -->
<div id="assignEstimateModal" class="modal">
    <div class="modal-content">
        <span class="close-modal">&times;</span>
        <h3 id="modal-title">Assign Estimates</h3>
        <form method="POST" action="">
            <input type="hidden" name="assign_estimates" value="1">
            <input type="hidden" name="estimate_type" id="estimate_type" value="">
            <label for="estimate_select">Select Estimates:</label>
            <select name="estimates[]" id="estimate_select" multiple size="5">
                <!-- Options will be populated dynamically via JavaScript -->
            </select>
            <div class="modal-actions">
                <a href="#" id="viewEstimateLink" class="modal-button" style="display: none;">View Estimate</a>
            </div>
            <button type="submit">Assign Selected Estimates</button>
        </form>
    </div>
</div>

<script>
    // JavaScript to handle Assign Estimate Modal

    // Variables to hold assigned estimates
    var assignedWarehouseEstimates = <?php echo json_encode($assigned_warehouse_estimates); ?>;
    var assignedFreightEstimates = <?php echo json_encode($assigned_freight_estimates); ?>;

    document.querySelectorAll('.assign-estimate-button').forEach(function(button) {
        button.addEventListener('click', function() {
            var estimateType = this.getAttribute('data-type');
            var modal = document.getElementById('assignEstimateModal');
            var estimateSelect = document.getElementById('estimate_select');
            var estimateTypeInput = document.getElementById('estimate_type');
            var modalTitle = document.getElementById('modal-title');
            var viewEstimateLink = document.getElementById('viewEstimateLink');

            estimateTypeInput.value = estimateType;
            modalTitle.textContent = 'Assign ' + (estimateType.charAt(0).toUpperCase() + estimateType.slice(1)) + ' Estimates';

            // Hide View Estimate link initially
            viewEstimateLink.style.display = 'none';

            // Clear existing options
            estimateSelect.innerHTML = '';

            var estimates = [];
            var assignedEstimates = [];
            <?php
            echo 'var warehouseEstimates = ' . json_encode($warehouse_estimates) . ';';
            echo 'var freightEstimates = ' . json_encode($freight_estimates) . ';';
            ?>

            if (estimateType === 'warehouse') {
                estimates = warehouseEstimates;
                assignedEstimates = assignedWarehouseEstimates;
            } else if (estimateType === 'freight') {
                estimates = freightEstimates;
                assignedEstimates = assignedFreightEstimates;
            }

            estimates.forEach(function(estimate) {
                var option = document.createElement('option');
                var cost = estimate.cost || 0;
                option.value = estimate.id;
                option.text = estimate.name + ' - $' + Number(cost).toFixed(2);
                if (assignedEstimates.includes(parseInt(estimate.id))) {
                    option.selected = true;
                }
                estimateSelect.appendChild(option);
            });

            modal.style.display = 'block';

            // Add event listener to update View Estimate link
            estimateSelect.addEventListener('change', function() {
                var selectedOptions = Array.from(this.selectedOptions);
                if (selectedOptions.length === 1) {
                    var estimateId = selectedOptions[0].value;
                    if (estimateType === 'warehouse') {
                        viewEstimateLink.href = 'view_estimate?id=' + estimateId;
                    } else if (estimateType === 'freight') {
                        viewEstimateLink.href = 'view_freight_estimate?id=' + estimateId;
                    }
                    viewEstimateLink.style.display = 'inline-block';
                } else {
                    viewEstimateLink.style.display = 'none';
                }
            });
        });
    });

    // Close modal when clicking on close button
    document.querySelectorAll('.close-modal').forEach(function(closeBtn) {
        closeBtn.addEventListener('click', function() {
            this.parentElement.parentElement.style.display = 'none';
        });
    });

    // Close modal when clicking outside of modal content
    window.onclick = function(event) {
        var assignModal = document.getElementById('assignEstimateModal');
        var editModal = document.getElementById('editProjectModal');
        if (event.target == assignModal) {
            assignModal.style.display = 'none';
        }
        if (event.target == editModal) {
            editModal.style.display = 'none';
        }
    }

    // Show Edit Project Modal
    document.querySelector('.edit-project-button').addEventListener('click', function() {
        document.getElementById('editProjectModal').style.display = 'block';
    });
</script>
</body>
</html>
<?php
$conn->close();
?>
