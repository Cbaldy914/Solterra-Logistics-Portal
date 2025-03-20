<?php
session_name("logistics_session");
session_start();

// Check if the user is a global admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'global_admin') {
    header("Location: unauthorized");
    exit();
}

// Database connection
$servername = "localhost";
$db_username = "SolterraSolutions";
$db_password = "CompanyAdmin!";
$dbname = "solterra_portal";

$conn = new mysqli($servername, $db_username, $db_password, $dbname);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Handle form submission for linking
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['project_id'], $_POST['site_id'])) {
    $project_id = (int)$_POST['project_id'];
    $site_id = (int)$_POST['site_id'];
    
    // Update the site to link to the chosen project
    $update_sql = "UPDATE sites SET project_id = ? WHERE id = ?";
    $stmt = $conn->prepare($update_sql);
    $stmt->bind_param('ii', $project_id, $site_id);
    $stmt->execute();
    
    // Optional: check for success or errors
    if ($stmt->affected_rows > 0) {
        $message = "Site linked to project successfully.";
    } else {
        $message = "No changes made or an error occurred.";
    }
    $stmt->close();
}

// Fetch all projects
$project_sql = "SELECT id, project_name FROM projects ORDER BY project_name ASC";
$project_result = $conn->query($project_sql);
$projects = [];
if ($project_result->num_rows > 0) {
    while($row = $project_result->fetch_assoc()) {
        $projects[] = $row;
    }
}

// Fetch all sites with joined project info
// (LEFT JOIN so we can see sites that might not yet have a project linked)
$site_sql = "
SELECT s.id AS site_id, 
       s.project_id, 
       s.project_name AS site_title, 
       s.city, 
       s.state,
       p.project_name
FROM sites s
LEFT JOIN projects p ON s.project_id = p.id
ORDER BY s.id ASC
";
$site_result = $conn->query($site_sql);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Link Projects and Sites</title>
    <link rel="stylesheet" href="portal.css">
    <link rel="icon" href="pictures/favicon.png" type="image/x-icon">
    <link href="https://fonts.googleapis.com/css?family=Poppins:300,400,500,600,700&display=swap" rel="stylesheet">
    <style>
        .action-button {
            background-color: #488C9A;
            color: #fff;
            text-decoration: none;
            border-radius: 5px;
            padding: 7px 15px;
            margin: 5px;
            font-weight: bold;
            cursor: pointer;
        }
        
        /* Simple styling for notification message */
        .message {
            color: #006600;
            font-weight: bold;
            margin-bottom: 10px;
        }
    </style>
</head>
<body>
<?php include 'header.php'; ?>
<main>
    <h1>Link Projects and Sites</h1>
    
    <?php if (!empty($message)): ?>
        <div class="message">
            <?php echo htmlspecialchars($message); ?>
        </div>
    <?php endif; ?>
    
    <!-- Form to link a site to a project -->
    <form action="project_site.php" method="POST" style="margin-bottom: 20px;">
        <label for="project_id">Project:</label>
        <select name="project_id" id="project_id" required>
            <option value="">-- Select a Project --</option>
            <?php foreach ($projects as $proj): ?>
                <option value="<?php echo $proj['id']; ?>">
                    <?php echo htmlspecialchars($proj['project_name']); ?>
                </option>
            <?php endforeach; ?>
        </select>
        
        <label for="site_id">Site:</label>
        <select name="site_id" id="site_id" required>
            <option value="">-- Select a Site --</option>
            <?php 
            // For flexibility, you might only show sites that are NOT linked yet, 
            // or show all sites to allow re-linking. 
            // For this example, we show all. 
            
            // Re-run a quick query for the dropdown or reuse the data from $site_result
            $site_result->data_seek(0); // Reset pointer if we want to reuse the result
            while($site = $site_result->fetch_assoc()): 
            ?>
                <option value="<?php echo $site['site_id']; ?>">
                    <?php 
                    // We can show city, state, or any label that helps identify the site
                    echo "Site #" . $site['site_id'] . " - " . 
                         htmlspecialchars($site['city'] . ", " . $site['state']);
                    ?>
                </option>
            <?php endwhile; ?>
        </select>
        
        <button type="submit" class="action-button">Link Site to Project</button>
    </form>
    
    <!-- Show a table of all sites and their currently linked project -->
    <?php 
    // We need to re-fetch the sites with a fresh query, 
    // because we used $site_result inside the dropdown above
    $site_result->close();
    $site_result = $conn->query($site_sql);
    ?>
    
    <table border="1" cellpadding="10" cellspacing="0">
        <tr>
            <th>Site ID</th>
            <th>Site Location</th>
            <th>Linked Project</th>
        </tr>
        <?php if ($site_result->num_rows > 0): ?>
            <?php while($site = $site_result->fetch_assoc()): ?>
                <tr>
                    <td><?php echo $site['site_id']; ?></td>
                    <td>
                        <?php 
                            // Display any site details you wish (city, state, etc.)
                            echo htmlspecialchars($site['city'] . ", " . $site['state']); 
                        ?>
                    </td>
                    <td>
                        <?php 
                        if ($site['project_id']) {
                            echo htmlspecialchars($site['project_name']);
                        } else {
                            echo "<em>Not linked</em>";
                        }
                        ?>
                    </td>
                </tr>
            <?php endwhile; ?>
        <?php else: ?>
            <tr>
                <td colspan="3">No sites found.</td>
            </tr>
        <?php endif; ?>
    </table>
    
</main>
</body>
</html>

<?php
$site_result->close();
$conn->close();
?>
