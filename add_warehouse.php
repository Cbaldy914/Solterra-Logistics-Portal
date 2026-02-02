<?php
session_name("logistics_session");
session_start();

// Check if user is logged in and is an admin
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'global_admin', 'customer_admin'])) {
    header("Location: unauthorized");
    exit();
}
// Database connection
require_once '../config.php';
$conn = getDBConnection();
if (!$conn) {
    die("Connection failed");
}

// Get Google Maps API key from config
$google_maps_api_key = getGoogleMapsApiKey();

$role = $_SESSION['role'] ?? '';
$user_id = $_SESSION['user_id'] ?? 0;
$account_id = null;
$accounts = [];

if ($role === 'global_admin') {
    $resAccounts = $conn->query("SELECT id, name FROM customer_accounts ORDER BY name ASC");
    if ($resAccounts) {
        while ($row = $resAccounts->fetch_assoc()) {
            $accounts[] = $row;
        }
    }
} else {
    $stmtAccount = $conn->prepare("SELECT account_id FROM customer_account_users WHERE user_id = ? AND role IN ('admin', 'customer_admin') LIMIT 1");
    if ($stmtAccount) {
        $stmtAccount->bind_param("i", $user_id);
        $stmtAccount->execute();
        $stmtAccount->bind_result($account_id);
        $stmtAccount->fetch();
        $stmtAccount->close();
    }
    if (!$account_id) {
        die("No valid account found for this user.");
    }
}

// Prepare variables to hold user messages:
$successMessage = "";
$errorMessage   = "";

// Process form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['submit'])) {
    try {
        if ($role === 'global_admin') {
            $account_id = isset($_POST['account_id']) ? (int)$_POST['account_id'] : 0;
            if ($account_id <= 0) {
                throw new Exception("Please select a valid Account.");
            }
        }

        // Retrieve form data and sanitize
        $name = trim($_POST['name']);
        $street_address = trim($_POST['street_address']);
        $city = trim($_POST['city']);
        $state = trim($_POST['state']);
        $zip_code = trim($_POST['zip_code']);
        $country = trim($_POST['country'] ?? 'USA');
        $is_port = isset($_POST['is_port']) ? 1 : 0;
        
        // Cost structure - parse JSON from dynamic fee manager
        $warehouse_fees_json = $_POST['warehouse_fees'] ?? '[]';
        $warehouse_fees = json_decode($warehouse_fees_json, true);
        if (!is_array($warehouse_fees)) {
            $warehouse_fees = [];
        }

        if (empty($name)) {
            throw new Exception("Warehouse Name is required.");
        }
        if ($street_address === '' && $city === '' && $state === '' && $zip_code === '') {
            throw new Exception("At least one address field is required.");
        }

        // Handle image upload if provided
        $image_url = ""; // Empty - will display Font Awesome icon as fallback
        if (isset($_FILES['image']) && $_FILES['image']['error'] == UPLOAD_ERR_OK) {
            $allowed_ext = ['jpg','jpeg','png','gif'];
            $file_name   = $_FILES['image']['name'];
            $file_tmp    = $_FILES['image']['tmp_name'];
            $file_size   = $_FILES['image']['size'];
            $file_ext    = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));

            if (!in_array($file_ext, $allowed_ext)) {
                throw new Exception("Invalid file type. Only JPG, JPEG, PNG, GIF allowed.");
            }
            if ($file_size > 5*1024*1024) { // Example: 5MB limit
                throw new Exception("File exceeds 5MB limit.");
            }

            // Ensure the uploads directory exists
            $target_dir = "uploads/warehouse_images/";
            if (!is_dir($target_dir)) {
                if (!mkdir($target_dir, 0755, true)) {
                    throw new Exception("Failed to create upload directory.");
                }
            }

            // Sanitize the file name and create unique name
            $safe_base_name = preg_replace("/[^a-zA-Z0-9\.\-_]/", "", basename($file_name));
            $unique_name = uniqid('wh_', true).'.'.$file_ext;
            $target_file = $target_dir . $unique_name;

            if (!move_uploaded_file($file_tmp, $target_file)) {
                 throw new Exception("Error uploading image file.");
            }
            $image_url = $target_file; // Adjust based on your URL structure if needed
        } elseif (isset($_FILES['image']) && $_FILES['image']['error'] != UPLOAD_ERR_NO_FILE) {
            // Handle other upload errors
             throw new Exception("File upload error code: " . $_FILES['image']['error']);
        }

        // Insert into the database with separate address fields (trigger will populate address field)
        $stmt = $conn->prepare("INSERT INTO warehouses (account_id, name, street_address, city, state, zip_code, country, image_url, is_port) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
         if (!$stmt) {
            throw new Exception("Error preparing warehouse insert: " . $conn->error);
        }
        $stmt->bind_param("isssssssi", $account_id, $name, $street_address, $city, $state, $zip_code, $country, $image_url, $is_port);
        if ($stmt->execute()) {
            $warehouse_id = $conn->insert_id;
            
            // Insert cost items from dynamic fee manager
            if (!empty($warehouse_fees)) {
                $stmt_cost = $conn->prepare("INSERT INTO warehouse_cost_items
                    (warehouse_id, label, trigger_event, amount, unit_type, pallets_per_truck, sqft_per_pallet, display_order)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                if (!$stmt_cost) {
                    throw new Exception("Error preparing cost items insert: " . $conn->error);
                }

                $display_order = 0;
                foreach ($warehouse_fees as $fee) {
                    $label = trim($fee['name'] ?? 'Unnamed Fee');
                    $trigger = $fee['trigger'] ?? 'other';
                    $amount = floatval($fee['amount'] ?? 0);
                    $unit_type = $fee['unit'] ?? 'per_pallet';
                    $pallets_per_truck = intval($fee['palletsPerTruck'] ?? 26);
                    $sqft_per_pallet = floatval($fee['sqftPerPallet'] ?? 13.33);

                    // Skip customs clearance fee if not a port
                    if ($trigger === 'customs_clearance' && !$is_port) {
                        continue;
                    }

                    $stmt_cost->bind_param("issdsidi",
                        $warehouse_id,
                        $label,
                        $trigger,
                        $amount,
                        $unit_type,
                        $pallets_per_truck,
                        $sqft_per_pallet,
                        $display_order
                    );
                    if (!$stmt_cost->execute()) {
                        throw new Exception("Error adding cost item '{$label}': " . $stmt_cost->error);
                    }
                    $display_order++;
                }
                $stmt_cost->close();
            }
            
            $successMessage = "Warehouse and cost structure added successfully.";
        } else {
            throw new Exception("Error adding warehouse: " . htmlspecialchars($stmt->error));
        }
        $stmt->close();

    } catch (Exception $ex) {
        $errorMessage = $ex->getMessage();
    }
}

$conn->close();
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
    <style>
        /* Modern Page Header */
        .add-warehouse-header {
            background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%);
            border-radius: 24px;
            padding: 32px;
            margin-bottom: 32px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.06);
            border: 1px solid rgba(72, 140, 154, 0.08);
            position: relative;
            overflow: hidden;
        }
        .add-warehouse-header::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, #488C9A 0%, #293E4C 100%);
        }
        .add-warehouse-header-content {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 20px;
        }
        .add-warehouse-header h1 {
            font-size: 2.5em;
            font-weight: 700;
            background: linear-gradient(135deg, #293E4C 0%, #488C9A 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            margin: 0 0 8px 0;
            line-height: 1.2;
        }
        .add-warehouse-header .subtitle {
            color: #6c757d;
            font-size: 1.1em;
            font-weight: 500;
            margin: 0;
        }
        .header-actions {
            display: flex;
            gap: 12px;
        }
        .btn-back {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 12px 24px;
            background: #fff;
            color: #488C9A;
            border: 2px solid #488C9A;
            border-radius: 12px;
            font-size: 0.95rem;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
        }
        .btn-back:hover {
            background: #488C9A;
            color: #fff;
        }

        /* Accordion Sections */
        .accordion-section {
            background: #fff;
            border-radius: 16px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.06);
            margin-bottom: 20px;
            overflow: hidden;
            border: 1px solid #e9ecef;
        }
        .accordion-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 20px 24px;
            background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%);
            cursor: pointer;
            transition: all 0.3s ease;
            border-bottom: 1px solid transparent;
        }
        .accordion-header:hover {
            background: #f8f9fa;
        }
        .accordion-header.active {
            border-bottom: 1px solid #e9ecef;
        }
        .accordion-header h2 {
            margin: 0;
            font-size: 1.25rem;
            font-weight: 600;
            color: #293E4C;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .accordion-header h2 .step-badge {
            background: linear-gradient(135deg, #488C9A 0%, #3a7a87 100%);
            color: #fff;
            width: 28px;
            height: 28px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.85rem;
        }
        .accordion-header h2 .step-badge.completed {
            background: #28a745;
        }
        .accordion-toggle {
            font-size: 1.5rem;
            color: #6c757d;
            transition: transform 0.3s ease;
        }
        .accordion-header.active .accordion-toggle {
            transform: rotate(180deg);
        }
        .accordion-content {
            padding: 0;
            max-height: 0;
            overflow: hidden;
            transition: max-height 0.4s ease, padding 0.3s ease;
        }
        .accordion-content.open {
            padding: 24px;
            max-height: 2000px;
        }
        .section-description {
            color: #6c757d;
            margin-bottom: 24px;
            padding: 16px;
            background: #f8f9fa;
            border-radius: 12px;
            border-left: 4px solid #488C9A;
        }

        /* Form Fields */
        .form-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }
        .form-row.single {
            grid-template-columns: 1fr;
        }
        .form-group {
            display: flex;
            flex-direction: column;
        }
        .form-group label {
            font-weight: 500;
            color: #333;
            margin-bottom: 8px;
            font-size: 0.95rem;
        }
        .form-group label .required-star {
            color: #dc3545;
            margin-left: 4px;
        }
        .form-group label .optional-tag {
            color: #6c757d;
            font-weight: 400;
            font-size: 0.85rem;
            margin-left: 8px;
        }
        .form-group input,
        .form-group select,
        .form-group textarea {
            padding: 12px 16px;
            border: 2px solid #e9ecef;
            border-radius: 10px;
            font-size: 1rem;
            transition: all 0.2s ease;
            background: #fafafa;
        }
        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: #488C9A;
            background: #fff;
            box-shadow: 0 0 0 3px rgba(72, 140, 154, 0.1);
        }
        .form-group .help-text {
            font-size: 0.85rem;
            color: #6c757d;
            margin-top: 6px;
        }

        /* Address Grid */
        .address-grid {
            display: grid;
            grid-template-columns: 2fr 1fr 1fr 1fr;
            gap: 16px;
        }
        @media (max-width: 768px) {
            .address-grid {
                grid-template-columns: 1fr 1fr;
            }
        }

        /* Port Toggle */
        .port-toggle {
            padding: 20px;
            background: linear-gradient(135deg, #e8f4f8 0%, #f0f9fb 100%);
            border-radius: 12px;
            border: 2px solid #bee5eb;
            margin-top: 16px;
        }
        .port-toggle label {
            display: flex;
            align-items: center;
            gap: 12px;
            cursor: pointer;
            font-weight: 600;
            color: #0c5460;
        }
        .port-toggle input[type="checkbox"] {
            width: 20px;
            height: 20px;
            cursor: pointer;
        }
        .port-toggle .help-text {
            margin-left: 32px;
            margin-top: 8px;
            font-size: 0.9rem;
            color: #0c5460;
        }

        /* Image Upload */
        .image-upload-area {
            border: 2px dashed #e9ecef;
            border-radius: 12px;
            padding: 32px;
            text-align: center;
            transition: all 0.3s ease;
            cursor: pointer;
        }
        .image-upload-area:hover {
            border-color: #488C9A;
            background: #f8f9fa;
        }
        .image-upload-area .upload-icon {
            font-size: 3rem;
            margin-bottom: 12px;
            opacity: 0.5;
        }
        .image-upload-area p {
            color: #6c757d;
            margin: 0;
        }
        .image-upload-area input[type="file"] {
            display: none;
        }
        .default-image-note {
            text-align: center;
            color: #6c757d;
            font-size: 0.9rem;
            margin-top: 12px;
        }

        /* Messages */
        .success-message {
            color: #155724;
            background-color: #d4edda;
            border: 1px solid #c3e6cb;
            padding: 15px 20px;
            margin-bottom: 20px;
            border-radius: 12px;
        }
        .error-message {
            color: #721c24;
            background-color: #f8d7da;
            border: 1px solid #f5c6cb;
            padding: 15px 20px;
            margin-bottom: 20px;
            border-radius: 12px;
        }

        /* Submit Button */
        .form-actions {
            display: flex;
            justify-content: flex-end;
            gap: 12px;
            margin-top: 32px;
        }
        .btn-submit {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 14px 32px;
            background: linear-gradient(135deg, #488C9A 0%, #3a7a87 100%);
            color: white;
            border: none;
            border-radius: 12px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            box-shadow: 0 4px 12px rgba(72, 140, 154, 0.3);
        }
        .btn-submit:hover {
            background: linear-gradient(135deg, #3a7a87 0%, #293E4C 100%);
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(72, 140, 154, 0.4);
        }

        /* Fee Table Styles */
        .fee-table-container {
            overflow-x: auto;
            border-radius: 12px;
            border: 1px solid #e9ecef;
            margin-bottom: 12px;
        }
        .fee-table {
            width: 100%;
            border-collapse: collapse;
            min-width: 500px;
        }
        .fee-table th {
            background: linear-gradient(135deg, #488C9A 0%, #3a7a87 100%);
            color: #fff;
            padding: 14px 16px;
            text-align: left;
            font-weight: 600;
            font-size: 0.85rem;
        }
        .fee-table td {
            padding: 12px 16px;
            border-bottom: 1px solid #f0f0f0;
            vertical-align: middle;
        }
        .fee-table tr:last-child td {
            border-bottom: none;
        }
        .fee-table input[type="text"],
        .fee-table input[type="number"],
        .fee-table select {
            width: 100%;
            padding: 10px 12px;
            border: 2px solid #e9ecef;
            border-radius: 8px;
            font-size: 0.9rem;
            box-sizing: border-box;
        }
        .fee-table .amount-input {
            max-width: 110px;
        }
        .fee-table select {
            max-width: 150px;
        }
        .unit-param {
            margin-top: 8px;
            font-size: 0.8rem;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .unit-param label {
            color: #6c757d;
            margin: 0;
            font-size: 0.75rem;
            white-space: nowrap;
        }
        .unit-param input {
            width: 70px !important;
            padding: 6px 8px !important;
            font-size: 0.85rem !important;
        }
        .fee-row-remove {
            background: none;
            border: none;
            color: #dc3545;
            cursor: pointer;
            font-size: 1.3rem;
            padding: 4px 8px;
            opacity: 0.6;
            transition: opacity 0.2s;
        }
        .fee-row-remove:hover {
            opacity: 1;
        }
        .btn-add-fee {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 12px 18px;
            background: #f8f9fa;
            color: #488C9A;
            border: 2px dashed #488C9A;
            border-radius: 10px;
            cursor: pointer;
            font-size: 0.95rem;
            font-weight: 500;
            transition: all 0.2s;
        }
        .btn-add-fee:hover {
            background: #488C9A;
            color: #fff;
            border-style: solid;
        }
    </style>
</head>
<body>
<?php include 'header.php'; ?>
<main>
    <!-- Breadcrumb Navigation -->
    <?php
        require_once 'components/breadcrumbs.php';
        echo slp_render_breadcrumbs([
            'current_label' => 'Add Warehouse',
            'extra' => [ ['label' => 'Manage Warehouses', 'url' => 'manage_warehouses.php'] ]
        ]);
    ?>

    <!-- Modern Page Header -->
    <div class="add-warehouse-header">
        <div class="add-warehouse-header-content">
            <div>
                <h1>Add Warehouse</h1>
                <p class="subtitle">Create a new warehouse or port facility</p>
            </div>
            <div class="header-actions">
                <a href="manage_warehouses.php" class="btn-back">Back to Warehouses</a>
            </div>
        </div>
    </div>

    <!-- Display success or error messages -->
    <?php if (!empty($successMessage)): ?>
        <div class="success-message"><strong><?php echo htmlspecialchars($successMessage); ?></strong></div>
    <?php endif; ?>
    <?php if (!empty($errorMessage)): ?>
        <div class="error-message"><strong>Error:</strong> <?php echo htmlspecialchars($errorMessage); ?></div>
    <?php endif; ?>

    <form action="add_warehouse" method="post" enctype="multipart/form-data">
        <?php if ($role === 'global_admin'): ?>
            <div class="accordion-section">
                <div class="accordion-header active" onclick="toggleAccordion(this)">
                    <h2><span class="step-badge">1</span> Account</h2>
                    <span class="accordion-toggle">&#9660;</span>
                </div>
                <div class="accordion-content open">
                    <div class="section-description">
                        Assign this warehouse to a customer account.
                    </div>
                    <div class="form-row single">
                        <div class="form-group">
                            <label for="account_id">Account <span class="required-star">*</span></label>
                            <select id="account_id" name="account_id" required>
                                <option value="">Select Account</option>
                                <?php foreach ($accounts as $account): ?>
                                    <option value="<?php echo (int)$account['id']; ?>" <?php echo ((int)($_POST['account_id'] ?? 0) === (int)$account['id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($account['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- Section 1: Basic Information -->
        <div class="accordion-section">
            <div class="accordion-header active" onclick="toggleAccordion(this)">
                <h2><span class="step-badge"><?php echo ($role === 'global_admin') ? '2' : '1'; ?></span> Basic Information</h2>
                <span class="accordion-toggle">&#9660;</span>
            </div>
            <div class="accordion-content open">
                <div class="section-description">
                    Enter the warehouse name and specify if it functions as a port of entry.
                </div>

                <div class="form-row single">
                    <div class="form-group">
                        <label for="name">Warehouse Name <span class="required-star">*</span></label>
                        <input type="text" id="name" name="name" required placeholder="e.g., Charlotte Distribution Center">
                        <span class="help-text">A unique, descriptive name for this facility</span>
                    </div>
                </div>

                <div class="port-toggle">
                    <label>
                        <input type="checkbox" id="is_port" name="is_port">
                        <span>This warehouse functions as a port of entry for overseas shipments</span>
                    </label>
                    <div class="help-text">Check this if this facility will receive overseas shipments and handle customs clearance</div>
                </div>
            </div>
        </div>

        <!-- Section 2: Location -->
        <div class="accordion-section">
            <div class="accordion-header" onclick="toggleAccordion(this)">
                <h2><span class="step-badge">2</span> Location</h2>
                <span class="accordion-toggle">&#9660;</span>
            </div>
            <div class="accordion-content">
                <div class="section-description">
                    Enter the complete address for this facility. Start typing to use address autocomplete.
                </div>

                <div class="form-row single">
                    <div class="form-group">
                        <label for="street_address">Street Address</label>
                        <input type="text" id="street_address" name="street_address" placeholder="5430 Franklin Springs Circle">
                    </div>
                </div>

                <div class="address-grid">
                    <div class="form-group">
                        <label for="city">City</label>
                        <input type="text" id="city" name="city" placeholder="Charlotte">
                    </div>
                    <div class="form-group">
                        <label for="state">State/Province</label>
                        <input type="text" id="state" name="state" placeholder="NC">
                    </div>
                    <div class="form-group">
                        <label for="zip_code">Zip/Postal Code</label>
                        <input type="text" id="zip_code" name="zip_code" placeholder="28217">
                    </div>
                    <div class="form-group">
                        <label for="country">Country</label>
                        <input type="text" id="country" name="country" placeholder="USA" value="USA">
                    </div>
                </div>
            </div>
        </div>

        <!-- Section 3: Cost Structure -->
        <div class="accordion-section">
            <div class="accordion-header" onclick="toggleAccordion(this)">
                <h2><span class="step-badge">3</span> Cost Structure</h2>
                <span class="accordion-toggle">&#9660;</span>
            </div>
            <div class="accordion-content">
                <div class="section-description">
                    Configure fees for this warehouse. You can add multiple fee types with different billing units (per pallet, per truck, per sqft, or flat rate).
                </div>

                <!-- Dynamic Fee Manager Container -->
                <div id="feeManagerContainer"></div>

                <!-- Hidden input for form submission -->
                <input type="hidden" name="warehouse_fees" id="warehouseFeesInput" value="[]">

                <p class="help-text" style="margin-top: 16px;">
                    You can modify these costs anytime after creation. Leave amounts at $0.00 if not applicable.
                </p>
            </div>
        </div>

        <!-- Section 4: Warehouse Image -->
        <div class="accordion-section">
            <div class="accordion-header" onclick="toggleAccordion(this)">
                <h2><span class="step-badge">4</span> Warehouse Image <span class="optional-tag">(Optional)</span></h2>
                <span class="accordion-toggle">&#9660;</span>
            </div>
            <div class="accordion-content">
                <div class="section-description">
                    Upload a photo of the warehouse. If no image is uploaded, a default warehouse icon will be used.
                </div>

                <div class="image-upload-area" onclick="document.getElementById('image').click()">
                    <div class="upload-icon">📷</div>
                    <p><strong>Click to upload</strong> or drag and drop</p>
                    <p style="font-size: 0.85rem;">JPG, PNG, GIF up to 5MB</p>
                    <input type="file" id="image" name="image" accept="image/*" onchange="updateImagePreview(this)">
                </div>
                <div id="imagePreview" style="display: none; text-align: center; margin-top: 16px;">
                    <img id="previewImg" src="" alt="Preview" style="max-width: 200px; border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.15);">
                    <p style="margin-top: 8px; color: #488C9A; cursor: pointer;" onclick="clearImage()">Remove image</p>
                </div>
                <p class="default-image-note">If no image is uploaded, a default warehouse icon will be displayed.</p>
            </div>
        </div>

        <!-- Form Actions -->
        <div class="form-actions">
            <a href="manage_warehouses.php" class="btn-back">Cancel</a>
            <button type="submit" name="submit" class="btn-submit">Create Warehouse</button>
        </div>
    </form>
</main>

<!-- Load the Google Maps JavaScript API with Places library -->
<script src="https://maps.googleapis.com/maps/api/js?key=<?php echo htmlspecialchars($google_maps_api_key); ?>&libraries=places"></script>
<!-- Load the Warehouse Fee Manager component -->
<script src="components/warehouse-fee-manager.js"></script>

<script>
// Accordion toggle function
function toggleAccordion(header) {
    const content = header.nextElementSibling;
    const isActive = header.classList.contains('active');

    // Toggle current section
    header.classList.toggle('active');
    content.classList.toggle('open');
}

// Image preview functions
function updateImagePreview(input) {
    const preview = document.getElementById('imagePreview');
    const previewImg = document.getElementById('previewImg');

    if (input.files && input.files[0]) {
        const reader = new FileReader();
        reader.onload = function(e) {
            previewImg.src = e.target.result;
            preview.style.display = 'block';
        };
        reader.readAsDataURL(input.files[0]);
    }
}

function clearImage() {
    const input = document.getElementById('image');
    const preview = document.getElementById('imagePreview');
    input.value = '';
    preview.style.display = 'none';
}

// Initialize the fee manager when DOM is ready
document.addEventListener('DOMContentLoaded', function() {
    // Initialize fee manager with default fees
    feeManager = new WarehouseFeeManager({
        containerId: 'feeManagerContainer',
        inputId: 'warehouseFeesInput',
        initialFees: [],
        isPort: document.getElementById('is_port').checked
    });

    // Listen for port checkbox changes
    document.getElementById('is_port').addEventListener('change', function() {
        feeManager.setIsPort(this.checked);
    });
});

function initializeAddressAutocomplete() {
    // Get the street address input element
    const streetAddressInput = document.getElementById('street_address');
    const cityInput = document.getElementById('city');
    const stateInput = document.getElementById('state');
    const zipInput = document.getElementById('zip_code');
    const countryInput = document.getElementById('country');

    // Create the autocomplete object for international addresses
    const autocomplete = new google.maps.places.Autocomplete(streetAddressInput, {
        types: ['address']
        // No country restrictions - allow international addresses
    });

    // When the user selects an address from the dropdown, populate the address fields
    autocomplete.addListener('place_changed', function() {
        const place = autocomplete.getPlace();

        // Clear all fields first
        streetAddressInput.value = '';
        cityInput.value = '';
        stateInput.value = '';
        zipInput.value = '';
        countryInput.value = '';

        if (!place.geometry) {
            // User entered the name of a Place that was not suggested and pressed Enter
            console.log("No details available for input: '" + place.name + "'");
            return;
        }

        // Get the address components and populate the form fields
        let streetNumber = '';
        let route = '';

        for (let i = 0; i < place.address_components.length; i++) {
            const addressType = place.address_components[i].types[0];
            const val = place.address_components[i].long_name;

            switch (addressType) {
                case 'street_number':
                    streetNumber = val;
                    break;
                case 'route':
                    route = val;
                    break;
                case 'locality':
                case 'administrative_area_level_3':
                    cityInput.value = val;
                    break;
                case 'administrative_area_level_1':
                    // For international addresses, use long name; for US use short name
                    const shortName = place.address_components[i].short_name;
                    stateInput.value = shortName && shortName.length <= 3 ? shortName : val;
                    break;
                case 'postal_code':
                    zipInput.value = val;
                    break;
                case 'country':
                    countryInput.value = val;
                    break;
            }
        }

        // Combine street number and route for full street address
        if (streetNumber && route) {
            streetAddressInput.value = (streetNumber + ' ' + route).trim();
        } else if (route) {
            streetAddressInput.value = route; // For addresses where only route is available
        }
        // If autocomplete doesn't provide street info, keep what user typed
    });
}

// Initialize the autocomplete when the page loads
google.maps.event.addDomListener(window, 'load', initializeAddressAutocomplete);
</script>

</body>
</html>
