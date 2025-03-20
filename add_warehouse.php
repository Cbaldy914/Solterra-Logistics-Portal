<?php
session_name("logistics_session");
session_start();

// Check if user is logged in and is an admin
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Warehouse</title>
    <link rel="stylesheet" href="portal.css">
    <link rel="icon" href="pictures/favicon.png" type="image/x-icon">
    <link href="https://fonts.googleapis.com/css?family=Poppins:300,400,500,600,700&display=swap" rel="stylesheet">
</head>
<body>
<?php include 'header.php'; ?>
<main>
    <h1>Add Warehouse</h1>
    <form action="add_warehouse" method="post" enctype="multipart/form-data">
        <label for="name">Warehouse Name:</label>
        <input type="text" name="name" required><br><br>

        <label for="address">Address:</label>
        <input type="text" name="address" required><br><br>

        <label for="image">Warehouse Image:</label>
        <input type="file" name="image"><br><br>

        <label for="in_fee">In Fee:</label>
        <input type="number" step="0.01" name="in_fee" required><br><br>

        <label for="out_fee">Out Fee:</label>
        <input type="number" step="0.01" name="out_fee" required><br><br>

        <label for="monthly_storage_fee">Monthly Storage Fee:</label>
        <input type="number" step="0.01" name="monthly_storage_fee" required><br><br>

        <input type="submit" name="submit" value="Add Warehouse">
    </form>

    <?php
    if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['submit'])) {
        // Retrieve form data and sanitize
        $name = trim($_POST['name']);
        $address = trim($_POST['address']);
        $in_fee = floatval($_POST['in_fee']);
        $out_fee = floatval($_POST['out_fee']);
        $monthly_storage_fee = floatval($_POST['monthly_storage_fee']);

        // Handle image upload if provided
        $image_url = '';
        if (isset($_FILES['image']) && $_FILES['image']['error'] == UPLOAD_ERR_OK) {
            // Ensure the uploads directory exists
            $target_dir = "uploads/warehouse_images/";
            if (!is_dir($target_dir)) {
                mkdir($target_dir, 0755, true);
            }

            // Sanitize the file name
            $image_name = basename($_FILES["image"]["name"]);
            $image_name = preg_replace("/[^a-zA-Z0-9\.\-_]/", "", $image_name);

            $target_file = $target_dir . $image_name;

            if (move_uploaded_file($_FILES["image"]["tmp_name"], $target_file)) {
                $image_url = $target_file; // Adjust based on your URL structure
            } else {
                echo "<p>Error uploading image.</p>";
            }
        }

        // Insert into the database
        $stmt = $conn->prepare("INSERT INTO warehouses (name, address, image_url, in_fee, out_fee, monthly_storage_fee) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("sssddd", $name, $address, $image_url, $in_fee, $out_fee, $monthly_storage_fee);
        if ($stmt->execute()) {
            echo "<p>Warehouse added successfully.</p>";
        } else {
            echo "<p>Error adding warehouse: " . htmlspecialchars($stmt->error) . "</p>";
        }
        $stmt->close();
    }

    $conn->close();
    ?>
</main>
</body>
</html>
