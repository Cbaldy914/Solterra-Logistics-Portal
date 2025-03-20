<?php
session_name("logistics_session");
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: login");
    exit();
}

if (!isset($_GET['id'])) {
    die("Forecast ID not specified.");
}

$forecast_id = intval($_GET['id']);
$user_id = $_SESSION['user_id'];

// Database connection
$conn = new mysqli($servername, $db_username, $db_password, $dbname);

// Check if the forecast belongs to the user
$stmt = $conn->prepare("SELECT id FROM forecast_projects WHERE id = ? AND user_id = ?");
$stmt->bind_param("ii", $forecast_id, $user_id);
$stmt->execute();
$stmt->store_result();
if ($stmt->num_rows == 0) {
    die("You do not have permission to access this forecast project.");
}
$stmt->close();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $warehouse_estimate_id = intval($_POST['warehouse_estimate']);
    $freight_estimate_id = intval($_POST['freight_estimate']);

    // Save associations in forecast_items
    // First, delete any existing associations
    $stmt = $conn->prepare("DELETE FROM forecast_items WHERE forecast_id = ?");
    $stmt->bind_param("i", $forecast_id);
    $stmt->execute();
    $stmt->close();

    // Insert warehouse estimate
    $stmt = $conn->prepare("INSERT INTO forecast_items (forecast_id, estimate_type, estimate_id) VALUES (?, 'warehouse', ?)");
    $stmt->bind_param("ii", $forecast_id, $warehouse_estimate_id);
    $stmt->execute();
    $stmt->close();

    // Insert freight estimate
    $stmt = $conn->prepare("INSERT INTO forecast_items (forecast_id, estimate_type, estimate_id) VALUES (?, 'freight', ?)");
    $stmt->bind_param("ii", $forecast_id, $freight_estimate_id);
    $stmt->execute();
    $stmt->close();

    // Redirect to the forecast view page
    header("Location: view_forecast?id=" . $forecast_id);
    exit();
}

// Fetch user's warehouse and freight estimates
$warehouse_estimates = [];
$freight_estimates = [];

// Warehouse estimates
$stmt = $conn->prepare("SELECT id, name FROM warehouse_estimates WHERE user_id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $warehouse_estimates[] = $row;
}
$stmt->close();

// Freight estimates
$stmt = $conn->prepare("SELECT id, name FROM freight_estimates WHERE user_id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $freight_estimates[] = $row;
}
$stmt->close();

$conn->close();
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Associate Estimates</title>
    <link rel="stylesheet" href="portal.css">
    <link rel="icon" href="pictures/favicon.png" type="image/x-icon">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
</head>
<body>
    <?php include 'header.php'; ?>
    <h1>Associate Estimates with Forecast</h1>
    <form method="POST" action="">
        <label for="warehouse_estimate">Select Warehouse Estimate:</label>
        <select name="warehouse_estimate" id="warehouse_estimate" required>
            <option value="">--Select--</option>
            <?php foreach ($warehouse_estimates as $estimate): ?>
                <option value="<?php echo $estimate['id']; ?>"><?php echo htmlspecialchars($estimate['name']); ?></option>
            <?php endforeach; ?>
        </select>

        <label for="freight_estimate">Select Freight Estimate:</label>
        <select name="freight_estimate" id="freight_estimate" required>
            <option value="">--Select--</option>
            <?php foreach ($freight_estimates as $estimate): ?>
                <option value="<?php echo $estimate['id']; ?>"><?php echo htmlspecialchars($estimate['name']); ?></option>
            <?php endforeach; ?>
        </select>

        <button type="submit">Save Associations</button>
    </form>
</body>
</html>
