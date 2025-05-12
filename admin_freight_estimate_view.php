<?php
session_name("logistics_session");
session_start();

// Check if the user is logged in and is an admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'global_admin') {
    header("Location: login");
    exit();
}

if (!isset($_GET['id'])) {
    die("Estimate ID not specified.");
}

$estimate_id = intval($_GET['id']);

// Database connection
require_once '../config.php';
$conn = getDBConnection();
if (!$conn) {
    die("Connection failed");
}

// Handle form submission for updating the estimate
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_estimate'])) {
    $cost_per_truck = floatval($_POST['cost_per_truck']);
    $total_accessorial_cost = floatval($_POST['total_accessorial_cost']);

    // Fetch existing estimate data
    $stmt = $conn->prepare("SELECT estimate_data FROM freight_estimates WHERE id = ?");
    $stmt->bind_param("i", $estimate_id);
    $stmt->execute();
    $stmt->bind_result($estimate_data_json);
    $stmt->fetch();
    $stmt->close();

    $estimate_data = json_decode($estimate_data_json, true);

    // Update estimate data with admin inputs
    $number_of_trucks = $estimate_data['estimated_number_of_trucks'];
    $total_freight_cost = $cost_per_truck * $number_of_trucks;
    $grand_total = $total_freight_cost + $total_accessorial_cost;

    $estimate_data['cost_per_truck'] = $cost_per_truck;
    $estimate_data['total_accessorial_cost'] = $total_accessorial_cost;
    $estimate_data['total_freight_cost'] = $total_freight_cost;
    $estimate_data['grand_total'] = $grand_total;

    // Save updated estimate data
    $estimate_data_json = json_encode($estimate_data);
    $stmt = $conn->prepare("UPDATE freight_estimates SET estimate_data = ? WHERE id = ?");
    $stmt->bind_param("si", $estimate_data_json, $estimate_id);

    if ($stmt->execute()) {
        $success_message = "Estimate updated successfully!";
    } else {
        $error_message = "Error updating estimate: " . $stmt->error;
    }
    $stmt->close();
}

// Fetch estimate data
$stmt = $conn->prepare("SELECT user_id, name, estimate_data, created_at FROM freight_estimates WHERE id = ?");
$stmt->bind_param("i", $estimate_id);
$stmt->execute();
$stmt->bind_result($user_id, $name, $estimate_data_json, $created_at);
$stmt->fetch();
$stmt->close();
$conn->close();

$estimate_data = json_decode($estimate_data_json, true);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin - Freight Estimate View</title>
    <link rel="stylesheet" href="portal.css">
    <link rel="icon" href="pictures/favicon.png" type="image/x-icon">
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css?family=Poppins:300,400,500,600,700&display=swap" rel="stylesheet">

    <style>
        /* Mimicking the style approach from admin_warehouse_estimate_view.php */
        label {
            display: block;
            margin-top: 15px;
            font-weight: bold;
        }
        input {
            width: 95%;
            padding: 8px;
            margin-top: 5px;
        }
        button {
            background-color: #488C9A; /* Secondary blue color */
            color: white;
            padding: 10px 20px;
            margin: 10px 0;
            border: none;
            border-radius: 4px;
            font-size: 1em;
            cursor: pointer;
            font-weight: bold;
        }
        button:hover {
            background-color: #293E4C; /* Darker shade on hover */
        }
        .success-message {
            color: green;
            margin-top: 15px;
        }
        .error-message {
            color: red;
            margin-top: 15px;
        }
        table {
            width: 100%;
            margin-top: 20px;
            border-collapse: collapse;
        }
        th, td {
            padding: 8px;
            text-align: left;
            border-bottom: 1px solid #ddd;
        }
        h1, h2 {
            margin-top: 20px;
        }
        ul {
            list-style-type: none;
            padding: 0;
            margin: 0;
        }
        ul li {
            margin: 5px 0;
        }
        ul li strong {
            display: inline-block;
            width: 220px; /* Adjust for alignment as needed */
        }
    </style>
</head>
<body>
<?php include 'header.php'; ?>
<main>
    <h1>Freight Estimate: <?php echo htmlspecialchars($name); ?></h1>

    <?php
    // Display success or error messages
    if (isset($success_message)) {
        echo '<p class="success-message">' . htmlspecialchars($success_message) . '</p>';
    }
    if (isset($error_message)) {
        echo '<p class="error-message">' . htmlspecialchars($error_message) . '</p>';
    }
    ?>

    <!-- Basic Info -->
    <ul>
        <li><strong>User ID:</strong> <?php echo htmlspecialchars($user_id); ?></li>
        <li><strong>Created At:</strong> <?php echo htmlspecialchars($created_at); ?></li>
    </ul>

    <h2>Estimate Details</h2>
    <ul>
        <li><strong>Origin:</strong> <?php echo htmlspecialchars($estimate_data['origin']); ?></li>
        <li><strong>Destination:</strong> <?php echo htmlspecialchars($estimate_data['destination']); ?></li>
        <li><strong>Distance:</strong> <?php echo htmlspecialchars($estimate_data['distance']); ?> miles</li>
        <li><strong>Project Size:</strong> <?php echo htmlspecialchars($estimate_data['project_size']); ?> MW</li>
        <li><strong>Estimated Start Date:</strong> <?php echo htmlspecialchars($estimate_data['estimated_start_date']); ?></li>
        <li><strong>Estimated Number of Trucks:</strong> <?php echo htmlspecialchars($estimate_data['estimated_number_of_trucks']); ?></li>
        <li><strong>Estimated Modules Per Truck:</strong> <?php echo htmlspecialchars($estimate_data['estimated_modules_per_truck']); ?></li>
    </ul>

    <!-- Admin input for costs -->
    <h2>Update Costs</h2>
    <form method="POST" action="">
        <input type="hidden" name="update_estimate" value="1">

        <label for="cost_per_truck">Cost per Truck:</label>
        <input type="number" name="cost_per_truck" step="0.01" required
               value="<?php echo htmlspecialchars($estimate_data['cost_per_truck']); ?>">

        <label for="total_accessorial_cost">Total Accessorial Cost:</label>
        <input type="number" name="total_accessorial_cost" step="0.01" required
               value="<?php echo htmlspecialchars($estimate_data['total_accessorial_cost']); ?>">

        <button type="submit">Update Estimate</button>
    </form>

    <!-- Display updated totals -->
    <?php if (!empty($estimate_data['grand_total'])): ?>
        <h2>Updated Totals</h2>
        <table>
            <tr>
                <th>Total Freight Cost</th>
                <td>$<?php echo number_format($estimate_data['total_freight_cost'], 2); ?></td>
            </tr>
            <tr>
                <th>Total Accessorial Cost</th>
                <td>$<?php echo number_format($estimate_data['total_accessorial_cost'], 2); ?></td>
            </tr>
            <tr>
                <th>Grand Total</th>
                <td>$<?php echo number_format($estimate_data['grand_total'], 2); ?></td>
            </tr>
        </table>
    <?php endif; ?>
</main>
</body>
</html>
