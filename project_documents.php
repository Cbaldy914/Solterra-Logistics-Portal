<?php
session_name("logistics_session");
session_start();

// Check if the user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login");
    exit();
}


if (!isset($_GET['project_id']) || empty($_GET['project_id'])) {
    die("Project ID is missing.");
}

$project_id = intval($_GET['project_id']);
$user_id = $_SESSION['user_id'];

// Database connection
$servername = "localhost";
$db_username = "SolterraSolutions"; // Replace with your actual database username
$db_password = "CompanyAdmin!"; // Replace with your actual database password
$dbname = "solterra_portal";

$conn = new mysqli($servername, $db_username, $db_password, $dbname);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Verify that the project belongs to the user
$stmt = $conn->prepare("SELECT project_name FROM projects WHERE id = ? AND user_id = ?");
$stmt->bind_param("ii", $project_id, $user_id);
$stmt->execute();
$stmt->bind_result($project_name);
$stmt->fetch();
$stmt->close();

if (!$project_name) {
    die("You do not have access to this project.");
}

// Define the folders
$folders = [
    ['name' => 'Invoices', 'link' => 'invoices?project_id=' . $project_id],
    ['name' => 'PODs', 'link' => 'pods?project_id=' . $project_id],
    ['name' => 'Flash Test Data', 'link' => 'ftd?project_id=' . $project_id],
    // Add more folders if needed
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Documents - <?php echo htmlspecialchars($project_name); ?></title>
    <link rel="stylesheet" href="portal.css">
    <link rel="icon" href="pictures/favicon.png" type="image/x-icon">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
</head>
<body>
<?php include 'header.php'; ?>
<main>
<a href="#" onclick="if(document.referrer) { window.location = document.referrer; } else { window.history.back(); }" class="back-icon" style="margin:20px;">
    <!-- SVG for Back Arrow -->
    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24">
        <path d="M10 19c-.39 0-.78-.15-1.06-.44L3.5 13.06a1.5 1.5 0 010-2.12l5.44-5.5a1.5 1.5 0 012.12 2.12L7.12 11H19a1.5 1.5 0 010 3H7.12l3.44 3.44a1.5 1.5 0 01-1.06 2.56z"/>
    </svg>
    Back
</a>
    <h1>Documents for <?php echo htmlspecialchars($project_name); ?></h1>
    <!-- Filter Input -->
    <input type="text" id="folderFilter" class="filter-input" placeholder="Search Folders...">
    <!-- Folders List -->
    <ul class="folder-list" id="folderList">
        <?php foreach ($folders as $folder): ?>
            <li>
                <img src="pictures/folder_icon.jpg" alt="Folder Icon" width="32" height="32">
                <a href="<?php echo $folder['link']; ?>">
                    <?php echo htmlspecialchars($folder['name']); ?>
                </a>
            </li>
        <?php endforeach; ?>
    </ul>
</main>

<!-- Include JavaScript for filtering -->
<script>
    document.getElementById('folderFilter').addEventListener('input', function() {
        var filter = this.value.toLowerCase();
        var listItems = document.querySelectorAll('#folderList li');

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
