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
    die("You do not have access to this project or it does not exist.");
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Safe Harbor Evidence - <?php echo htmlspecialchars($project_name); ?></title>
    <link rel="stylesheet" href="portal.css">
    <link rel="icon" href="pictures/favicon.png" type="image/png">
    <link rel="shortcut icon" href="pictures/favicon.png" type="image/png">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Poppins', -apple-system, BlinkMacSystemFont, 'Segoe UI', system-ui, sans-serif;
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            min-height: 100vh;
            color: #2c3e50;
            line-height: 1.6;
        }
        .breadcrumb {
            background: rgba(255, 255, 255, 0.9);
            backdrop-filter: blur(10px);
            padding: 12px 20px;
            border-radius: 12px;
            margin: 10px 10px 20px 10px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
            display: flex;
            align-items: center;
        }
        .breadcrumb a { color: #488C9A; text-decoration: none; font-weight: 500; transition: all 0.2s ease; }
        .breadcrumb a:hover { color: #3a6e7f; text-decoration: underline; }
        .breadcrumb .separator { margin: 0 12px; color: #6c757d; font-weight: 600; }
        .page-header h1 {
            margin: 0 10px 12px 10px;
            background: linear-gradient(135deg, #293E4C 0%, #488C9A 100%);
            -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text;
        }
        .pods-container {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            border-radius: 24px;
            padding: 32px;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.1), 0 1px 3px rgba(0, 0, 0, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.2);
            margin: 0 10px 20px 10px;
        }
        .empty-state { text-align: center; padding: 60px 20px; color: #6c757d; }
        .empty-state-icon { font-size: 4rem; margin-bottom: 16px; opacity: 0.5; }
        .empty-state h3 { color: #495057; margin-bottom: 8px; font-weight: 600; }
        .empty-state p { font-size: 1.1rem; line-height: 1.6; }
        @media (max-width: 768px) {
            main { padding: 15px; }
            .pods-container { padding: 20px; border-radius: 16px; }
            .breadcrumb { padding: 10px 15px; margin: 10px; }
        }
    </style>
    </head>
<body>
<?php include 'header.php'; ?>
<main>
    <div class="breadcrumb">
        <a href="dashboard.php">Dashboard</a>
        <span class="separator">&raquo;</span>
        <a href="project_documents.php?project_id=<?php echo $project_id; ?>">Project Documents</a>
        <span class="separator">&raquo;</span>
        <span>Safe Harbor Evidence</span>
    </div>
    <div class="page-header">
        <h1>Safe Harbor Evidence for <?php echo htmlspecialchars($project_name); ?></h1>
    </div>

    <div class="pods-container">
        <div class="empty-state">
            <div class="empty-state-icon">📄</div>
            <h3>No Safe Harbor Evidence Found</h3>
            <p>Evidence files will appear here once uploaded for this project.</p>
        </div>
    </div>
</main>
</body>
</html>

<?php
$conn->close();
?>


