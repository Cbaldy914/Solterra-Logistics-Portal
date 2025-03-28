<?php
session_name("logistics_session");
session_start();

// Check if the user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login");
    exit();
}

// Check for project_id parameter
if (!isset($_GET['project_id']) || empty($_GET['project_id'])) {
    die("Project ID is missing.");
}

$project_id = intval($_GET['project_id']);

// Database connection
require_once '../config.php';
$conn = getDBConnection();
if (!$conn) {
    die("Connection failed");
}

// Verify that the project belongs to the user (optional but recommended)
$user_id = $_SESSION['user_id'];
$stmt = $conn->prepare("SELECT project_name FROM projects WHERE id = ? AND user_id = ?");
$stmt->bind_param("ii", $project_id, $user_id);
$stmt->execute();
$stmt->bind_result($project_name);
$stmt->fetch();
$stmt->close();

if (!$project_name) {
    die("You do not have access to this project or it does not exist.");
}

// Fetch Flash Test Data for the project
$stmt = $conn->prepare("SELECT id, module_id, flash_date, flash_result FROM flash_test_data WHERE project_id = ?");
$stmt->bind_param("i", $project_id);
$stmt->execute();
$result = $stmt->get_result();
$stmt->close();

$flash_data = [];
while ($row = $result->fetch_assoc()) {
    $flash_data[] = $row;
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Flash Test Data</title>
    <link rel="stylesheet" href="portal.css">
    <link rel="icon" href="pictures/favicon.png" type="image/x-icon">
    <link href="https://fonts.googleapis.com/css?family=Poppins:300,400,500,600,700&display=swap" rel="stylesheet">
</head>
<body>
    <?php include 'header.php'; ?>
    <main>
    <a href="#" onclick="if(document.referrer) { window.location = document.referrer; } else { window.history.back(); }" class="back-icon">
        <!-- SVG for Back Arrow -->
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24">
            <path d="M10 19c-.39 0-.78-.15-1.06-.44L3.5 13.06a1.5 1.5 0 010-2.12l5.44-5.5a1.5 1.5 0 012.12 2.12L7.12 11H19a1.5 1.5 0 010 3H7.12l3.44 3.44a1.5 1.5 0 01-1.06 2.56z"/>
        </svg>
        Back
    </a>
        <h1>Flash Test Data</h1>
        <?php if (count($flash_data) > 0): ?>
            <table border="1" cellpadding="8" cellspacing="0">
                <tr>
                    <th>ID</th>
                    <th>Module ID</th>
                    <th>Flash Date</th>
                    <th>Flash Result</th>
                </tr>
                <?php foreach ($flash_data as $data): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($data['id']); ?></td>
                        <td><?php echo htmlspecialchars($data['module_id']); ?></td>
                        <td><?php echo htmlspecialchars($data['flash_date']); ?></td>
                        <td><?php echo htmlspecialchars($data['flash_result']); ?></td>
                    </tr>
                <?php endforeach; ?>
            </table>
        <?php else: ?>
            <p>No Flash Test Data available</p>
        <?php endif; ?>
    </main>
</body>
</html>
