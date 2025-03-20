<?php
session_name("logistics_session");
session_start();

// Check if user is admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: dashboard");
    exit();
}

// Database connection
$servername = "localhost";
$db_username = "SolterraSolutions";
$db_password = "CompanyAdmin!";
$dbname = "solterra_portal";

$conn = new mysqli($servername, $db_username, $db_password, $dbname);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Fetch all projects
$stmt = $conn->prepare("SELECT id, project_name, forecasted_costs FROM projects ORDER BY project_name ASC");
$stmt->execute();
$projects_result = $stmt->get_result();
$stmt->close();

$projects = [];
while ($row = $projects_result->fetch_assoc()) {
    $projects[] = $row;
}

// Fetch warehouse estimates (adjust query as needed)
$warehouse_estimates = [];
$result = $conn->query("SELECT id, name, estimate_data FROM warehouse_estimates ORDER BY name ASC");
while ($row = $result->fetch_assoc()) {
    // Assuming estimate_data is JSON with a cost field or a structure from which you can extract cost
    // If you store cost differently, adjust this logic.
    $estimate_data = json_decode($row['estimate_data'], true);
    // If you have a known structure, for example grand_totals:
    $cost = 0;
    if (isset($estimate_data['grand_totals'])) {
        $cost = array_sum($estimate_data['grand_totals']);
    } elseif (isset($estimate_data['grand_total'])) {
        $cost = $estimate_data['grand_total'];
    }
    $row['cost'] = $cost;
    $warehouse_estimates[] = $row;
}
$result->close();

// Fetch freight estimates (adjust query as needed)
$freight_estimates = [];
$result = $conn->query("SELECT id, name, estimate_data FROM freight_estimates ORDER BY name ASC");
while ($row = $result->fetch_assoc()) {
    $estimate_data = json_decode($row['estimate_data'], true);
    $cost = isset($estimate_data['grand_total']) ? $estimate_data['grand_total'] : 0;
    $row['cost'] = $cost;
    $freight_estimates[] = $row;
}
$result->close();

$error = '';
$success = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $project_id = isset($_POST['project_id']) ? intval($_POST['project_id']) : 0;

    if ($project_id <= 0) {
        $error = "Please select a project.";
    } else {
        $manual_freight = isset($_POST['freight_manual']) && $_POST['freight_manual'] !== '' ? floatval($_POST['freight_manual']) : 0;
        $manual_warehouse = isset($_POST['warehouse_manual']) && $_POST['warehouse_manual'] !== '' ? floatval($_POST['warehouse_manual']) : 0;
        $manual_accessorial = isset($_POST['accessorial_manual']) && $_POST['accessorial_manual'] !== '' ? floatval($_POST['accessorial_manual']) : 0;

        $new_forecast = [
            'freight' => $manual_freight,
            'warehousing' => $manual_warehouse,
            'accessorial' => $manual_accessorial
        ];
        $new_forecast_json = json_encode($new_forecast);

        $stmt = $conn->prepare("UPDATE projects SET forecasted_costs = ? WHERE id = ?");
        $stmt->bind_param("si", $new_forecast_json, $project_id);
        $stmt->execute();
        $stmt->close();

        $success = "Forecasted costs updated successfully!";
    }
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Assign Forecasted Costs</title>
<link rel="stylesheet" href="portal.css">
<link rel="icon" href="pictures/favicon.png" type="image/x-icon">
<link href="https://fonts.googleapis.com/css?family=Poppins:300,400,500,600,700&display=swap" rel="stylesheet">
<style>
    .form-section {
        margin-bottom: 30px;
    }
    .form-section h2 {
        margin-bottom: 10px;
    }
    select, input[type="number"], input[type="text"] {
        padding: 5px;
        margin: 5px 0;
        width: 100%;
        max-width: 300px;
    }
    .button {
        padding: 10px 20px;
        background-color: #488C9A;
        color: #fff;
        text-decoration: none;
        border-radius: 5px;
        border: none;
        cursor: pointer;
        margin-top: 10px;
        font-weight: bold;
        font-size: 1em;
    }
    .success {
        color: green;
        font-weight: 500;
        margin-bottom: 20px;
    }
    .error {
        color: red;
        font-weight: 500;
        margin-bottom: 20px;
    }
    .inline-group {
        display: flex;
        align-items: center;
        gap: 10px;
        margin-top: 10px;
    }

    /* Modal Styles (Similar to future_projects_details) */
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
        position: relative;
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

    .modal h3 {
        margin-top:0;
    }

    .modal label {
        display: block;
        margin-top: 10px;
        font-weight: bold;
    }

    .modal select {
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

    .modal form button[type="button"] {
        padding: 10px 20px;
        background-color: #488C9A;
        color: #fff;
        text-decoration: none;
        border-radius: 5px;
        border: none;
        cursor: pointer;
        margin-top: 10px;
    }

    .modal form button[type="button"]:hover {
        background-color: #293E4C;
    }
</style>
</head>
<body>
<?php include 'header.php'; ?>
<main>
    <h1>Assign Forecasted Costs</h1>

    <?php if (!empty($success)): ?>
        <p class="success"><?php echo htmlspecialchars($success); ?></p>
    <?php endif; ?>
    <?php if (!empty($error)): ?>
        <p class="error"><?php echo htmlspecialchars($error); ?></p>
    <?php endif; ?>

    <form method="post">

        <!-- Select Project -->
        <div class="form-section">
            <h2>Select Project (Required)</h2>
            <select name="project_id" required>
                <option value="">--Select a Project--</option>
                <?php foreach ($projects as $proj): ?>
                    <option value="<?php echo $proj['id']; ?>"><?php echo htmlspecialchars($proj['project_name']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <!-- Assign Warehouse Estimate -->
        <div class="form-section">
            <h2>Assign Warehouse Estimate</h2>
            <label for="warehouse_manual">Manual Warehouse Amount:</label>
            <input type="number" step="0.01" name="warehouse_manual" id="warehouse_manual" placeholder="Enter a value or leave blank">

            <div class="inline-group">
                <span>Or</span>
                <button type="button" class="button" onclick="openModal('warehouse')">Select from Estimates</button>
            </div>
        </div>

        <!-- Assign Freight Estimate -->
        <div class="form-section">
            <h2>Assign Freight Estimate</h2>
            <label for="freight_manual">Manual Freight Amount:</label>
            <input type="number" step="0.01" name="freight_manual" id="freight_manual" placeholder="Enter a value or leave blank">

            <div class="inline-group">
                <span>Or</span>
                <button type="button" class="button" onclick="openModal('freight')">Select from Estimates</button>
            </div>
        </div>

        <!-- Assign Accessorial Estimate -->
        <div class="form-section">
            <h2>Assign Accessorial Estimate</h2>
            <label for="accessorial_manual">Manual Accessorial Amount:</label>
            <input type="number" step="0.01" name="accessorial_manual" id="accessorial_manual" placeholder="Enter a value or leave blank">
            <!-- No modal needed for Accessorial -->
        </div>

        <button type="submit" class="button">Save Forecasted Costs</button>
    </form>
</main>

<!-- Assign Estimate Modal -->
<div id="assignEstimateModal" class="modal">
    <div class="modal-content">
        <span class="close-modal">&times;</span>
        <h3 id="modal-title">Assign Estimates</h3>
        <form onsubmit="return false;">
            <input type="hidden" id="estimate_type" value="">
            <label for="estimate_select">Select Estimates:</label>
            <select id="estimate_select" size="5">
                <!-- Options populated by JS -->
            </select>
            <div class="modal-actions">
                <a href="#" id="viewEstimateLink" class="modal-button" style="display: none;" target="_blank">View Estimate</a>
            </div>
            <button type="button" onclick="assignSelectedEstimates()">Assign Selected Estimates</button>
        </form>
    </div>
</div>

<script>
var warehouseEstimates = <?php echo json_encode($warehouse_estimates); ?>;
var freightEstimates = <?php echo json_encode($freight_estimates); ?>;

var currentMode = ''; // 'warehouse' or 'freight'
var assignModal = document.getElementById('assignEstimateModal');
var estimateSelect = document.getElementById('estimate_select');
var modalTitle = document.getElementById('modal-title');
var estimateTypeInput = document.getElementById('estimate_type');
var viewEstimateLink = document.getElementById('viewEstimateLink');

// Open modal with estimates
function openModal(mode) {
    currentMode = mode;
    modalTitle.textContent = 'Assign ' + (mode === 'warehouse' ? 'Warehouse' : 'Freight') + ' Estimates';
    estimateTypeInput.value = mode;

    // Clear existing options
    estimateSelect.innerHTML = '';
    var data = (mode === 'warehouse') ? warehouseEstimates : freightEstimates;
    data.forEach(function(item) {
        var option = document.createElement('option');
        var cost = item.cost || 0;
        option.value = item.id + '|' + cost;
        option.text = item.name + ' - $' + cost.toFixed(2);
        estimateSelect.appendChild(option);
    });

    viewEstimateLink.style.display = 'none';
    assignModal.style.display = 'block';
}

// Close modal
function closeModal() {
    assignModal.style.display = 'none';
}

// View and Assign logic
estimateSelect.addEventListener('change', function() {
    var selectedOptions = Array.from(this.selectedOptions);
    if (selectedOptions.length === 1) {
        // Show view link
        var val = selectedOptions[0].value.split('|');
        var estimateId = val[0];
        if (currentMode === 'warehouse') {
            viewEstimateLink.href = 'view_estimate?id=' + estimateId;
        } else {
            viewEstimateLink.href = 'view_freight_estimate?id=' + estimateId;
        }
        viewEstimateLink.style.display = 'inline-block';
    } else {
        viewEstimateLink.style.display = 'none';
    }
});

function assignSelectedEstimates() {
    var selectedOptions = Array.from(estimateSelect.selectedOptions);
    if (selectedOptions.length === 0) {
        alert('Please select at least one estimate.');
        return;
    }

    // If multiple selected, assign the first one
    var val = selectedOptions[0].value.split('|');
    var cost = parseFloat(val[1]);
    if (currentMode === 'warehouse') {
        document.getElementById('warehouse_manual').value = cost.toFixed(2);
    } else {
        document.getElementById('freight_manual').value = cost.toFixed(2);
    }

    closeModal();
    alert('Estimate assigned!');
}

// Close modal on click outside or on close button
document.querySelector('.close-modal').onclick = closeModal;
window.onclick = function(event) {
    if (event.target == assignModal) {
        closeModal();
    }
};
</script>

</body>
</html>
