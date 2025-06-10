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
require_once '../config.php';
$conn = getDBConnection();
if (!$conn) {
    die("Connection failed");
}

// First get the user's account ID
$stmt = $conn->prepare("
    SELECT account_id 
    FROM customer_account_users 
    WHERE user_id = ?
");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$stmt->bind_result($account_id);
$stmt->fetch();
$stmt->close();

// Verify that the project belongs to the account
$stmt = $conn->prepare("
    SELECT p.project_name 
    FROM projects p 
    JOIN customer_accounts ca ON p.account_id = ca.id 
    WHERE p.id = ? AND ca.id = ?
");
$stmt->bind_param("ii", $project_id, $account_id);
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
    <style>
        .breadcrumb {
            display: flex;
            margin-bottom: 20px;
        }
        .breadcrumb a {
            color: #488C9A;
            text-decoration: none;
        }
        .breadcrumb .separator {
            margin: 0 8px;
            color: #6c757d;
        }
    </style>
</head>
<body>
<?php include 'header.php'; ?>
<main>
    <!-- Simple breadcrumb trail -->
    <div class="breadcrumb" style="margin: 10px 20px;">
        <a href="project_overview.php?project_id=<?php echo $project_id; ?>">Project Overview</a>
        <span class="separator">&raquo;</span>
        <span>Documents</span>
    </div>

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
