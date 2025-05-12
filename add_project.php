<?php
/***********************
 * Combined add_project.php
 * (No user_id insertion)
 ***********************/

session_name("logistics_session");
session_start();


// 2) Ensure user has role admin or global_admin
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin','global_admin'])) {
    die("Unauthorized: You must be 'admin' or 'global_admin' to add projects.");
}

// Database connection
require_once '../config.php';
$conn = getDBConnection();
if (!$conn) {
    die("Database connection failed.");
}

$role    = $_SESSION['role'];
$user_id = $_SESSION['user_id'];

// We'll find the admin's single account or list of accounts for a dropdown
$account_id_for_admin = null;
$accounts             = [];

// If global_admin, load all accounts for a dropdown
if ($role === 'global_admin') {
    $sqlAll = "SELECT id, name FROM customer_accounts ORDER BY name ASC";
    $resAll = $conn->query($sqlAll);
    if ($resAll && $resAll->num_rows > 0) {
        while ($row = $resAll->fetch_assoc()) {
            $accounts[] = $row;
        }
    }
} else {
    // If admin, fetch exactly one account_id from bridging table
    $sqlOne = "
        SELECT account_id
        FROM customer_account_users
        WHERE user_id = ?
          AND role = 'admin'
        LIMIT 1
    ";
    $stmtOne = $conn->prepare($sqlOne);
    if (!$stmtOne) {
        die("Error preparing account lookup: " . $conn->error);
    }
    $stmtOne->bind_param("i", $user_id);
    $stmtOne->execute();
    $stmtOne->bind_result($acctID);
    if ($stmtOne->fetch()) {
        $account_id_for_admin = $acctID;
    }
    $stmtOne->close();

    if (!$account_id_for_admin) {
        die("No valid account found for this admin user.");
    }
}

// Prepare variables to hold user messages:
$successMessage = "";
$errorMessage   = "";

// If POST, handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Determine correct account_id
        if ($role === 'global_admin') {
            $account_id = isset($_POST['account_id']) ? intval($_POST['account_id']) : 0;
            if ($account_id <= 0) {
                throw new Exception("Please select a valid Account.");
            }
        } else {
            // Admin => single known account
            $account_id = $account_id_for_admin;
        }

        // Gather fields
        $project_name              = trim($_POST['project_name'] ?? '');
        $project_address           = trim($_POST['project_address'] ?? '');
        $estimated_completion_date = trim($_POST['estimated_completion_date'] ?? '');
        $solterra_fee              = isset($_POST['solterra_fee']) ? floatval($_POST['solterra_fee']) : 0.0000;

        if ($project_name === '' || $project_address === '') {
            throw new Exception("Project Name and Address are required.");
        }

        // Optional image (default to pictures/test.png if none uploaded)
        $image_url = "pictures/test.png"; // default
        if (isset($_FILES['image_file']) && $_FILES['image_file']['error'] !== UPLOAD_ERR_NO_FILE) {
            if ($_FILES['image_file']['error'] === UPLOAD_ERR_OK) {
                $allowed_ext = ['jpg','jpeg','png','gif'];
                $file_name   = $_FILES['image_file']['name'];
                $file_tmp    = $_FILES['image_file']['tmp_name'];
                $file_size   = $_FILES['image_file']['size'];
                $file_ext    = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));

                if (!in_array($file_ext, $allowed_ext)) {
                    throw new Exception("Invalid file type. Only JPG, JPEG, PNG, GIF allowed.");
                }
                if ($file_size > 5*1024*1024) {
                    throw new Exception("File exceeds 5MB limit.");
                }
                $unique_name = uniqid('project_', true).'.'.$file_ext;
                $upload_dir  = 'uploads/';
                if (!is_dir($upload_dir)) {
                    mkdir($upload_dir, 0755, true);
                }
                if (!move_uploaded_file($file_tmp, $upload_dir.$unique_name)) {
                    throw new Exception("Error uploading the image file.");
                }
                $image_url = $upload_dir.$unique_name;
            } else {
                throw new Exception("File upload error code: " . $_FILES['image_file']['error']);
            }
        }

        // Insert into projects
        $stmt = $conn->prepare("
            INSERT INTO projects (
                account_id,
                project_name,
                project_address,
                estimated_completion_date,
                image_url,
                solterra_fee
            ) VALUES (?, ?, ?, ?, ?, ?)
        ");
        if (!$stmt) {
            throw new Exception("Error preparing project insert: " . $conn->error);
        }
        $stmt->bind_param(
            "issssd",
            $account_id,
            $project_name,
            $project_address,
            $estimated_completion_date,
            $image_url,
            $solterra_fee
        );
        if (!$stmt->execute()) {
            throw new Exception("Error inserting project: " . $stmt->error);
        }
        $project_id = $stmt->insert_id;
        $stmt->close();

        // Insert wattage+total_orders if provided
        if (isset($_POST['wattages'], $_POST['total_orders'])) {
            $wattages     = $_POST['wattages'];
            $total_orders = $_POST['total_orders'];

            if (count($wattages) !== count($total_orders)) {
                throw new Exception("Mismatch between wattage[] and total_orders[].");
            }
            for ($i=0; $i<count($wattages); $i++) {
                $w = floatval($wattages[$i]);
                $t = floatval($total_orders[$i]);
                if ($w <= 0 || $t <= 0) {
                    throw new Exception("Wattage and total_order must be > 0.");
                }
                $stmt2 = $conn->prepare("
                    INSERT INTO project_wattage_orders (project_id, wattage, total_order)
                    VALUES (?, ?, ?)
                ");
                if (!$stmt2) {
                    throw new Exception("Error preparing wattage insert: " . $conn->error);
                }
                $stmt2->bind_param("idi", $project_id, $w, $t);
                if (!$stmt2->execute()) {
                    throw new Exception("Error inserting wattage: " . $stmt2->error);
                }
                $stmt2->close();
            }
        }

        // Set a success message to be displayed with the form below
        $successMessage = "Project added successfully!";
    } catch (Exception $ex) {
        // Set the error message to be displayed with the form below
        $errorMessage = $ex->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Project</title>
    <link rel="stylesheet" href="portal.css">
    <link rel="icon" href="pictures/favicon.png" type="image/x-icon">
    <link href="https://fonts.googleapis.com/css?family=Poppins:300,400,500,600,700&display=swap" rel="stylesheet">
    <style>
        /* Styles adapted from add_warehouse.php (and originally add_project.php) */
        main {
            max-width: 800px; /* Adjust as needed */
            margin: 20px auto; /* Center main content */
            padding: 20px;
            background-color: #fff;
            box-shadow: 0 0 10px rgba(0,0,0,0.1);
        }
        form label {
            display: block;
            margin-top: 15px;
            font-weight: 600;
            color: #333; /* Darker label text */
        }
        form input[type="text"],
        form input[type="number"],
        form input[type="date"],
        form input[type="file"],
        form select {
            width: 100%;
            padding: 10px; /* Slightly larger padding */
            margin-top: 5px;
            border-radius: 4px;
            border: 1px solid #ccc;
            box-sizing: border-box; /* Include padding and border in the element's total width and height */
        }
        /* Keep specific wattage entry styles */
        .wattage-entry {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-bottom: 10px;
            padding: 10px;
            border: 1px dashed #ccc;
            border-radius: 4px;
        }
        .wattage-entry label {
            margin-top: 0; /* Override general label margin */
            flex-basis: 100px; /* Adjust label width as needed */
        }
        .wattage-entry input[type="number"] {
            flex-grow: 1; /* Allow inputs to take available space */
            width: auto; /* Override 100% width */
        }
        .wattage-entry button {
            background: #dc3545; /* Red for remove */
            color: #fff;
            border: none;
            padding: 8px 14px;
            cursor: pointer;
            border-radius: 4px;
            margin-top: 5px; /* Align with inputs */
            align-self: flex-end; /* Align button bottom */
        }
        .wattage-entry button:hover {
            background: #c82333;
        }
        /* Styling for the 'Add Wattage' button */
         .btn-add-wattage {
            background: #488C9A;
            color: #fff;
            border: none;
            padding: 8px 14px;
            cursor: pointer;
            border-radius: 4px;
            margin-top: 10px;
            margin-bottom: 20px; /* Space before submit */
            display: inline-block;
        }
        .btn-add-wattage:hover {
            background: #293E4C;
        }
        /* General submit button style */
        .btn-submit { 
            background: #293E4C; /* Dark blue */
            color: #fff;
            border: none;
            padding: 12px 20px;
            cursor: pointer;
            border-radius: 4px;
            font-size: 1rem;
            font-weight: 600; /* Make button text bolder */
            margin-top: 20px;
            display: inline-block; /* Or block if you want it full width */
            transition: background-color 0.3s ease; /* Smooth transition on hover */
        }
        .btn-submit:hover {
            background: #488C9A; /* Lighter blue on hover */
        }
        h1 {
            color: #293E4C; /* Match button color */
            text-align: center; /* Center the heading */
            margin-bottom: 30px;
        }
        .section-title {
            margin-top: 30px;
            margin-bottom: 15px; /* Increased bottom margin */
            font-size: 1.2rem; /* Slightly larger title */
            font-weight: 600;
            color: #333;
            border-bottom: 1px solid #eee; /* Add a light separator */
            padding-bottom: 5px;
        }
        /* Message styling */
        .success-message {
            color: #155724; /* Dark green */
            background-color: #d4edda; /* Light green background */
            border: 1px solid #c3e6cb; /* Green border */
            padding: 15px;
            margin: 20px 0; /* Add margin */
            text-align: center;
            border-radius: 4px;
        }
        .error-message {
            color: #721c24; /* Dark red */
            background-color: #f8d7da; /* Light red background */
            border: 1px solid #f5c6cb; /* Red border */
            padding: 15px;
            margin: 20px 0; /* Add margin */
            text-align: center;
            border-radius: 4px;
        }
        /* Remove default br spacing if labels are block */
        form br {
            display: none;
        }
    </style>
    <script>
        function addWattageField() {
            var container = document.getElementById('wattage-container');
            var index = container.children.length;

            var div = document.createElement('div');
            div.className = 'wattage-entry';

            var wattageLabel = document.createElement('label');
            wattageLabel.textContent = 'Wattage:';
            var wattageInput = document.createElement('input');
            wattageInput.type = 'number';
            wattageInput.step = '0.01';
            wattageInput.name = 'wattages[' + index + ']';
            wattageInput.required = true;

            var totalOrderLabel = document.createElement('label');
            totalOrderLabel.textContent = 'Total Order Quantity:';
            var totalOrderInput = document.createElement('input');
            totalOrderInput.type = 'number';
            totalOrderInput.name = 'total_orders[' + index + ']';
            totalOrderInput.required = true;

            var removeButton = document.createElement('button');
            removeButton.type = 'button';
            removeButton.textContent = 'Remove';
            removeButton.onclick = function() {
                container.removeChild(div);
            };
            removeButton.style.marginTop = '0';

            div.appendChild(wattageLabel);
            div.appendChild(wattageInput);
            div.appendChild(totalOrderLabel);
            div.appendChild(totalOrderInput);
            div.appendChild(removeButton);

            container.appendChild(div);
        }
    </script>
</head>
<body>
<?php include 'header.php'; ?>
<main>
    <h1>Add Project</h1>

    <!-- Display success or error messages if any -->
    <?php if (!empty($successMessage)): ?>
        <div class="success-message"><strong><?php echo htmlspecialchars($successMessage); ?></strong></div>
    <?php endif; ?>

    <?php if (!empty($errorMessage)): ?>
        <div class="error-message"><strong>Error:</strong> <?php echo htmlspecialchars($errorMessage); ?></div>
    <?php endif; ?>

    <!-- The project form -->
    <form action="" method="POST" enctype="multipart/form-data">
        <?php if ($role === 'global_admin'): ?>
            <label for="account_id">Account Name:</label>
            <select name="account_id" id="account_id" required>
                <option value="">--Select Account--</option>
                <?php foreach ($accounts as $acc): ?>
                    <option value="<?php echo $acc['id']; ?>">
                        <?php echo htmlspecialchars($acc['name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        <?php else: ?>
            <input type="hidden" name="account_id" value="<?php echo $account_id_for_admin; ?>">
        <?php endif; ?>

        <label for="project_name">Project Name:</label>
        <input type="text" id="project_name" name="project_name" required>

        <label for="project_address">Project Address:</label>
        <input type="text" id="project_address" name="project_address" required>

        <label for="image_file">Project Image:</label>
        <input type="file" id="image_file" name="image_file" accept="image/*">

        <label for="estimated_completion_date">Estimated Completion Date:</label>
        <input type="date" id="estimated_completion_date" name="estimated_completion_date">

        <label for="solterra_fee">Solterra Fee (per watt):</label>
        <input type="number" id="solterra_fee" step="0.0001" name="solterra_fee" value="0.0000" required>

        <div class="section-title">Wattage and Total Order Quantities</div>
        <div id="wattage-container">
            <!-- Wattage fields will be added here by JS -->
        </div>
        <button type="button" class="btn-add-wattage" onclick="addWattageField()">Add Wattage</button>

        <div class="btn-submit-container">
            <input type="submit" value="Add Project" class="btn-submit">
        </div>
    </form>
</main>
</body>
</html>
