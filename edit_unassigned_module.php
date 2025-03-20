<?php
session_name("logistics_session");
session_start();

// Check if the user is an admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: unauthorized");
    exit();
}

// Get the module ID from the URL
if (!isset($_GET['id']) || empty($_GET['id'])) {
    die("Module ID is missing.");
}

$module_id = intval($_GET['id']);

// Database connection parameters
$servername = "localhost";
$db_username = "SolterraSolutions"; // Replace with your actual database username
$db_password = "CompanyAdmin!";     // Replace with your actual database password
$dbname = "solterra_portal";        // Replace with your actual database name

// Create a new database connection
$conn = new mysqli($servername, $db_username, $db_password, $dbname);

// Check the connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Fetch the module data
$stmt = $conn->prepare("SELECT * FROM unassigned_modules WHERE id = ?");
$stmt->bind_param("i", $module_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    die("Module not found.");
}

$module = $result->fetch_assoc();

$stmt->close();
$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Edit Unassigned Module</title>
    <link rel="stylesheet" href="portal.css">
</head>
<body>
<?php include 'header.php'; ?>
<main>
    <h1>Edit Unassigned Module</h1>
    <form action="process_edit_unassigned_module" method="POST">
        <input type="hidden" name="module_id" value="<?php echo htmlspecialchars($module['id']); ?>">

        <label for="user_id">User ID:</label>
        <input type="number" name="user_id" value="<?php echo htmlspecialchars($module['user_id']); ?>" required><br><br>

        <label for="vendor">Vendor:</label>
        <input type="text" name="vendor" value="<?php echo htmlspecialchars($module['vendor']); ?>" required><br><br>

        <label for="wattage">Wattage:</label>
        <input type="number" name="wattage" value="<?php echo htmlspecialchars($module['wattage']); ?>" required><br><br>

        <label for="quantity">Quantity:</label>
        <input type="number" name="quantity" value="<?php echo htmlspecialchars($module['quantity']); ?>" required><br><br>

        <label for="current_location">Current Location:</label>
        <input type="text" name="current_location" value="<?php echo htmlspecialchars($module['current_location']); ?>" required><br><br>

        <input type="submit" value="Update Unassigned Module">
    </form>
    <br>
    <a href="manage_unassigned_modules">Back to Unassigned Modules</a>
</main>
</body>
</html>
