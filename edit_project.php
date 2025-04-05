<?php
session_name("logistics_session");
session_start();

// Ensure the user is a global_admin
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin','global_admin'])) {
    header("Location: unauthorized");
    exit();
}

require_once '../config.php';
$conn = getDBConnection();
if (!$conn) {
    die("Database connection failed.");
}

// Check if we have a project_id (for both GET and POST)
if (!isset($_REQUEST['project_id'])) {
    die("Project ID is missing.");
}
$project_id = intval($_REQUEST['project_id']);

// If POST, process form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Gather form fields
    $project_name             = trim($_POST['project_name'] ?? '');
    $project_address          = trim($_POST['project_address'] ?? '');
    $estimated_completion_date= trim($_POST['estimated_completion_date'] ?? '');
    $solterra_fee             = isset($_POST['solterra_fee']) ? floatval($_POST['solterra_fee']) : 0.0000;

    // Fetch existing image URL to see if we need to replace it
    $stmtOld = $conn->prepare("SELECT image_url FROM projects WHERE id = ?");
    $stmtOld->bind_param("i", $project_id);
    $stmtOld->execute();
    $stmtOld->bind_result($existing_image_url);
    $stmtOld->fetch();
    $stmtOld->close();

    // Handle new image file if uploaded
    $image_url = $existing_image_url; // default to existing
    if (isset($_FILES['image_file']) && $_FILES['image_file']['error'] !== UPLOAD_ERR_NO_FILE) {
        // Check for upload errors
        if ($_FILES['image_file']['error'] === UPLOAD_ERR_OK) {
            $allowed_ext = ['jpg','jpeg','png','gif'];
            $file_name   = $_FILES['image_file']['name'];
            $file_tmp    = $_FILES['image_file']['tmp_name'];
            $file_size   = $_FILES['image_file']['size'];
            $file_ext    = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));

            if (in_array($file_ext, $allowed_ext)) {
                if ($file_size <= 5*1024*1024) {
                    $unique_name = uniqid('project_', true).'.'.$file_ext;
                    $upload_dir  = 'uploads/';
                    if (!is_dir($upload_dir)) {
                        mkdir($upload_dir, 0755, true);
                    }
                    if (move_uploaded_file($file_tmp, $upload_dir.$unique_name)) {
                        $image_url = $upload_dir.$unique_name;
                        // Delete old image if it exists
                        if (!empty($existing_image_url) && file_exists($existing_image_url)) {
                            unlink($existing_image_url);
                        }
                    } else {
                        die("Error uploading the image file.");
                    }
                } else {
                    die("File size exceeds the maximum limit of 5MB.");
                }
            } else {
                die("Invalid file type. Only JPG, JPEG, PNG, and GIF files are allowed.");
            }
        } else {
            die("Error uploading the file. Error code: ".$_FILES['image_file']['error']);
        }
    }

    // Update the project (user_id removed; not needed anymore)
    $stmtUp = $conn->prepare("
        UPDATE projects
           SET project_name = ?,
               project_address = ?,
               estimated_completion_date = ?,
               image_url = ?,
               solterra_fee = ?
         WHERE id = ?
    ");
    $stmtUp->bind_param("ssssdi",
        $project_name,
        $project_address,
        $estimated_completion_date,
        $image_url,
        $solterra_fee,
        $project_id
    );
    if (!$stmtUp->execute()) {
        die("Error updating project: " . $stmtUp->error);
    }
    $stmtUp->close();

    // Handle wattage updates

    // 1) Remove wattages
    if (isset($_POST['remove_wattages'])) {
        foreach ($_POST['remove_wattages'] as $wid) {
            $wid = intval($wid);
            $stmtRm = $conn->prepare("DELETE FROM project_wattage_orders WHERE id=? AND project_id=?");
            $stmtRm->bind_param("ii", $wid, $project_id);
            if (!$stmtRm->execute()) {
                die("Error deleting wattage: " . $stmtRm->error);
            }
            $stmtRm->close();
        }
    }

    // 2) Update existing wattages
    if (isset($_POST['wattages'], $_POST['total_orders'])) {
        foreach ($_POST['wattages'] as $id => $w) {
            $id   = intval($id);
            $wat  = floatval($w);
            $ord  = isset($_POST['total_orders'][$id]) ? intval($_POST['total_orders'][$id]) : 0;
            $stmtWt = $conn->prepare("
                UPDATE project_wattage_orders
                   SET wattage = ?, total_order = ?
                 WHERE id = ? AND project_id=?
            ");
            $stmtWt->bind_param("diii", $wat, $ord, $id, $project_id);
            if (!$stmtWt->execute()) {
                die("Error updating wattage: " . $stmtWt->error);
            }
            $stmtWt->close();
        }
    }

    // 3) Add new wattages
    if (isset($_POST['new_wattages'], $_POST['new_total_orders'])) {
        $new_wattages     = $_POST['new_wattages'];
        $new_total_orders = $_POST['new_total_orders'];
        for ($i = 0; $i < count($new_wattages); $i++) {
            $nw = floatval($new_wattages[$i]);
            $nt = intval($new_total_orders[$i]);
            $stmtAdd = $conn->prepare("
                INSERT INTO project_wattage_orders (project_id, wattage, total_order)
                VALUES (?,?,?)
            ");
            $stmtAdd->bind_param("idi", $project_id, $nw, $nt);
            if (!$stmtAdd->execute()) {
                die("Error adding new wattage: " . $stmtAdd->error);
            }
            $stmtAdd->close();
        }
    }

    $conn->close();
    // Redirect or show success
    header("Location: admin_dashboard");
    exit();

} else {
    // GET request => show the form
    // 1) Fetch project
    $stmt = $conn->prepare("SELECT * FROM projects WHERE id=?");
    $stmt->bind_param("i", $project_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $stmt->close();

    if ($res->num_rows<1) {
        $conn->close();
        die("Project not found.");
    }
    $project = $res->fetch_assoc();

    // 2) Fetch wattage orders
    $stmtWo = $conn->prepare("SELECT * FROM project_wattage_orders WHERE project_id=?");
    $stmtWo->bind_param("i", $project_id);
    $stmtWo->execute();
    $wRes = $stmtWo->get_result();
    $stmtWo->close();

    $wattage_orders=[];
    while ($row=$wRes->fetch_assoc()) {
        $wattage_orders[]=$row;
    }
    $conn->close();
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <title>Edit Project</title>
        <link rel="stylesheet" href="portal.css">
        <link rel="icon" href="pictures/favicon.png" type="image/x-icon">
        <link href="https://fonts.googleapis.com/css?family=Poppins:300,400,500,600,700&display=swap" rel="stylesheet">
        <style>
            .wattage-entry {
                display: flex;
                flex-wrap: wrap;
                gap: 10px;
                margin-bottom: 10px;
                align-items: center;
            }
            .wattage-entry label {
                margin-top: 0;
            }
            .btn-add-wattage, .wattage-entry button {
                background: #488C9A;
                color: #fff;
                border: none;
                padding: 8px 14px;
                cursor: pointer;
                border-radius: 4px;
                margin-top: 10px;
            }
            .btn-add-wattage:hover, .wattage-entry button:hover {
                background: #293E4C;
            }
            .btn-submit {
                background: #293E4C;
                color: #fff;
                border: none;
                padding: 12px 20px;
                cursor: pointer;
                border-radius: 4px;
                font-size: 1rem;
                margin-top: 20px;
            }
            .btn-submit:hover {
                background: #488C9A;
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
                wattageInput.name = 'new_wattages[' + index + ']';
                wattageInput.required = true;

                var totalOrderLabel = document.createElement('label');
                totalOrderLabel.textContent = 'Total Order Quantity:';
                var totalOrderInput = document.createElement('input');
                totalOrderInput.type = 'number';
                totalOrderInput.name = 'new_total_orders[' + index + ']';
                totalOrderInput.required = true;

                var removeButton = document.createElement('button');
                removeButton.type = 'button';
                removeButton.textContent = 'Remove';
                removeButton.onclick = function() {
                    container.removeChild(div);
                };

                div.appendChild(wattageLabel);
                div.appendChild(wattageInput);
                div.appendChild(totalOrderLabel);
                div.appendChild(totalOrderInput);
                div.appendChild(removeButton);

                container.appendChild(div);
            }
            function removeExistingWattage(id) {
                var input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'remove_wattages[]';
                input.value = id;
                document.getElementById('edit-project-form').appendChild(input);
                document.getElementById('wattage-entry-' + id).remove();
            }
        </script>
    </head>
    <body>
    <?php include 'header.php'; ?>
    <main>
        <h1>Edit Project</h1>
        <form id="edit-project-form" action="edit_project.php" method="POST" enctype="multipart/form-data">
            <input type="hidden" name="project_id" value="<?php echo $project_id; ?>">

            <label for="project_name">Project Name:</label>
            <input type="text" name="project_name" value="<?php echo htmlspecialchars($project['project_name']); ?>" required>
            <br><br>

            <label for="project_address">Project Address:</label>
            <input type="text" name="project_address" value="<?php echo htmlspecialchars($project['project_address']); ?>" required>
            <br><br>

            <label for="image_file">Project Image:</label>
            <?php if (!empty($project['image_url'])): ?>
                <div>
                    <img src="<?php echo htmlspecialchars($project['image_url']); ?>" alt="Project Image" style="max-width: 200px;">
                </div>
            <?php endif; ?>
            <input type="file" name="image_file" accept="image/*">
            <br><br>

            <label for="estimated_completion_date">Estimated Completion Date:</label>
            <input type="date" name="estimated_completion_date" value="<?php echo htmlspecialchars($project['estimated_completion_date']); ?>">
            <br><br>

            <label for="solterra_fee">Solterra Fee (per watt):</label>
            <input type="number" step="0.0001" name="solterra_fee"
                   value="<?php echo isset($project['solterra_fee']) ? htmlspecialchars($project['solterra_fee']) : '0.0000'; ?>"
                   required
            >
            <br><br>

            <h2>Wattage and Total Order Quantities</h2>
            <div id="wattage-container">
                <?php foreach ($wattage_orders as $order): ?>
                    <div class="wattage-entry" id="wattage-entry-<?php echo $order['id']; ?>">
                        <label>Wattage:</label>
                        <input
                            type="number"
                            step="0.01"
                            name="wattages[<?php echo $order['id']; ?>]"
                            value="<?php echo htmlspecialchars($order['wattage']); ?>"
                            required
                        >
                        <label>Total Order Quantity:</label>
                        <input
                            type="number"
                            name="total_orders[<?php echo $order['id']; ?>]"
                            value="<?php echo htmlspecialchars($order['total_order']); ?>"
                            required
                        >
                        <button type="button" onclick="removeExistingWattage(<?php echo $order['id']; ?>)">Remove</button>
                    </div>
                <?php endforeach; ?>
            </div>
            <button type="button" class="btn-add-wattage" onclick="addWattageField()">Add Wattage</button>
            <br><br>

            <button type="submit" class="btn-submit">Update Project</button>
        </form>
        <br>
        <a href="admin_dashboard">Back to Admin Dashboard</a>
    </main>
    </body>
    </html>
    <?php
}
