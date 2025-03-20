<?php
session_name("logistics_session");
session_start();

// Check if user is logged in and is an admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'global_admin') {
    header("Location: unauthorized");
    exit();
}

$user_id = $_SESSION['user_id'];

// Database connection
$servername = "localhost";
$db_username = "SolterraSolutions";
$db_password = "CompanyAdmin!";
$dbname = "solterra_portal";

$conn = new mysqli($servername, $db_username, $db_password, $dbname);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Assign Warehouse to Projects</title>
    <link rel="stylesheet" href="portal.css">
    <link rel="icon" href="pictures/favicon.png" type="image/x-icon">
    <link href="https://fonts.googleapis.com/css?family=Poppins:300,400,500,600,700&display=swap" rel="stylesheet">
</head>
<body>
<?php include 'header.php'; ?>
<main>
    <h1>Assign Warehouse to Project</h1>
    <form action="assign_warehouse" method="post">
        <label for="project_id">Select Project:</label>
        <select name="project_id" required>
            <option value="">-- Select Project --</option>
            <?php
            // Fetch projects
            $stmt = $conn->prepare("SELECT id, project_name FROM projects");
            $stmt->execute();
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                echo "<option value='" . htmlspecialchars($row['id']) . "'>" . htmlspecialchars($row['project_name']) . "</option>";
            }
            $stmt->close();
            ?>
        </select><br><br>

        <label for="warehouse_id">Select Warehouse:</label>
        <select name="warehouse_id" required>
            <option value="">-- Select Warehouse --</option>
            <?php
            // Fetch warehouses
            $stmt = $conn->prepare("SELECT id, name FROM warehouses");
            $stmt->execute();
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                echo "<option value='" . htmlspecialchars($row['id']) . "'>" . htmlspecialchars($row['name']) . "</option>";
            }
            $stmt->close();
            ?>
        </select><br><br>

        <input type="submit" name="assign" value="Assign Warehouse">
    </form>

    <?php
    if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['assign'])) {
        // Retrieve and sanitize input
        $project_id = intval($_POST['project_id']);
        $warehouse_id = intval($_POST['warehouse_id']);

        // Validate inputs
        if ($project_id > 0 && $warehouse_id > 0) {
            // Update the project's warehouse_id
            $stmt = $conn->prepare("UPDATE projects SET warehouse_id = ? WHERE id = ?");
            $stmt->bind_param("ii", $warehouse_id, $project_id);
            if ($stmt->execute()) {
                echo "<p>Warehouse assigned to project successfully.</p>";
            } else {
                echo "<p>Error assigning warehouse: " . htmlspecialchars($stmt->error) . "</p>";
            }
            $stmt->close();
        } else {
            echo "<p>Invalid project or warehouse selection.</p>";
        }
    }

    $conn->close();
    ?>
</main>
</body>
</html>
