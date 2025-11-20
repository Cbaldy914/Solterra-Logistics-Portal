<?php
// freight_estimate

session_name("logistics_session");
session_start();

$notification_email = 'cbaldy@solterrasol.com';

// Check if the user is logged in
if (!isset($_SESSION['user_id'])) {
    // redirect to login page
    header("Location: login");
    exit();
}

// Get user role
$user_role = isset($_SESSION['role']) ? $_SESSION['role'] : '';

// Get user ID
$user_id = (int)$_SESSION['user_id'];

// Database connection
require_once '../config.php';
require_once 'notification_helpers.php';
require_once 'Mailer.php';
$conn = getDBConnection();
if (!$conn) {
    die("Connection failed");
}

// Helper function to safely validate database connection
function validateDatabaseConnection(&$conn) {
    if (!$conn || !is_object($conn)) {
        $conn = getDBConnection();
        return ($conn !== false);
    }
    
    // Check if connection has errors
    if (isset($conn->connect_errno) && $conn->connect_errno) {
        $conn = getDBConnection();
        return ($conn !== false);
    }
    
    // Try to ping the connection
    try {
        if (!$conn->ping()) {
            $conn = getDBConnection();
            return ($conn !== false);
        }
    } catch (Error $e) {
        // Connection is closed or invalid
        $conn = getDBConnection();
        return ($conn !== false);
    } catch (Exception $e) {
        // Any other connection issue
        $conn = getDBConnection();
        return ($conn !== false);
    }
    
    return true;
}

// Validate initial connection
if (!validateDatabaseConnection($conn)) {
    die("Connection failed after reconnection attempt");     
}

// Get Google Maps API key from config
$google_maps_api_key = getGoogleMapsApiKey();

// Initialize variables
$origin = $destination = $distance = $project_size = $estimated_start_date = '';
$estimated_number_of_trucks = $estimated_modules_per_truck = '';
$estimate_name = '';
$cost_per_truck = $total_accessorial_cost = '';
$total_freight_cost = $grand_total = 0;

// Handle estimate deletion
if (isset($_GET['delete_estimate'])) {
    $estimate_id_to_delete = intval($_GET['delete_estimate']);

    // Validate connection before using it
    if (!validateDatabaseConnection($conn)) {
        $error_message = "Database connection error.";
    }
    
    if ($conn) {
        // Verify that the estimate belongs to the current user
        $stmt = $conn->prepare("DELETE FROM freight_estimates WHERE id = ? AND user_id = ?");
        if ($stmt) {
            $stmt->bind_param("ii", $estimate_id_to_delete, $user_id);

            if ($stmt->execute()) {
                $success_message = "Estimate deleted successfully!";
            } else {
                $error_message = "Error deleting estimate: " . $stmt->error;
            }
            $stmt->close();
        } else {
            $error_message = "Database error: Unable to prepare delete statement.";
            error_log("Failed to prepare delete statement: " . $conn->error);
        }
    } else {
        $error_message = "Database connection error.";
    }

    // Refresh the page to update the list
    header("Location: " . basename($_SERVER['PHP_SELF']));
    exit();
}

// Check if the form has been submitted
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Get form variables
    $origin = trim($_POST['origin']);
    $destination = trim($_POST['destination']);
    $distance = isset($_POST['distance']) ? $_POST['distance'] : 'N/A';
    $project_size = isset($_POST['project_size']) ? trim($_POST['project_size']) : '';
    $estimated_start_date = isset($_POST['estimated_start_date']) ? $_POST['estimated_start_date'] : '';
    $estimated_number_of_trucks = isset($_POST['estimated_number_of_trucks']) ? trim($_POST['estimated_number_of_trucks']) : '';
    $estimated_modules_per_truck = isset($_POST['estimated_modules_per_truck']) ? trim($_POST['estimated_modules_per_truck']) : '';
    $estimate_name = isset($_POST['estimate_name']) ? trim($_POST['estimate_name']) : '';

    // Admin-only fields
    if ($user_role == 'admin') {
        $cost_per_truck = isset($_POST['cost_per_truck']) ? trim($_POST['cost_per_truck']) : '';
        $total_accessorial_cost = isset($_POST['total_accessorial_cost']) ? trim($_POST['total_accessorial_cost']) : '';
    }

    // Validate required input fields
    if (empty($origin) || empty($destination) || empty($estimate_name)) {
        $error_message = "Please fill in all required fields.";
    } else {
        // Collect all estimate data
        $estimate_data = [
            'origin' => $origin,
            'destination' => $destination,
            'distance' => $distance,
            'project_size' => $project_size,
            'estimated_start_date' => $estimated_start_date,
            'estimated_number_of_trucks' => $estimated_number_of_trucks,
            'estimated_modules_per_truck' => $estimated_modules_per_truck,
            // Initialize cost fields (admin will fill these later)
            'cost_per_truck' => null,
            'total_accessorial_cost' => null,
            'total_freight_cost' => null,
            'grand_total' => null
        ];

        // Save estimate to database
        $estimate_data_json = json_encode($estimate_data);

        $stmt = $conn->prepare("INSERT INTO freight_estimates (user_id, name, estimate_data, created_at) VALUES (?, ?, ?, NOW())");
        $stmt->bind_param("iss", $user_id, $estimate_name, $estimate_data_json);

        if ($stmt->execute()) {
            $estimate_id = (int)$stmt->insert_id;
            $success_message = "Your estimate has been saved successfully!";

            // Prepare the email content
            $subject = '[Solterra Logistics] New Freight Cost Estimate Request';
            $message = "You have received a new freight cost estimate request:\n\n";
            $message .= "Estimate Name: $estimate_name\n";
            $message .= "Origin: $origin\n";
            $message .= "Destination: $destination\n";
            $message .= "Distance: $distance miles\n";
            $message .= "Project Size: $project_size MW\n";
            $message .= "Estimated Start Date: $estimated_start_date\n";
            $message .= "Estimated Number of Trucks: $estimated_number_of_trucks\n";
            $message .= "Estimated Modules Per Truck: $estimated_modules_per_truck\n";

            try {
                $sent = Mailer::send($notification_email, $subject, $message);
                if (!$sent) {
                    error_log("Freight estimate notification email returned false for: " . $notification_email . " (Estimate ID #" . $estimate_id . ")");
                }
            } catch (Exception $e) {
                // Log error but don't fail the request
                error_log("Freight estimate notification email failed: " . $e->getMessage());
            }

            // Notify admins and global admins
            $accountIds = account_ids_for_user($user_id);
            $notifyTitle = "New freight estimate request: $estimate_name";
            $notifyMessage = "Origin: $origin → Destination: $destination";
            $adminLink = 'admin_freight_estimate_view?id=' . $estimate_id;

            notify_account_admins($accountIds, 'freight_estimate_request', $notifyTitle, $notifyMessage, $adminLink);
            notify_global_admins('freight_estimate_request', $notifyTitle, $notifyMessage, $adminLink);
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
    $stmt = $conn->prepare("SELECT id, name, estimate_data, created_at FROM freight_estimates WHERE user_id = ? ORDER BY created_at DESC");
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
    $grand = $data['grand_total'] ?? null;
    $hasRate = is_numeric($grand) && (float)$grand > 0;
    $display = $hasRate ? '$' . number_format((float)$grand, 2) : 'Rate pending';
    return [$display, $hasRate];
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <!-- ... existing head content ... -->
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Freight Cost Estimator</title>
    <link rel="stylesheet" href="portal.css">
    <link rel="icon" href="pictures/favicon.png" type="image/x-icon">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        /* Basic styling for layout */
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

        .container {
            display: flex;
            flex-wrap: wrap;
            gap: 18px;
        }
        .card-box { background: #fff; border-radius: 16px; box-shadow: 0 10px 30px rgba(0,0,0,0.06); padding: 18px; width: 100%; }
        .left-side { flex: 1 1 380px; }
        .right-side { flex: 1 1 420px; }
        label {
            display: block;
            margin-top: 15px;
            font-weight: bold;
        }
        input, select {
            width: 100%;
            padding: 10px;
            margin-top: 6px;
            border: 1px solid #dbe3e7;
            border-radius: 10px;
            font-size: 1em;
        }
        #distance {
            font-weight: bold;
            margin-top: 10px;
        }
        #map {
            width: 100%;
            height: 500px; /* Adjusted height for better visibility */
            background-color: #ccc;
            margin-top: 20px;
            border-radius: 14px;
            overflow: hidden;
        }
        .success-message {
            color: #0f5132;
            background: #d1e7dd;
            border: 1px solid #badbcc;
            padding: 10px 12px;
            border-radius: 8px;
            margin-top: 15px;
        }
        .error-message {
            color: #842029;
            background: #f8d7da;
            border: 1px solid #f5c2c7;
            padding: 10px 12px;
            border-radius: 8px;
            margin-top: 15px;
        }
        .disclaimer {
            margin-top: 10px;
            font-size: 0.9em;
            color: #666;
        }

        #submit-quote-button{
            margin-top: 14px;
        }
        .submit-button {
            background-color: #488C9A;
            border: none;
            color: #fff;
            padding: 12px 18px;
            border-radius: 12px;
            cursor: pointer;
            font-weight: 700;
            box-shadow: 0 8px 18px rgba(72,140,154,0.3);
        }
        .submit-button:hover { background-color: #3A6E7F; }
        
        @media (max-width: 768px) {
            .container { flex-direction: column; }
            .left-side, .right-side { flex: 1 1 100%; }
        }
        .required {
            color: red;
        }

        /* Saved estimates */
        .saved-estimates-card { background: #fff; border-radius: 16px; box-shadow: 0 8px 20px rgba(0,0,0,0.05); padding: 16px; margin-bottom: 18px; }
        .saved-estimates-header { display: flex; align-items: center; justify-content: space-between; gap: 12px; }
        .saved-estimates-toggle { background: #293E4C; color: #fff; border: none; border-radius: 12px; padding: 10px 16px; cursor: pointer; font-weight: 600; box-shadow: 0 10px 24px rgba(41,62,76,0.28); }
        .saved-estimates-toggle:hover { background: #1f2f3a; }
        .saved-estimates-list { margin-top: 12px; display: none; }
        .estimate-row { display: grid; grid-template-columns: 1fr auto auto; align-items: center; gap: 10px; padding: 12px 10px; border: 1px solid #e3eaee; border-radius: 12px; margin-bottom: 10px; background: #fdfefe; cursor: pointer; transition: box-shadow .15s ease, transform .15s ease; }
        .estimate-row:hover { box-shadow: 0 8px 20px rgba(0,0,0,0.06); transform: translateY(-2px); }
        .estimate-name { font-weight: 700; color: #1f303a; }
        .estimate-meta { color: #6c7a82; font-size: 0.92em; }
        .rate-pill { padding: 8px 12px; border-radius: 12px; font-weight: 700; }
        .rate-pill.ready { background: #e7f6f8; color: #1c4755; border: 1px solid #b8dde4; }
        .rate-pill.pending { background: #fff6e6; color: #8a4b00; border: 1px solid #ffd699; }
        .view-link { color: #488C9A; text-decoration: none; font-weight: 600; }

        /* Modal */
        .modal-backdrop { position: fixed; inset: 0; background: rgba(13,21,28,0.35); display: none; align-items: center; justify-content: center; z-index: 2000; }
        .modal-backdrop.open { display: flex; }
        .modal { background: #fff; border-radius: 16px; max-width: 640px; width: 92%; box-shadow: 0 20px 60px rgba(0,0,0,.2); padding: 20px; position: relative; }
        .modal h3 { margin-top: 0; }
        .modal-close { position: absolute; top: 12px; right: 12px; background: none; border: none; font-size: 22px; cursor: pointer; }
        .modal-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
        .modal-tile { background: #f7f9fb; border-radius: 12px; padding: 12px; }
        .modal-actions { margin-top: 12px; display: flex; gap: 10px; justify-content: flex-end; flex-wrap: wrap; }
        .link-button { padding: 10px 14px; border-radius: 10px; border: 1px solid #dbe3e7; background: #fff; color: #1f303a; text-decoration: none; font-weight: 600; }
        .link-button.primary { background: #488C9A; color: #fff; border-color: #488C9A; }
    </style>
</head>
<body>
<?php include 'header.php'; ?>
<main>
    <?php require_once 'components/breadcrumbs.php'; echo slp_render_breadcrumbs(['current_label' => 'Freight Cost Estimator']); ?>

    <section class="freight-header">
        <div class="freight-header__content">
            <div class="freight-header__left">
                <div class="freight-icon">🚚</div>
                <div>
                    <h1 class="freight-title">Freight Cost Estimator</h1>
                    <p class="freight-sub">Plan your lane, capture quotes, and keep track of Solterra rates in one spot.</p>
                </div>
            </div>
            <div class="freight-actions">
                <button id="saved-estimates-button" class="saved-estimates-toggle">Saved Estimates</button>
            </div>
        </div>
    </section>

    <!-- Saved Estimates Section -->
    <div class="saved-estimates-card">
        <div class="saved-estimates-header">
            <h3 style="margin:0;">Quick Picks</h3>
            <span class="estimate-meta">Open a saved request to view details or delete.</span>
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
                        <a class="view-link" href="view_freight_estimate?id=<?php echo $estimate['id']; ?>">Open</a>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <p class="estimate-meta">You have no saved estimates.</p>
            <?php endif; ?>
        </div>
    </div>

<?php
// Display success or error messages
if (isset($success_message)) {
    echo '<p class="success-message">' . htmlspecialchars($success_message) . '</p>';
}
if (isset($error_message)) {
    echo '<p class="error-message">' . htmlspecialchars($error_message) . '</p>';
}
?>

<div class="container">
    <div class="left-side card-box">
        <p><span class="required">*</span> indicates a required field.</p>
        <form id="freight-form" method="POST" action="">
            <!-- Form Inputs -->
            <label for="estimate_name">Estimate Name:<span class="required">*</span></label>
            <input type="text" id="estimate_name" name="estimate_name" required value="<?php echo htmlspecialchars($estimate_name); ?>">

            <label for="origin">Origin (City or ZIP Code):<span class="required">*</span></label>
            <input type="text" id="origin" name="origin" required value="<?php echo htmlspecialchars($origin); ?>">

            <label for="destination">Destination (City or ZIP Code):<span class="required">*</span></label>
            <input type="text" id="destination" name="destination" required value="<?php echo htmlspecialchars($destination); ?>">

            <label for="project_size">Project Size (in MW):</label>
            <input type="number" id="project_size" name="project_size" step="0.01" value="<?php echo htmlspecialchars($project_size); ?>">

            <label for="estimated_start_date">Estimated Start Date:</label>
            <input type="date" id="estimated_start_date" name="estimated_start_date" value="<?php echo htmlspecialchars($estimated_start_date); ?>">

            <label for="estimated_number_of_trucks">Estimated Number of Trucks:
                <span class="info-tooltip">?
                <span class="tooltip-text">This information can be provided by the manufacturer's logistic information sheet (may go by other names)</span>
            </span>
            </label>
            <input type="number" id="estimated_number_of_trucks" name="estimated_number_of_trucks" value="<?php echo htmlspecialchars($estimated_number_of_trucks); ?>">

            <label for="estimated_modules_per_truck">Estimated Modules Per Truck:
                <span class="info-tooltip">?
                <span class="tooltip-text">This information can be provided by the manufacturer's logistic information sheet (may go by other names)</span>
            </span>
            </label>
            <input type="number" id="estimated_modules_per_truck" name="estimated_modules_per_truck" value="<?php echo htmlspecialchars($estimated_modules_per_truck); ?>">

            <div id="distance"><?php if ($distance !== 'N/A') echo 'Distance: ' . htmlspecialchars($distance) . ' miles'; ?></div>

            <!-- Add distance hidden input -->
            <input type="hidden" id="distanceInput" name="distance" value="<?php echo htmlspecialchars($distance); ?>">

            <button id="submit-quote-button" class="submit-button">Submit</button>
        </form>
    </div>

    <!-- Right Side Content (Admin fields or Estimated Costs) -->
    <div class="right-side card-box">
        <!-- Display the map -->
        <div id="map"></div>
    </div>
</div>

    <p class="disclaimer">This number is an estimate and is for budgeting purposes only.</p>

<!-- Load the Google Maps JavaScript API with Places library -->
<script src="https://maps.googleapis.com/maps/api/js?key=<?php echo htmlspecialchars($google_maps_api_key); ?>&libraries=places"></script>

<script>
    // JavaScript code for map and distance calculation

    let map;
    let directionsService;
    let directionsRenderer;
    let distanceElement = document.getElementById('distance');

    function initMap() {
        directionsService = new google.maps.DirectionsService();
        directionsRenderer = new google.maps.DirectionsRenderer();

        map = new google.maps.Map(document.getElementById('map'), {
            zoom: 5,
            center: { lat: 39.5, lng: -98.35 } // Center of the US
        });

        directionsRenderer.setMap(map);

        initAutocomplete();

        // If form was submitted, calculate the route
        <?php if ($_SERVER['REQUEST_METHOD'] == 'POST' && empty($error_message)): ?>
        calculateRoute();
        <?php endif; ?>
    }

    function initAutocomplete() {
        const originInput = document.getElementById('origin');
        const destinationInput = document.getElementById('destination');

        const options = {
            // Restrict predictions to geographical location types
            types: ['geocode'],
            componentRestrictions: { country: 'us' }
        };

        const originAutocomplete = new google.maps.places.Autocomplete(originInput, options);
        const destinationAutocomplete = new google.maps.places.Autocomplete(destinationInput, options);

        originAutocomplete.addListener('place_changed', calculateRoute);
        destinationAutocomplete.addListener('place_changed', calculateRoute);

        // Also handle manual input and changes
        originInput.addEventListener('change', calculateRoute);
        destinationInput.addEventListener('change', calculateRoute);
    }

    function calculateRoute() {
        const origin = document.getElementById('origin').value;
        const destination = document.getElementById('destination').value;

        if (origin && destination) {
            const request = {
                origin: origin,
                destination: destination,
                travelMode: 'DRIVING'
            };

            directionsService.route(request, function (result, status) {
                if (status == 'OK') {
                    directionsRenderer.setDirections(result);

                    // Get the distance in miles
                    const distanceInMeters = result.routes[0].legs[0].distance.value;
                    const distanceInMiles = (distanceInMeters / 1609.34).toFixed(2);

                    // Display the distance
                    distanceElement.textContent = `Distance: ${distanceInMiles} miles`;

                    // Update the distance hidden input
                    document.getElementById('distanceInput').value = distanceInMiles;
                } else {
                    distanceElement.textContent = 'Could not calculate distance.';
                }
            });
        } else {
            // Clear the map and distance if inputs are incomplete
            directionsRenderer.set('directions', null);
            distanceElement.textContent = '';
        }
    }

    // Initialize the map when the window loads
    window.onload = initMap;
</script>
<script>
    // Toggle saved estimates list
    const toggleButton = document.getElementById('saved-estimates-button');
    const savedList = document.getElementById('saved-estimates-list');
    const savedEstimates = <?php echo json_encode($saved_estimates, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;

    toggleButton.addEventListener('click', function() {
        const isHidden = savedList.style.display === 'none' || savedList.style.display === '';
        savedList.style.display = isHidden ? 'block' : 'none';
    });

    // Modal handling
    const modal = document.createElement('div');
    modal.className = 'modal-backdrop';
    modal.innerHTML = `
        <div class="modal" role="dialog" aria-modal="true">
            <button class="modal-close" aria-label="Close">×</button>
            <h3 id="modal-title"></h3>
            <div class="modal-grid">
                <div class="modal-tile">
                    <strong>Origin</strong>
                    <div id="modal-origin"></div>
                </div>
                <div class="modal-tile">
                    <strong>Destination</strong>
                    <div id="modal-destination"></div>
                </div>
                <div class="modal-tile">
                    <strong>Distance</strong>
                    <div id="modal-distance"></div>
                </div>
                <div class="modal-tile">
                    <strong>Project Size</strong>
                    <div id="modal-project"></div>
                </div>
                <div class="modal-tile">
                    <strong>Rate</strong>
                    <div id="modal-rate"></div>
                </div>
                <div class="modal-tile">
                    <strong>Created</strong>
                    <div id="modal-created"></div>
                </div>
            </div>
            <div class="modal-actions">
                <a id="modal-view" class="link-button primary" href="#">View Full Estimate</a>
                <button id="modal-delete" class="link-button" type="button">Delete</button>
                <button class="link-button modal-close" type="button">Close</button>
            </div>
        </div>
    `;
    document.body.appendChild(modal);

    function formatCurrency(value) {
        const num = parseFloat(value);
        if (isNaN(num)) return 'Rate pending';
        return `$${num.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
    }

    function openModal(estimateId) {
        const estimate = savedEstimates.find(e => String(e.id) === String(estimateId));
        if (!estimate) return;
        const data = estimate.estimate_data || {};

        document.getElementById('modal-title').textContent = estimate.name || 'Estimate';
        document.getElementById('modal-origin').textContent = data.origin || '—';
        document.getElementById('modal-destination').textContent = data.destination || '—';
        document.getElementById('modal-distance').textContent = data.distance ? `${data.distance} miles` : '—';
        document.getElementById('modal-project').textContent = data.project_size ? `${data.project_size} MW` : '—';
        document.getElementById('modal-rate').textContent = formatCurrency(data.grand_total);
        document.getElementById('modal-created').textContent = estimate.created_at || '';

        document.getElementById('modal-view').href = `view_freight_estimate?id=${estimate.id}`;
        document.getElementById('modal-delete').setAttribute('data-id', estimate.id);

        modal.classList.add('open');
    }

    function closeModal() {
        modal.classList.remove('open');
    }

    modal.addEventListener('click', (event) => {
        if (event.target.classList.contains('modal-backdrop') || event.target.classList.contains('modal-close')) {
            closeModal();
        }
    });

    document.querySelectorAll('.estimate-row').forEach(row => {
        row.addEventListener('click', (event) => {
            // Prevent navigating when clicking the inline "Open" link
            if (event.target.closest('a')) {
                return;
            }
            const estimateId = row.getAttribute('data-estimate-id');
            openModal(estimateId);
        });
    });

    document.getElementById('modal-delete').addEventListener('click', (event) => {
        const estimateId = event.target.getAttribute('data-id');
        if (!estimateId) return;
        if (confirm('Are you sure you want to delete this estimate?')) {
            window.location.href = window.location.href.split('?')[0] + '?delete_estimate=' + estimateId;
        }
    });
</script>
</main>
</body>
</html>
