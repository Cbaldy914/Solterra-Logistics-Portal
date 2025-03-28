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

// Fetch PODs for the project
$stmt = $conn->prepare("
    SELECT d.id, d.proof_of_delivery
    FROM deliveries d
    WHERE d.project_id = ? AND d.proof_of_delivery IS NOT NULL
");
$stmt->bind_param("i", $project_id);
$stmt->execute();
$pods_result = $stmt->get_result();
$stmt->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PODs - <?php echo htmlspecialchars($project_name); ?></title>
    <link rel="stylesheet" href="portal.css">
    <link rel="icon" href="pictures/favicon.png" type="image/x-icon">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
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
<div class="pod_header">
    <h1>PODs for <?php echo htmlspecialchars($project_name); ?></h1>
</div>
    <?php if ($pods_result->num_rows > 0): ?>
        <form action="download_pods" method="post">
            <input type="hidden" name="project_id" value="<?php echo $project_id; ?>">
            <button type="submit" name="download_selected" onclick="return confirm('Download selected PODs?');">Download Selected</button>
            <table>
                <tr>
                    <th><input type="checkbox" id="select-all"></th>
                    <th>POD File</th>
                </tr>
                <?php while ($pod = $pods_result->fetch_assoc()): ?>
                    <tr>
                        <td>
                            <input type="checkbox" name="selected_pods[]" value="<?php echo $pod['id']; ?>">
                        </td>
                        <td>
                            <a href="view_pod?delivery_id=<?php echo $pod['id']; ?>" target="_blank">
                                <?php echo basename($pod['proof_of_delivery']); ?>
                            </a>
                        </td>
                    </tr>
                <?php endwhile; ?>
            </table>
        </form>
    <?php else: ?>
        <p>No PODs found for this project.</p>
    <?php endif; ?>
    </main>
    <script>
        // "Select All" functionality
        document.getElementById('select-all').onclick = function() {
            var checkboxes = document.getElementsByName('selected_pods[]');
            for (var checkbox of checkboxes) {
                checkbox.checked = this.checked;
            }
        };
    </script>
</body>
</html>

<?php
$conn->close();
?>
