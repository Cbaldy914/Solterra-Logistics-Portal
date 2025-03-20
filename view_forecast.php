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

// Fetch forecast project details
$stmt = $conn->prepare("SELECT name, estimated_start_date, modules_data, created_at FROM forecast_projects WHERE id = ? AND user_id = ?");
$stmt->bind_param("ii", $forecast_id, $user_id);
$stmt->execute();
$stmt->bind_result($name, $estimated_start_date, $modules_data_json, $created_at);
if ($stmt->fetch()) {
    $modules_data = json_decode($modules_data_json, true);
} else {
    die("Forecast not found or you do not have permission to view it.");
}
$stmt->close();

// Fetch associated estimates
$warehouse_estimate = null;
$freight_estimate = null;

$stmt = $conn->prepare("SELECT estimate_type, estimate_id FROM forecast_items WHERE forecast_id = ?");
$stmt->bind_param("i", $forecast_id);
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

// Fetch warehouse estimate data
if (isset($warehouse_estimate_id)) {
    $stmt = $conn->prepare("SELECT estimate_data FROM warehouse_estimates WHERE id = ? AND user_id = ?");
    $stmt->bind_param("ii", $warehouse_estimate_id, $user_id);
    $stmt->execute();
    $stmt->bind_result($warehouse_estimate_data_json);
    if ($stmt->fetch()) {
        $warehouse_estimate = json_decode($warehouse_estimate_data_json, true);
    }
    $stmt->close();
}

// Fetch freight estimate data
if (isset($freight_estimate_id)) {
    $stmt = $conn->prepare("SELECT estimate_data FROM freight_estimates WHERE id = ? AND user_id = ?");
    $stmt->bind_param("ii", $freight_estimate_id, $user_id);
    $stmt->execute();
    $stmt->bind_result($freight_estimate_data_json);
    if ($stmt->fetch()) {
        $freight_estimate = json_decode($freight_estimate_data_json, true);
    }
    $stmt->close();
}

$conn->close();

// Calculate total estimated logistic costs
$total_logistic_cost = 0;
if ($warehouse_estimate && isset($warehouse_estimate['grand_total'])) {
    $total_logistic_cost += $warehouse_estimate['grand_total'];
}
if ($freight_estimate && isset($freight_estimate['grand_total'])) {
    $total_logistic_cost += $freight_estimate['grand_total'];
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Forecast</title>
    <link rel="stylesheet" href="portal.css">
    <link rel="icon" href="pictures/favicon.png" type="image/x-icon">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body>
    <?php include 'header.php'; ?>
    <h1><?php echo htmlspecialchars($name); ?></h1>
    <p><strong>Created At:</strong> <?php echo htmlspecialchars($created_at); ?></p>
    <p><strong>Estimated Start Date:</strong> <?php echo htmlspecialchars($estimated_start_date); ?></p>

    <h2>Modules Data</h2>
    <table>
        <tr>
            <th>Wattage</th>
            <th>Number of Modules</th>
        </tr>
        <?php
        for ($i = 0; $i < count($modules_data['wattage']); $i++) {
            echo '<tr>';
            echo '<td>' . htmlspecialchars($modules_data['wattage'][$i]) . '</td>';
            echo '<td>' . htmlspecialchars($modules_data['quantity'][$i]) . '</td>';
            echo '</tr>';
        }
        ?>
    </table>

    <h2>Associated Estimates</h2>
    <ul>
        <?php if ($warehouse_estimate): ?>
            <li>Warehouse Estimate: $<?php echo number_format($warehouse_estimate['grand_total'], 2); ?></li>
        <?php endif; ?>
        <?php if ($freight_estimate): ?>
            <li>Freight Estimate: $<?php echo number_format($freight_estimate['grand_total'], 2); ?></li>
        <?php endif; ?>
    </ul>

    <h2>Total Estimated Logistic Cost: $<?php echo number_format($total_logistic_cost, 2); ?></h2>

    <!-- Visualize Data with Chart.js -->
    <canvas id="costChart" width="400" height="200"></canvas>
    <script>
        var ctx = document.getElementById('costChart').getContext('2d');
        var costChart = new Chart(ctx, {
            type: 'pie',
            data: {
                labels: ['Warehouse Cost', 'Freight Cost'],
                datasets: [{
                    data: [
                        <?php echo isset($warehouse_estimate['grand_total']) ? $warehouse_estimate['grand_total'] : 0; ?>,
                        <?php echo isset($freight_estimate['grand_total']) ? $freight_estimate['grand_total'] : 0; ?>
                    ],
                    backgroundColor: ['#36A2EB', '#FF6384']
                }]
            },
            options: {
                title: {
                    display: true,
                    text: 'Logistic Cost Breakdown'
                }
            }
        });
    </script>

    <!-- Option to Link to Actual Project -->
    <!-- This can be implemented as needed -->
</body>
</html>
