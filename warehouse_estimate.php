<?php
// warehouse_estimate
session_name("logistics_session");
session_start();

// Replace with your administrator's email address
$to_email = 'cbaldy@solterrasol.com';

// Check if the user is logged in
if (!isset($_SESSION['user_id'])) {
    // redirect to login page
    header("Location: login");
    exit();
}

// Get user role
$user_role = isset($_SESSION['role']) ? $_SESSION['role'] : '';

// Get user ID
$user_id = $_SESSION['user_id'];

$servername = "localhost";
$db_username = "SolterraSolutions";
$db_password = "CompanyAdmin!";
$dbname = "solterra_portal";

$conn = new mysqli($servername, $db_username, $db_password, $dbname);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Initialize variables
$estimate_name = '';
$project_location = '';
$estimated_storage_start = '';
$estimated_number_of_pallets = '';
$pallet_length = '';
$pallet_width = '';
$pallet_height = '';
$stackable = false;
$square_feet = '';
$additional_documentation = '';

$success_message = '';
$error_message = '';

// Handle estimate deletion
if (isset($_GET['delete_estimate'])) {
    $estimate_id_to_delete = intval($_GET['delete_estimate']);

    // Verify that the estimate belongs to the current user
    $stmt = $conn->prepare("DELETE FROM warehouse_quotes WHERE id = ? AND user_id = ?");
    $stmt->bind_param("ii", $estimate_id_to_delete, $user_id);

    if ($stmt->execute()) {
        $success_message = "Estimate deleted successfully!";
    } else {
        $error_message = "Error deleting estimate: " . $stmt->error;
    }
    $stmt->close();

    // Refresh the page to update the list
    header("Location: " . basename($_SERVER['PHP_SELF']));
    exit();
}

// Check if the form has been submitted
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Get form variables
    $estimate_name = isset($_POST['estimate_name']) ? trim($_POST['estimate_name']) : '';
    $project_location = isset($_POST['project_location']) ? trim($_POST['project_location']) : '';
    $estimated_storage_start = isset($_POST['estimated_storage_start']) ? $_POST['estimated_storage_start'] : '';
    $estimated_number_of_pallets = isset($_POST['estimated_number_of_pallets']) ? trim($_POST['estimated_number_of_pallets']) : '';
    $pallet_length = isset($_POST['pallet_length']) ? trim($_POST['pallet_length']) : '';
    $pallet_width = isset($_POST['pallet_width']) ? trim($_POST['pallet_width']) : '';
    $pallet_height = isset($_POST['pallet_height']) ? trim($_POST['pallet_height']) : '';
    $stackable = isset($_POST['stackable']) ? true : false;

    // Handle file upload for additional documentation
    $upload_dir = 'uploads/warehouse_estimates/';
    $uploaded_file_path = '';

    if (!empty($_FILES['additional_documentation']['name'])) {
        // Ensure the upload directory exists
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }

        $file_name = basename($_FILES['additional_documentation']['name']);
        $file_tmp = $_FILES['additional_documentation']['tmp_name'];
        $file_type = $_FILES['additional_documentation']['type'];
        $file_size = $_FILES['additional_documentation']['size'];

        // Validate file type and size (optional)
        $allowed_types = ['application/pdf', 'image/jpeg', 'image/png', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'];
        $max_size = 10 * 1024 * 1024; // 10 MB

        if (in_array($file_type, $allowed_types) && $file_size <= $max_size) {
            $unique_file_name = time() . '_' . $file_name;
            $uploaded_file_path = $upload_dir . $unique_file_name;
            if (!move_uploaded_file($file_tmp, $uploaded_file_path)) {
                $error_message = "Error uploading the additional documentation.";
            }
        } else {
            $error_message = "Invalid file type or size for additional documentation.";
        }
    }

    // Validate required input fields
    if (empty($estimate_name) || empty($project_location) || empty($estimated_number_of_pallets) || empty($pallet_length) || empty($pallet_width) || empty($pallet_height)) {
        $error_message = "Please fill in all required fields.";
    } else {
        // Calculate square feet
        $length_ft = $pallet_length / 12;
        $width_ft = $pallet_width / 12;
        $pallet_area = $length_ft * $width_ft; // in square feet
        $total_area = $pallet_area * $estimated_number_of_pallets;

        if ($stackable) {
            $total_area /= 2; // Assuming stackable reduces area by half
        }

        $square_feet = $total_area;

        // Collect all estimate data
        $estimate_data = [
            'estimate_name' => $estimate_name,
            'project_location' => $project_location,
            'estimated_storage_start' => $estimated_storage_start,
            'estimated_number_of_pallets' => $estimated_number_of_pallets,
            'pallet_length' => $pallet_length,
            'pallet_width' => $pallet_width,
            'pallet_height' => $pallet_height,
            'stackable' => $stackable,
            'square_feet' => $square_feet,
            'additional_documentation' => $uploaded_file_path,
            // Initialize cost fields (admin will fill these later)
            'in_fee_per_pallet' => null,
            'out_fee_per_pallet' => null,
            'monthly_storage_cost_per_pallet' => null,
        ];

        // Save estimate to database
        $estimate_data_json = json_encode($estimate_data);

        $stmt = $conn->prepare("INSERT INTO warehouse_quotes (user_id, name, estimate_data, created_at) VALUES (?, ?, ?, NOW())");
        $stmt->bind_param("iss", $user_id, $estimate_name, $estimate_data_json);

        if ($stmt->execute()) {
            $success_message = "Your request has been sent. Solterra Solutions will provide a quote shortly.";
            // Send email notification to admin

            // Prepare the email content
            $subject = 'New Warehouse Quote Request';
            $message = "You have received a new warehouse quote request:\n\n";
            $message .= "Quote Name: $estimate_name\n";
            $message .= "Project Location: $project_location\n";
            $message .= "Estimated Storage Start: $estimated_storage_start\n";
            $message .= "Estimated Number of Pallets: $estimated_number_of_pallets\n";
            $message .= "Estimated Pallet Dimensions (L x W x H in inches): $pallet_length x $pallet_width x $pallet_height\n";
            $message .= "Stackable: " . ($stackable ? 'Yes' : 'No') . "\n";
            $message .= "Calculated Square Feet: $square_feet sq ft\n";
            if (!empty($uploaded_file_path)) {
                $message .= "Additional Documentation: Yes\n";
            } else {
                $message .= "Additional Documentation: No\n";
            }

            // Additional headers
            $headers = "From: noreply@example.com\r\n";
            $headers .= "Reply-To: noreply@example.com\r\n";
            $headers .= "X-Mailer: PHP/" . phpversion();

            // Send the email
            if (mail($to_email, $subject, $message, $headers)) {
                $success_message .= "";
            } else {
                $error_message = "There was a problem sending your request. Please try again later.";
            }
        } else {
            $error_message = "Error saving estimate: " . $stmt->error;
        }

        $stmt->close();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <!-- ... existing head content ... -->
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Warehouse Quote Request</title>
    <link rel="stylesheet" href="portal.css">
    <link rel="icon" href="pictures/favicon.png" type="image/x-icon">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <!-- Include Google Maps JavaScript API with Places Library -->
    <script src="https://maps.googleapis.com/maps/api/js?key=AIzaSyCYF3qz_6niMzpTd0yklUX9YNpk73KviBM&libraries=places"></script>

    <style>
        /* Basic styling for layout */
        .container {
            display: flex;
            justify-content: left;
            flex-wrap: wrap;
        }
        form#warehouse-form {
            width: 45%;
        }

        label {
            display: block;
            margin-top: 15px;
            font-weight: bold;
        }
        input[type="text"],
        input[type="number"],
        input[type="date"],
        input[type="file"],
        select {
            width: 95%;
            padding: 8px;
            margin-top: 5px;
        }
        .dimensions-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .dimensions-row input {
            width: 30%;
        }
        .checkbox-row {
            margin-top: 15px;
            display: flex;
            align-items: center;
        }
        .checkbox-row input {
            margin-left: 0;
            margin-right: 10px;
        }
        .success-message {
            color: green;
            margin-top: 15px;
        }
        .error-message {
            color: red;
            margin-top: 15px;
        }

        #submit-quote-button{
            margin-top: 10px;
        }

        table {
            width: 100%;
            margin-top: 20px;
        }
        .disclaimer {
            margin-top: 20px;
            font-size: 0.9em;
            color: #666;
        }
        #calculated-square-feet {
            margin-top: 15px;
            font-weight: bold;
        }
        @media (max-width: 768px) {
            .dimensions-row {
                flex-direction: column;
            }
            .dimensions-row input {
                width: 95%;
            }
            form#warehouse-form {
            width: 100%;
        }
        }
        .required {
            color: red;
        }
        /* Tooltip styles (if you have them in your original code) */
        .info-tooltip {
            display: inline-block;
            width: 18px;
            height: 18px;
            line-height: 18px;
            text-align: center;
            background-color: #488C9A; /* Secondary blue color */
            color: white;
            border-radius: 50%;
            font-weight: bold;
            cursor: pointer;
            margin-left: 5px;
            position: relative;
        }
        .info-tooltip:hover {
            background-color: #293E4C; /* Darker shade on hover */
        }
        .info-tooltip .tooltip-text {
            visibility: hidden;
            width: 220px;
            background-color: #fff;
            color: #333;
            text-align: left;
            border-radius: 4px;
            padding: 8px;
            position: absolute;
            z-index: 1;
            top: 25px;
            left: -100px;
            box-shadow: 0 0 5px rgba(0,0,0,0.3);
            font-weight: normal;
        }
        .info-tooltip:hover .tooltip-text {
            visibility: visible;
        }
    </style>
</head>
<body>
<?php include 'header.php'; ?>
<main>
<h1>Warehouse Quote Request</h1>
<!-- Saved Estimates Section -->
<div id="saved-estimates">
    <button id="saved-estimates-button" class="submit-button">Saved Quotes</button>
    <div id="saved-estimates-list" style="display: none;">
        <?php
        // Fetch user's saved estimates
        $stmt = $conn->prepare("SELECT id, name, created_at FROM warehouse_quotes WHERE user_id = ? ORDER BY created_at DESC");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $saved_estimates = [];
        while ($row = $result->fetch_assoc()) {
            $saved_estimates[] = $row;
        }
        $stmt->close();
        ?>

        <?php if (!empty($saved_estimates)): ?>
            <table>
                <tr>
                    <th>Name</th>
                    <th>Created Date</th>
                    <th>Actions</th>
                </tr>
                <?php foreach ($saved_estimates as $estimate): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($estimate['name']); ?></td>
                        <td><?php echo htmlspecialchars($estimate['created_at']); ?></td>
                        <td>
                            <a href="view_warehouse_estimate?id=<?php echo $estimate['id']; ?>">View</a>
                            |
                            <a href="#" class="delete-estimate" data-id="<?php echo $estimate['id']; ?>">Delete</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </table>
        <?php else: ?>
            <p>You have no saved quotes.</p>
        <?php endif; ?>
    </div>
</div>

<?php
// Display success or error messages
if (!empty($success_message)) {
    echo '<p class="success-message">' . htmlspecialchars($success_message) . '</p>';
}
if (!empty($error_message)) {
    echo '<p class="error-message">' . htmlspecialchars($error_message) . '</p>';
}
?>

<!-- Disclaimer moved here -->
<p><span class="required">*</span> indicates a required field.</p>

<div class="container">
    <form id="warehouse-form" method="POST" action="" enctype="multipart/form-data">
        <!-- Form Inputs -->
        <label for="estimate_name">Quote Name:<span class="required">*</span></label>
        <input type="text" id="estimate_name" name="estimate_name" required value="<?php echo htmlspecialchars($estimate_name); ?>">

        <label for="project_location">Project Location (City or ZIP Code):<span class="required">*</span></label>
        <!-- Updated Input Field for Autocomplete -->
        <input type="text" id="autocomplete" name="project_location" required value="<?php echo htmlspecialchars($project_location); ?>">

        <label for="estimated_storage_start">Estimated Storage Start:</label>
        <input type="date" id="estimated_storage_start" name="estimated_storage_start" value="<?php echo htmlspecialchars($estimated_storage_start); ?>">

        <label for="estimated_number_of_pallets">Estimated Number of Pallets:<span class="required">*</span>
            <span class="info-tooltip">?
                <span class="tooltip-text">This information can be provided by the manufacturer's logistic information sheet (may go by other names)</span>
            </span>
        </label>
        <input type="number" id="estimated_number_of_pallets" name="estimated_number_of_pallets" required value="<?php echo htmlspecialchars($estimated_number_of_pallets); ?>">

        <label>Estimated Pallet Dimensions (in inches):<span class="required">*</span>
            <span class="info-tooltip">?
                <span class="tooltip-text">This information can be provided by the manufacturer's logistic information sheet (may go by other names)</span>
            </span>
        </label>
        <div class="dimensions-row">
            <input type="number" id="pallet_length" name="pallet_length" placeholder="Length (L)" required value="<?php echo htmlspecialchars($pallet_length); ?>">
            <input type="number" id="pallet_width" name="pallet_width" placeholder="Width (W)" required value="<?php echo htmlspecialchars($pallet_width); ?>">
            <input type="number" id="pallet_height" name="pallet_height" placeholder="Height (H)" required value="<?php echo htmlspecialchars($pallet_height); ?>">
        </div>

        <div class="checkbox-row">
            <input type="checkbox" id="stackable" name="stackable" <?php echo $stackable ? 'checked' : ''; ?>>
            <label for="stackable">Stackable?</label>
        </div>

        <label for="additional_documentation">Additional Documentation:</label>
        <input type="file" id="additional_documentation" name="additional_documentation" accept=".pdf,.doc,.docx,.jpg,.png">

        <div id="calculated-square-feet">Calculated Square Feet: <span id="square-feet-value"><?php echo !empty($square_feet) ? number_format($square_feet, 2) : '0.00'; ?></span> sq ft</div>

        <button id="submit-quote-button" class="submit-button">Submit</button>
    </form>
</div>

<script>
    // Toggle saved estimates list
    document.getElementById('saved-estimates-button').addEventListener('click', function() {
        var list = document.getElementById('saved-estimates-list');
        if (list.style.display === 'none' || list.style.display === '') {
            list.style.display = 'block';
        } else {
            list.style.display = 'none';
        }
    });

    // Handle deletion
    var deleteButtons = document.querySelectorAll('.delete-estimate');
    deleteButtons.forEach(function(button) {
        button.addEventListener('click', function(event) {
            event.preventDefault();
            var estimateId = this.getAttribute('data-id');
            if (confirm('Are you sure you want to delete this estimate?')) {
                window.location.href = window.location.href.split('?')[0] + '?delete_estimate=' + estimateId;
            }
        });
    });

    // Calculate square feet dynamically as user inputs data
    function calculateSquareFeet() {
        var lengthInches = parseFloat(document.getElementById('pallet_length').value) || 0;
        var widthInches = parseFloat(document.getElementById('pallet_width').value) || 0;
        var numberOfPallets = parseInt(document.getElementById('estimated_number_of_pallets').value) || 0;
        var stackable = document.getElementById('stackable').checked;

        var lengthFeet = lengthInches / 12;
        var widthFeet = widthInches / 12;
        var palletArea = lengthFeet * widthFeet;
        var totalArea = palletArea * numberOfPallets;

        if (stackable) {
            totalArea /= 2;
        }

        totalArea = totalArea.toFixed(2);

        document.getElementById('square-feet-value').textContent = totalArea;
    }

    // Add event listeners to input fields
    document.getElementById('pallet_length').addEventListener('input', calculateSquareFeet);
    document.getElementById('pallet_width').addEventListener('input', calculateSquareFeet);
    document.getElementById('estimated_number_of_pallets').addEventListener('input', calculateSquareFeet);
    document.getElementById('stackable').addEventListener('change', calculateSquareFeet);

    // Initialize calculation
    calculateSquareFeet();

    // Google Maps Autocomplete
    let autocomplete;

    function initAutocomplete() {
        autocomplete = new google.maps.places.Autocomplete(
            document.getElementById('autocomplete'),
            { types: ['(cities)'], componentRestrictions: { country: 'us' } }
        );

        // Set fields to return
        autocomplete.setFields(['address_components', 'geometry']);

        // Add listener for place changed
        autocomplete.addListener('place_changed', fillInAddress);
    }

    function fillInAddress() {
        // Get the place details from the autocomplete object
        const place = autocomplete.getPlace();
        // You can extract components if needed
    }

    function geolocate() {
        if (navigator.geolocation) {
            navigator.geolocation.getCurrentPosition(function(position) {
                const geolocation = {
                    lat: position.coords.latitude,
                    lng: position.coords.longitude
                };
                const circle = new google.maps.Circle(
                    { center: geolocation, radius: position.coords.accuracy }
                );
                autocomplete.setBounds(circle.getBounds());
            });
        }
    }

    // Initialize the autocomplete when the window loads
    google.maps.event.addDomListener(window, 'load', initAutocomplete);
</script>
</main>
</body>
</html>
