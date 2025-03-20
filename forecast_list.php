<?php
session_name("logistics_session");
session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: login");
    exit();
}

$user_id = $_SESSION['user_id'];

// Database connection
$conn = new mysqli($servername, $db_username, $db_password, $dbname);

// Fetch user's forecasted projects
$stmt = $conn->prepare("SELECT id, name, estimated_start_date, created_at FROM forecast_projects WHERE user_id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();

$forecasts = [];
while ($row = $result->fetch_assoc()) {
    $forecasts[] = $row;
}
$stmt->close();
$conn->close();
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forecasted Projects</title>
    <link rel="stylesheet" href="portal.css">
    <link rel="icon" href="pictures/favicon.png" type="image/x-icon">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
</head>
<body>
    <?php include 'header.php'; ?>
    <h1>Forecasted Projects</h1>
    <a href="create_forecast">Create New Forecast</a>
    <table>
        <tr>
            <th>Project Name</th>
            <th>Estimated Start Date</th>
            <th>Created At</th>
            <th>Actions</th>
        </tr>
        <?php foreach ($forecasts as $forecast): ?>
            <tr>
                <td><?php echo htmlspecialchars($forecast['name']); ?></td>
                <td><?php echo htmlspecialchars($forecast['estimated_start_date']); ?></td>
                <td><?php echo htmlspecialchars($forecast['created_at']); ?></td>
                <td>
                    <a href="view_forecast?id=<?php echo $forecast['id']; ?>">View</a>
                    |
                    <a href="associate_estimates?id=<?php echo $forecast['id']; ?>">Edit Estimates</a>
                    |
                    <a href="delete_forecast?id=<?php echo $forecast['id']; ?>">Delete</a>
                </td>
            </tr>
        <?php endforeach; ?>
    </table>
</body>
</html>
