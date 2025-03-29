<?php
session_name("logistics_session");
session_start();

// Check if the user is logged in and is an admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'global_admin') {
    header("Location: login");
    exit();
}

// Database connection
require_once '../config.php';
$conn = getDBConnection();
if (!$conn) {
    die("Connection failed");
}

// Handle form submission for updating estimates
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_estimate'])) {
    $estimate_id = intval($_POST['estimate_id']);
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

// Fetch all estimates
$stmt = $conn->prepare("SELECT id, user_id, name, estimate_data, created_at FROM freight_estimates ORDER BY created_at DESC");
$stmt->execute();
$result = $stmt->get_result();
$estimates = [];
while ($row = $result->fetch_assoc()) {
    $estimates[] = $row;
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin- Freight Estimate</title>
    <link rel="stylesheet" href="portal.css">
    <link rel="icon" href="pictures/favicon.png" type="image/x-icon">
    <link href="https://fonts.googleapis.com/css?family=Poppins:300,400,500,600,700&display=swap" rel="stylesheet">
</head>
<body>
<?php include 'header.php'; ?>
    <main>
        <h1>Freight Estimates</h1>

        <?php
        // Display success or error messages
        if (isset($success_message)) {
            echo '<p class="success-message">' . htmlspecialchars($success_message) . '</p>';
        }
        if (isset($error_message)) {
            echo '<p class="error-message">' . htmlspecialchars($error_message) . '</p>';
        }
        ?>

        <table>
            <tr>
                <th>Name</th>
                <th>User ID</th>
                <th>Created At</th>
                <th>Actions</th>
            </tr>
            <?php foreach ($estimates as $estimate): ?>
                <tr>
                    <td><?php echo htmlspecialchars($estimate['name']); ?></td>
                    <td><?php echo htmlspecialchars($estimate['user_id']); ?></td>
                    <td><?php echo htmlspecialchars($estimate['created_at']); ?></td>
                    <td>
                        <a href="admin_freight_estimate_view?id=<?php echo $estimate['id']; ?>">View / Edit</a>
                    </td>
                </tr>
            <?php endforeach; ?>
        </table>
    </main>
</body>
</html>
