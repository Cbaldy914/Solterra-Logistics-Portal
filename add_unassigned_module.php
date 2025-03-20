<?php
session_name("logistics_session");
session_start();

// Check if the user is an admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: unauthorized");
    exit();
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Unassigned Module</title>
    <link rel="stylesheet" href="portal.css">
</head>
<body>
<?php include 'header.php'; ?>
<main>
    <h1>Add Unassigned Module</h1>
    <form action="process_add_unassigned_module" method="POST">
        <label for="user_id">User ID:</label>
        <input type="number" name="user_id" required><br><br>

        <label for="vendor">Vendor:</label>
        <input type="text" name="vendor" required><br><br>

        <label for="wattage">Wattage:</label>
        <input type="number" name="wattage" required><br><br>

        <label for="quantity">Quantity:</label>
        <input type="number" name="quantity" required><br><br>

        <label for="current_location">Current Location:</label>
        <input type="text" name="current_location" required><br><br>

        <input type="submit" value="Add Unassigned Module">
    </form>
    <br>
    <a href="manage_unassigned_modules">Back to Unassigned Modules</a>
</main>
</body>
</html>
