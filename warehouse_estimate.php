<?php
// warehouse_estimate
session_name("logistics_session");
session_start();

// Notification email (legacy) - we now rely on notifications
$notification_email = 'cbaldy@solterrasol.com';

// Check login
if (!isset($_SESSION['user_id'])) {
    header("Location: login");
    exit();
}

$user_role = $_SESSION['role'] ?? '';
$user_id = (int)$_SESSION['user_id'];

require_once '../config.php';
require_once 'notification_helpers.php';
require_once 'Mailer.php';
$conn = getDBConnection();
if (!$conn) {
    die("Connection failed");
}

// Notification toggles (adjust as needed)
$notify_account_admins_on_request = true;
$notify_global_admins_on_request = true;
$notify_user_on_rate_update = true;
$notify_account_admins_on_rate_update = false;
$notify_global_admins_on_rate_update = false;

// Helper function to safely validate database connection
function validateDatabaseConnection(&$conn) {
    if (!$conn || !is_object($conn)) {
        $conn = getDBConnection();
        return ($conn !== false);
    }

    if (isset($conn->connect_errno) && $conn->connect_errno) {
        $conn = getDBConnection();
        return ($conn !== false);
    }

    try {
        if (!$conn->ping()) {
            $conn = getDBConnection();
            return ($conn !== false);
        }
    } catch (Throwable $e) {
        $conn = getDBConnection();
        return ($conn !== false);
    }

    return true;
}

if (!validateDatabaseConnection($conn)) {
    die("Connection failed after reconnection attempt");
}

// Initialize variables
$estimate_name = '';
$project_location = '';
$estimated_storage_start = '';
$estimated_number_of_pallets = '';
$pallet_length = '';
$pallet_width = '';
$pallet_height = '';
$pallet_unit = 'in';
$stackable = false;
$square_feet = '';
$success_message = '';
$error_message = '';

// Handle estimate deletion
if (isset($_GET['delete_estimate'])) {
    $estimate_id_to_delete = intval($_GET['delete_estimate']);

    if (!validateDatabaseConnection($conn)) {
        $error_message = "Database connection error.";
    }

    if ($conn) {
        $stmt = $conn->prepare("DELETE FROM warehouse_quotes WHERE id = ? AND user_id = ?");
        if ($stmt) {
            $stmt->bind_param("ii", $estimate_id_to_delete, $user_id);
            if ($stmt->execute()) {
                $success_message = "Quote deleted successfully!";
            } else {
                $error_message = "Error deleting quote: " . $stmt->error;
            }
            $stmt->close();
        } else {
            $error_message = "Database error: Unable to prepare delete statement.";
            error_log("Failed to prepare delete statement: " . $conn->error);
        }
    } else {
        $error_message = "Database connection error.";
    }

    header("Location: " . basename($_SERVER['PHP_SELF']));
    exit();
}

// Handle submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $estimate_name = trim($_POST['estimate_name'] ?? '');
    $project_location = trim($_POST['project_location'] ?? '');
    $estimated_storage_start = $_POST['estimated_storage_start'] ?? '';
    $estimated_number_of_pallets = trim($_POST['estimated_number_of_pallets'] ?? '');
    $pallet_length = trim($_POST['pallet_length'] ?? '');
    $pallet_width = trim($_POST['pallet_width'] ?? '');
    $pallet_height = trim($_POST['pallet_height'] ?? '');
    $pallet_unit = $_POST['pallet_unit'] ?? 'in';
    $stackable = isset($_POST['stackable']);

    $upload_dir = 'uploads/warehouse_estimates/';
    $uploaded_file_path = '';

    if (!empty($_FILES['additional_documentation']['name'])) {
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }
        $file_name = basename($_FILES['additional_documentation']['name']);
        $file_tmp = $_FILES['additional_documentation']['tmp_name'];
        $file_type = $_FILES['additional_documentation']['type'];
        $file_size = $_FILES['additional_documentation']['size'];
        $allowed_types = ['application/pdf', 'image/jpeg', 'image/png', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'];
        $max_size = 10 * 1024 * 1024;
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

    if (empty($estimate_name) || empty($project_location) || empty($estimated_number_of_pallets) || empty($pallet_length) || empty($pallet_width)) {
        $error_message = "Please fill in all required fields.";
    } else {
        $length_ft = $pallet_length / 12;
        $width_ft = $pallet_width / 12;
        $pallet_area = $length_ft * $width_ft;
        $total_area = $pallet_area * $estimated_number_of_pallets;
        if ($stackable) {
            $total_area /= 2;
        }
        $square_feet = $total_area;

        $estimate_data = [
            'estimate_name' => $estimate_name,
            'project_location' => $project_location,
            'estimated_storage_start' => $estimated_storage_start,
            'estimated_number_of_pallets' => $estimated_number_of_pallets,
            'pallet_length' => $pallet_length,
            'pallet_width' => $pallet_width,
            'pallet_height' => null,
            'pallet_unit' => $pallet_unit,
            'stackable' => $stackable,
            'square_feet' => $square_feet,
            'additional_documentation' => $uploaded_file_path,
            'entry_fee_per_pallet' => null,
            'exit_fee_per_pallet' => null,
            'monthly_storage_cost_per_pallet' => null,
            'quotes' => [],
        ];

        $estimate_data_json = json_encode($estimate_data);

        $stmt = $conn->prepare("INSERT INTO warehouse_quotes (user_id, name, estimate_data, created_at) VALUES (?, ?, ?, NOW())");
        $stmt->bind_param("iss", $user_id, $estimate_name, $estimate_data_json);

        if ($stmt->execute()) {
            $estimate_id = (int)$stmt->insert_id;
            $success_message = "Your request has been submitted. Expect a response within 24 hours. You can revisit it under Saved Quotes.";

            $accountIds = account_ids_for_user($user_id);
            $notifyTitle = "New warehouse quote request: $estimate_name";
            $notifyMessage = "Location: $project_location";
            $adminLink = 'admin_warehouse_estimate_view?id=' . $estimate_id;

            if ($notify_account_admins_on_request) {
                notify_account_admins($accountIds, 'warehouse_estimate_request', $notifyTitle, $notifyMessage, $adminLink);
            }
            if ($notify_global_admins_on_request) {
                notify_global_admins('warehouse_estimate_request', $notifyTitle, $notifyMessage, $adminLink);
            }
        } else {
            $error_message = "Error saving estimate: " . $stmt->error;
        }

        $stmt->close();
    }
}

// Fetch user's saved estimates
$saved_estimates = [];
validateDatabaseConnection($conn);
if ($conn) {
    $stmt = $conn->prepare("SELECT id, name, estimate_data, created_at FROM warehouse_quotes WHERE user_id = ? ORDER BY created_at DESC");
    if ($stmt) {
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $row['estimate_data'] = json_decode($row['estimate_data'], true) ?? [];
            $saved_estimates[] = $row;
        }
        $stmt->close();
    } else {
        error_log("Failed to prepare saved estimates query: " . $conn->error);
    }
}

function format_estimate_rate(?array $data): array {
    $quotes = $data['quotes'] ?? [];
    $count = count($quotes);
    if ($count === 0) {
        return ['No Quotes Yet', false];
    }
    return [$count . ' Quotes', true];
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Warehouse Quote Request</title>
    <link rel="stylesheet" href="portal.css">
    <link rel="icon" href="pictures/favicon.png" type="image/x-icon">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">

    <style>
        body { background: #f7f9fb; }
        main { padding: 20px; }
        .freight-header {
            background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%);
            border-radius: 24px;
            padding: 28px;
            margin-bottom: 22px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.06);
            border: 1px solid rgba(72, 140, 154, 0.08);
            position: relative;
            overflow: hidden;
        }
        .freight-header::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0;
            height: 4px;
            background: linear-gradient(90deg, #488C9A 0%, #293E4C 100%);
        }
        .freight-header__content { display: flex; align-items: center; justify-content: space-between; gap: 20px; flex-wrap: wrap; }
        .freight-header__left { display: flex; align-items: center; gap: 18px; }
        .freight-icon { width: 70px; height: 70px; background: linear-gradient(135deg, #488C9A 0%, #3A6E7F 100%); border-radius: 18px; display: flex; align-items: center; justify-content: center; color: #fff; font-size: 34px; box-shadow: 0 12px 24px rgba(72,140,154,0.3); }
        .freight-title { margin: 0; font-size: 2.2em; background: linear-gradient(135deg, #293E4C 0%, #488C9A 100%); -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text; }
        .freight-sub { margin: 6px 0 0; color: #556; max-width: 520px; }
        .freight-actions { display: flex; gap: 10px; }

        .container { display: flex; flex-wrap: wrap; gap: 18px; }
        .card-box { background: #fff; border-radius: 16px; box-shadow: 0 10px 30px rgba(0,0,0,0.06); padding: 18px; width: 100%; }
        .left-side { flex: 1 1 380px; }
        .right-side { flex: 1 1 420px; }
        
        /* Form Grid Layout */
        #warehouse-form {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px 50px;
            align-items: start;
        }
        #warehouse-form > p { grid-column: 1 / -1; margin: 0 0 8px 0; }
        .form-field { display: flex; flex-direction: column; }
        .form-field-full { grid-column: 1 / -1; }
        .form-field label { display: block; margin: 0 0 6px 0; font-weight: 600; font-size: 0.95em; color: #293E4C; }
        .form-field input, .form-field select { padding: 10px; border: 1px solid #dbe3e7; border-radius: 10px; font-size: 1em; transition: border-color 0.2s ease; }
        .form-field input:focus, .form-field select:focus { outline: none; border-color: #488C9A; box-shadow: 0 0 0 3px rgba(72, 140, 154, 0.1); }
        
        .dimensions-row { 
            display: grid; 
            grid-template-columns: 1fr 1fr; 
            gap: 16px; 
            max-width: 500px; 
        }
        .dimensions-row input { 
            width: 100%; 
            min-height: 45px;
        }
        
        .checkbox-row { display: flex; align-items: center; gap: 8px; margin: 4px 0; }
        .checkbox-row input[type="checkbox"] { width: auto; margin: 0; }
        .checkbox-row label { margin: 0; font-weight: 600; cursor: pointer; }
        
        .success-message { color: #0f5132; background: #d1e7dd; border: 1px solid #badbcc; padding: 10px 12px; border-radius: 8px; grid-column: 1 / -1; }
        .error-message { color: #842029; background: #f8d7da; border: 1px solid #f5c2c7; padding: 10px 12px; border-radius: 8px; grid-column: 1 / -1; }
        .submit-button { background-color: #488C9A; border: none; color: #fff; padding: 12px 18px; border-radius: 12px; cursor: pointer; font-weight: 700; box-shadow: 0 8px 18px rgba(72,140,154,0.3); grid-column: 1 / -1; margin-top: 8px; }
        .submit-button:hover { background-color: #3A6E7F; }
        
        @media (max-width: 768px) { 
            .container { flex-direction: column; }
            #warehouse-form { grid-template-columns: 1fr; }
            .dimensions-row { grid-template-columns: 1fr; max-width: none; }
        }
        .required { color: red; }

        /* Saved estimates */
        .saved-estimates-card { background: #fff; border-radius: 16px; box-shadow: 0 8px 20px rgba(0,0,0,0.05); padding: 16px; margin-bottom: 18px; }
        .saved-estimates-header { display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; }
        .saved-estimates-toggle { background: #293E4C; color: #fff; border: none; border-radius: 12px; padding: 10px 16px; cursor: pointer; font-weight: 600; box-shadow: 0 10px 24px rgba(41,62,76,0.28); }
        .saved-estimates-toggle:hover { background: #1f2f3a; }
        .estimate-row { display: grid; grid-template-columns: 1fr auto auto; align-items: center; gap: 10px; padding: 12px 10px; border: 1px solid #e3eaee; border-radius: 12px; margin-bottom: 10px; background: #fdfefe; cursor: pointer; transition: box-shadow .15s ease, transform .15s ease; }
        .estimate-row:hover { box-shadow: 0 8px 20px rgba(0,0,0,0.06); transform: translateY(-2px); }
        .estimate-name { font-weight: 700; color: #1f303a; }
        .estimate-meta { color: #6c7a82; font-size: 0.92em; }
        .rate-pill { padding: 8px 12px; border-radius: 12px; font-weight: 700; }
        .delete-btn { padding: 8px 12px; border-radius: 10px; border: 1px solid #e3eaee; background: #fff; cursor: pointer; font-weight: 600; }
        .delete-btn:hover { background: #f8d7da; color: #842029; border-color: #f5c2c7; }
        .rate-pill.ready { background: #e7f6f8; color: #1c4755; border: 1px solid #b8dde4; }
        .rate-pill.pending { background: #fff6e6; color: #8a4b00; border: 1px solid #ffd699; }

        /* Modern Unit Selector */
        .unit-selector-wrapper { 
            margin: 0 0 16px 0; 
            display: flex;
            align-items: center;
            gap: 16px;
            flex-wrap: wrap;
        }
        .unit-selector-label { 
            font-weight: 600; 
            color: #293E4C; 
            margin: 0; 
            font-size: 0.95em; 
        }
        .unit-selector {
            display: inline-flex;
            background: #f3f4f6;
            border-radius: 12px;
            padding: 4px;
            gap: 4px;
            box-shadow: inset 0 2px 4px rgba(0,0,0,0.06);
        }
        .unit-selector input[type="radio"] { display: none; }
        .unit-selector .unit-option {
            padding: 8px 16px;
            border-radius: 10px;
            font-weight: 600;
            font-size: 0.85em;
            cursor: pointer;
            transition: all 0.3s ease;
            color: #6b7280;
            background: transparent;
            border: none;
            margin: 0;
        }
        .unit-selector .unit-option:hover { color: #488C9A; background: rgba(72, 140, 154, 0.08); }
        .unit-selector input[type="radio"]:checked + .unit-option {
            background: linear-gradient(135deg, #488C9A 0%, #3A6E7F 100%);
            color: white;
            box-shadow: 0 4px 12px rgba(72, 140, 154, 0.3);
            transform: translateY(-1px);
        }

        /* Calculated Square Feet Card */
        .calculated-square-feet-card {
            background: linear-gradient(135deg, #e7f6f8 0%, #d4eef3 100%);
            border: 2px solid #b8dde4;
            border-radius: 16px;
            padding: 20px;
            margin: 8px 0;
            display: flex;
            align-items: center;
            gap: 16px;
            box-shadow: 0 8px 20px rgba(72, 140, 154, 0.15);
            transition: all 0.3s ease;
            grid-column: 1 / -1;
        }
        .calculated-square-feet-card:hover { box-shadow: 0 12px 28px rgba(72, 140, 154, 0.25); transform: translateY(-2px); }
        .calc-icon { font-size: 2.5em; line-height: 1; }
        .calc-content { flex: 1; }
        .calc-label {
            font-size: 0.9em;
            font-weight: 600;
            color: #1c4755;
            margin-bottom: 4px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .calc-value {
            font-size: 2.2em;
            font-weight: 700;
            color: #0f4350;
            line-height: 1;
        }
        .calc-unit { font-size: 0.6em; font-weight: 500; color: #488C9A; margin-left: 6px; }
        .calc-formula { margin-top: 6px; color: #488C9A; font-style: italic; }

        /* Enhanced Saved Estimates Toggle */
        .saved-estimates-toggle {
            display: flex;
            align-items: center;
            justify-content: space-between;
            width: auto;
            min-width: 200px;
            gap: 12px;
        }
        .toggle-icon {
            transition: transform 0.3s ease;
            font-size: 0.9em;
        }
        .saved-estimates-toggle.active .toggle-icon { transform: rotate(180deg); }
        .saved-estimates-header { cursor: pointer; transition: all 0.2s ease; }
        .saved-estimates-header:hover { transform: translateX(2px); }
        .saved-estimates-list {
            max-height: 0;
            overflow: hidden;
            transition: max-height 0.4s ease, opacity 0.3s ease, margin-top 0.3s ease;
            opacity: 0;
        }
        .saved-estimates-list.show {
            max-height: 2000px;
            opacity: 1;
            margin-top: 12px;
        }
    </style>
</head>
<body>
<?php include 'header.php'; ?>
<main>
    <?php require_once 'components/breadcrumbs.php'; echo slp_render_breadcrumbs(['current_label' => 'Warehouse Quote Request']); ?>

    <section class="freight-header">
        <div class="freight-header__content">
            <div class="freight-header__left">
                <div class="freight-icon">🏢</div>
                <div>
                    <h1 class="freight-title">Warehouse Quote Request</h1>
                    <p class="freight-sub">Submit your storage details and track Solterra warehouse rates in one place.</p>
                </div>
            </div>
            <div class="freight-actions"></div>
        </div>
    </section>

    <?php $saved_count = count($saved_estimates); ?>
    <div class="saved-estimates-card">
        <div class="saved-estimates-header">
            <button id="saved-estimates-button" class="saved-estimates-toggle">
                <span class="toggle-text">Saved Quotes (<?php echo $saved_count; ?>)</span>
                <i class="fas fa-chevron-down toggle-icon"></i>
            </button>
            <span class="estimate-meta">View or delete saved quotes.</span>
        </div>
        <div id="saved-estimates-list" class="saved-estimates-list">
            <?php if (!empty($saved_estimates)): ?>
                <?php foreach ($saved_estimates as $estimate): 
                    [$rateDisplay, $hasRate] = format_estimate_rate($estimate['estimate_data']);
                ?>
                    <div class="estimate-row" data-estimate-id="<?php echo $estimate['id']; ?>">
                        <div>
                            <div class="estimate-name"><?php echo htmlspecialchars($estimate['name']); ?></div>
                            <div class="estimate-meta">Created <?php echo htmlspecialchars($estimate['created_at']); ?></div>
                        </div>
                        <div class="rate-pill <?php echo $hasRate ? 'ready' : 'pending'; ?>"><?php echo htmlspecialchars($rateDisplay); ?></div>
                        <button class="delete-btn" type="button" data-id="<?php echo $estimate['id']; ?>">Delete</button>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <p class="estimate-meta">You have no saved quotes.</p>
            <?php endif; ?>
        </div>
    </div>

<?php
if (!empty($success_message)) {
    echo '<p class="success-message">' . htmlspecialchars($success_message) . '</p>';
}
if (!empty($error_message)) {
    echo '<p class="error-message">' . htmlspecialchars($error_message) . '</p>';
}
?>

<div class="container">
    <div class="left-side card-box">
        <form id="warehouse-form" method="POST" action="" enctype="multipart/form-data">
            <p><span class="required">*</span> indicates a required field.</p>
            
            <div class="form-field">
                <label for="estimate_name">Quote Name:<span class="required">*</span></label>
                <input type="text" id="estimate_name" name="estimate_name" required value="<?php echo htmlspecialchars($estimate_name); ?>">
            </div>

            <div class="form-field">
                <label for="project_location">Project Location (City or ZIP Code):<span class="required">*</span></label>
                <input type="text" id="autocomplete" name="project_location" required value="<?php echo htmlspecialchars($project_location); ?>">
            </div>

            <div class="form-field">
                <label for="estimated_storage_start">Estimated Storage Start:</label>
                <input type="date" id="estimated_storage_start" name="estimated_storage_start" value="<?php echo htmlspecialchars($estimated_storage_start); ?>">
            </div>

            <div class="form-field">
                <label for="estimated_number_of_pallets">Estimated Number of Pallets:<span class="required">*</span>
                    <span class="info-tooltip">?
                        <span class="tooltip-text">This information can be provided by the manufacturer's logistic information sheet.</span>
                    </span>
                </label>
                <input type="number" id="estimated_number_of_pallets" name="estimated_number_of_pallets" required value="<?php echo htmlspecialchars($estimated_number_of_pallets); ?>">
            </div>

            <div class="form-field-full">
                <label>Estimated Pallet Dimensions:<span class="required">*</span>
                    <span class="info-tooltip">?
                        <span class="tooltip-text">This information can be provided by the manufacturer's logistic information sheet.</span>
                    </span>
                </label>
                <div class="unit-selector-wrapper">
                    <label class="unit-selector-label">Unit of Measurement:</label>
                    <div class="unit-selector">
                        <input type="radio" name="pallet_unit" value="in" id="unit_in" <?php echo ($pallet_unit ?? 'in')==='in' ? 'checked' : ''; ?> onchange="calculateSquareFeet()">
                        <label for="unit_in" class="unit-option">Inches</label>
                        
                        <input type="radio" name="pallet_unit" value="cm" id="unit_cm" <?php echo ($pallet_unit ?? 'in')==='cm' ? 'checked' : ''; ?> onchange="calculateSquareFeet()">
                        <label for="unit_cm" class="unit-option">Centimeters</label>
                        
                        <input type="radio" name="pallet_unit" value="mm" id="unit_mm" <?php echo ($pallet_unit ?? 'in')==='mm' ? 'checked' : ''; ?> onchange="calculateSquareFeet()">
                        <label for="unit_mm" class="unit-option">Millimeters</label>
                    </div>
                </div>
                <div class="dimensions-row">
                    <input type="number" step="0.01" id="pallet_length" name="pallet_length" placeholder="Length" required value="<?php echo htmlspecialchars($pallet_length); ?>">
                    <input type="number" step="0.01" id="pallet_width" name="pallet_width" placeholder="Width" required value="<?php echo htmlspecialchars($pallet_width); ?>">
                </div>
                <div class="checkbox-row">
                    <input type="checkbox" id="stackable" name="stackable" <?php echo $stackable ? 'checked' : ''; ?> onchange="calculateSquareFeet()">
                    <label for="stackable">Stackable?</label>
                </div>
            </div>

            <div class="form-field-full">
                <label for="additional_documentation">Additional Documentation:</label>
                <input type="file" id="additional_documentation" name="additional_documentation" accept=".pdf,.doc,.docx,.jpg,.png">
            </div>

            <div class="calculated-square-feet-card">
                <div class="calc-icon">📐</div>
                <div class="calc-content">
                    <div class="calc-label">Calculated Square Feet</div>
                    <div class="calc-value">
                        <span id="square-feet-value"><?php echo !empty($square_feet) ? number_format($square_feet, 2) : '0.00'; ?></span>
                        <span class="calc-unit">sq ft</span>
                    </div>
                    <div class="calc-formula" id="calc-formula-display" style="display: none;">
                        <small>Formula: <span id="formula-text"></span></small>
                    </div>
                </div>
            </div>

            <button id="submit-quote-button" class="submit-button">Submit</button>
        </form>
    </div>
</div>

<script src="https://maps.googleapis.com/maps/api/js?key=<?php echo htmlspecialchars(getGoogleMapsApiKey()); ?>&libraries=places"></script>
<script>
    // Toggle saved estimates list
    const toggleButton = document.getElementById('saved-estimates-button');
    const savedList = document.getElementById('saved-estimates-list');

    toggleButton.addEventListener('click', function() {
        const isHidden = !savedList.classList.contains('show');
        if (isHidden) {
            savedList.classList.add('show');
            toggleButton.classList.add('active');
        } else {
            savedList.classList.remove('show');
            toggleButton.classList.remove('active');
        }
    });

    document.querySelectorAll('.estimate-row').forEach(row => {
        row.addEventListener('click', () => {
            const estimateId = row.getAttribute('data-estimate-id');
            window.location.href = `view_warehouse_estimate?id=${estimateId}`;
        });
    });

    document.querySelectorAll('.delete-btn').forEach(btn => {
        btn.addEventListener('click', (event) => {
            event.stopPropagation();
            const estimateId = btn.getAttribute('data-id');
            if (confirm('Are you sure you want to delete this quote?')) {
                window.location.href = window.location.href.split('?')[0] + '?delete_estimate=' + estimateId;
            }
        });
    });

    function calculateSquareFeet() {
        let lengthInches = parseFloat(document.getElementById('pallet_length').value) || 0;
        let widthInches = parseFloat(document.getElementById('pallet_width').value) || 0;
        const unit = document.querySelector('input[name="pallet_unit"]:checked')?.value || 'in';
        const unitLabel = unit === 'in' ? 'inches' : (unit === 'cm' ? 'cm' : 'mm');
        
        // Convert to inches
        let lengthInput = lengthInches;
        let widthInput = widthInches;
        if (unit === 'cm') { lengthInches = lengthInches / 2.54; widthInches = widthInches / 2.54; }
        if (unit === 'mm') { lengthInches = lengthInches / 25.4; widthInches = widthInches / 25.4; }
        const numberOfPallets = parseInt(document.getElementById('estimated_number_of_pallets').value) || 0;
        const stackable = document.getElementById('stackable').checked;

        const lengthFeet = lengthInches / 12;
        const widthFeet = widthInches / 12;
        let totalArea = (lengthFeet * widthFeet) * numberOfPallets;
        
        // Build formula string
        let formulaText = '';
        if (lengthInput > 0 && widthInput > 0 && numberOfPallets > 0) {
            formulaText = `(${lengthInput} × ${widthInput} ${unitLabel}) × ${numberOfPallets} pallets`;
            if (stackable) { 
                totalArea /= 2;
                formulaText += ' ÷ 2 (stackable)';
            }
            document.getElementById('calc-formula-display').style.display = 'block';
            document.getElementById('formula-text').textContent = formulaText;
        } else {
            document.getElementById('calc-formula-display').style.display = 'none';
        }
        
        document.getElementById('square-feet-value').textContent = totalArea.toFixed(2);
    }

    ['pallet_length','pallet_width','estimated_number_of_pallets'].forEach(id => {
        const el = document.getElementById(id);
        if (el) el.addEventListener('input', calculateSquareFeet);
    });
    const stackableEl = document.getElementById('stackable');
    if (stackableEl) stackableEl.addEventListener('change', calculateSquareFeet);
    calculateSquareFeet();

    // Google Maps Autocomplete (ZIP/city)
    let autocomplete;
    function initAutocomplete() {
        autocomplete = new google.maps.places.Autocomplete(
            document.getElementById('autocomplete'),
            { types: ['geocode'], componentRestrictions: { country: 'us' } }
        );
    }
    google.maps.event.addDomListener(window, 'load', initAutocomplete);
</script>
</main>
</body>
</html>
