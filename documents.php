<?php
session_name("logistics_session");
session_start();

// Check if the user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login");
    exit();
}


// Get the user's ID
$user_id = $_SESSION['user_id'];

// Database connection
require_once '../config.php';
$conn = getDBConnection();
if (!$conn) {
    die("Connection failed");
}

// Fetch projects associated with the user
$stmt = $conn->prepare("SELECT id, project_name FROM projects WHERE user_id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$projects_result = $stmt->get_result();
$stmt->close();

// Fetch projects into an array for easier manipulation
$projects = [];
while ($project = $projects_result->fetch_assoc()) {
    $projects[] = $project;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Documents</title>
    <link rel="stylesheet" href="portal.css">
    <link rel="icon" href="pictures/favicon.png" type="image/x-icon">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
</head>
<body>
<?php include 'header.php'; ?>
<main>
    <h1>Documents</h1>
    <!-- Filter Input -->
    <input type="text" id="projectFilter" class="filter-input" placeholder="Search Projects...">
    <!-- Projects List -->
    <ul class="folder-list" id="projectList">
        <?php if (count($projects) > 0): ?>
            <?php foreach ($projects as $project): ?>
                <li>
                    <img src="pictures/folder_icon.jpg" alt="Folder Icon" width="32" height="32">
                    <a href="project_documents?project_id=<?php echo $project['id']; ?>">
                        <?php echo htmlspecialchars($project['project_name']); ?>
                    </a>
                </li>
            <?php endforeach; ?>
        <?php else: ?>
            <li>No projects found.</li>
        <?php endif; ?>
    </ul>
</main>

<!-- Include JavaScript for filtering -->
<script>
    document.getElementById('projectFilter').addEventListener('input', function() {
        var filter = this.value.toLowerCase();
        var listItems = document.querySelectorAll('#projectList li');

        listItems.forEach(function(item) {
            var text = item.textContent.toLowerCase();
            if (text.includes(filter)) {
                item.style.display = '';
            } else {
                item.style.display = 'none';
            }
        });
    });
</script>
</body>
</html>

<?php
$conn->close();
?>
