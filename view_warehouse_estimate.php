<?php
session_name("logistics_session");
session_start();

// Check if the user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login");
    exit();
}

if (!isset($_GET['id'])) {
    die("Estimate ID not specified.");
}

$estimate_id = intval($_GET['id']);
$user_id = $_SESSION['user_id'];

// Database connection
require_once '../config.php';
$conn = getDBConnection();
if (!$conn) {
    die("Connection failed");
}

// Fetch estimate data
$stmt = $conn->prepare("SELECT estimate_data, name, created_at FROM warehouse_quotes WHERE id = ? AND user_id = ?");
$stmt->bind_param("ii", $estimate_id, $user_id);
$stmt->execute();
$stmt->bind_result($estimate_data_json, $name, $created_at);
if ($stmt->fetch()) {
    $estimate_data = json_decode($estimate_data_json, true);
} else {
    die("Estimate not found or you do not have permission to view it.");
}
$stmt->close();

// Fetch documents for each quote
$quote_documents = [];
if (!empty($estimate_data['quotes'])) {
    foreach ($estimate_data['quotes'] as $idx => $quote) {
        if (!empty($quote['document_ids'])) {
            $placeholders = implode(',', array_fill(0, count($quote['document_ids']), '?'));
            $doc_stmt = $conn->prepare("SELECT id, original_file_name, file_size FROM project_documents WHERE id IN ($placeholders) AND is_active = 1");
            $types = str_repeat('i', count($quote['document_ids']));
            $doc_stmt->bind_param($types, ...$quote['document_ids']);
            $doc_stmt->execute();
            $doc_result = $doc_stmt->get_result();
            $quote_documents[$idx] = [];
            while ($doc_row = $doc_result->fetch_assoc()) {
                $quote_documents[$idx][] = $doc_row;
            }
            $doc_stmt->close();
        }
    }
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <!-- ... existing head content ... -->
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Warehouse Estimate</title>
    <link rel="stylesheet" href="portal.css">
    <link rel="icon" href="pictures/favicon.png" type="image/x-icon">
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        /* Add any custom styles here */
        body { background: #f7f9fb; }
        main { padding: 20px; }
        .container {
            display: flex;
            flex-wrap: wrap;
            margin: 0 auto;
        }
        .breadcrumb {
            display: flex;
            margin-bottom: 20px;
            margin-top: 10px;
        }
        .breadcrumb a {
            color: #488C9A;
            text-decoration: none;
        }
        .breadcrumb .separator {
            margin: 0 8px;
            color: #6c757d;
        }
        .left-side {
            flex: 1 1 50%;
            padding: 20px;
            box-sizing: border-box;
        }
        .right-side {
            flex: 1 1 50%;
            padding: 20px;
            box-sizing: border-box;
        }
        label {
            display: block;
            margin-top: 15px;
            font-weight: bold;
        }
        input {
            width: 95%;
            padding: 8px;
            margin-top: 5px;
        }
        input[readonly] {
            background-color: #f9f9f9;
        }
        #map {
            width: 100%;
            height: 500px;
            margin-top: 20px;
        }
        .disclaimer {
            margin-top: 20px;
            font-size: 0.9em;
            color: #666;
        }
        table {
            width: 100%;
            margin-top: 20px;
            border-collapse: collapse;
            background: white;
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 4px 16px rgba(0,0,0,0.04);
        }
        th {
            background: linear-gradient(135deg, #488C9A 0%, #3A6E7F 100%);
            color: white;
            padding: 14px;
            text-align: left;
            font-weight: 600;
            font-size: 0.9em;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        td {
            padding: 14px;
            text-align: left;
            border-bottom: 1px solid #e5e7eb;
            font-size: 0.95em;
        }
        tr:last-child td {
            border-bottom: none;
        }
        tr:hover td {
            background: #f8f9fb;
        }
        @media (max-width: 768px) {
            .container {
                flex-direction: column;
            }
            .left-side, .right-side {
                flex: 1 1 100%;
            }
        }

        /* Modern Header */
        .warehouse-header {
            background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%);
            border-radius: 24px;
            padding: 32px;
            margin-bottom: 30px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.06);
            border: 1px solid rgba(72, 140, 154, 0.08);
            position: relative;
            overflow: hidden;
        }
        .warehouse-header::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, #488C9A 0%, #293E4C 100%);
        }
        .header-content {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 24px;
        }
        .header-left {
            display: flex;
            align-items: center;
            gap: 24px;
        }
        .header-icon {
            width: 80px;
            height: 80px;
            background: linear-gradient(135deg, #488C9A 0%, #3A6E7F 100%);
            border-radius: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 36px;
            box-shadow: 0 12px 24px rgba(72, 140, 154, 0.3);
        }
        .header-info h1 {
            font-size: 2.2em;
            font-weight: 700;
            background: linear-gradient(135deg, #293E4C 0%, #488C9A 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            margin: 0 0 8px 0;
            line-height: 1.2;
        }
        .header-subtitle {
            color: #6c757d;
            font-size: 1em;
            margin: 0;
        }
        .header-badges {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
        }
        .badge {
            padding: 8px 16px;
            border-radius: 20px;
            font-weight: 600;
            font-size: 0.9em;
        }
        .badge-quotes {
            background: #e7f6f8;
            color: #1c4755;
            border: 1px solid #b8dde4;
        }

        /* Document styling */
        .docs-cell {
            display: flex;
            flex-direction: column;
            gap: 8px;
            max-width: 350px;
        }
        .doc-item-inline {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 6px 10px;
            background: #f8f9fa;
            border-radius: 8px;
            border: 1px solid #e9ecef;
            transition: all 0.2s ease;
        }
        .doc-item-inline:hover {
            background: #e7f6f8;
            border-color: #488C9A;
        }
        .doc-link {
            color: #293E4C;
            text-decoration: none;
            font-weight: 600;
            font-size: 0.9em;
            flex: 1;
            min-width: 0;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .doc-link:hover {
            color: #488C9A;
            text-decoration: underline;
        }
        .doc-size {
            font-size: 0.8em;
            color: #6c757d;
            white-space: nowrap;
        }

    </style>
</head>
<body>
<?php include 'header.php'; ?>
<main>
    <?php
        require_once 'components/breadcrumbs.php';
        echo slp_render_breadcrumbs([
            'current_label' => htmlspecialchars($name),
            'extra' => [ ['label' => 'Warehouse Quote Request', 'url' => 'warehouse_estimate.php'] ]
        ]);
    ?>
    
    <section class="warehouse-header">
        <div class="header-content">
            <div class="header-left">
                <div class="header-icon">
                    🏢
                </div>
                <div class="header-info">
                    <h1><?php echo htmlspecialchars($name); ?></h1>
                    <p class="header-subtitle">Created <?php echo htmlspecialchars(date('F j, Y', strtotime($created_at))); ?></p>
                </div>
            </div>
            <div class="header-badges">
                <?php 
                $quote_count = !empty($estimate_data['quotes']) ? count($estimate_data['quotes']) : 0;
                if ($quote_count > 0): ?>
                    <span class="badge badge-quotes">
                        <i class="fas fa-file-invoice"></i> <?php echo $quote_count; ?> <?php echo $quote_count === 1 ? 'Quote' : 'Quotes'; ?>
                    </span>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <!-- Display Quotes at the Top -->
    <h2>Available Quotes</h2>
    <?php if (!empty($estimate_data['quotes'])): ?>
        <table>
            <tr>
                <th>Warehouse Location</th>
                <th>Entry Fee (per pallet)</th>
                <th>Exit Fee (per pallet)</th>
                <th>Monthly Storage Fee (per pallet)</th>
                <th>Documents</th>
            </tr>
            <?php foreach ($estimate_data['quotes'] as $idx => $quote): 
                $doc_count = !empty($quote['document_ids']) ? count($quote['document_ids']) : 0;
            ?>
                <tr>
                    <td><?php echo htmlspecialchars($quote['warehouse_location']); ?></td>
                    <td>$<?php echo number_format($quote['entry_fee_per_pallet'] ?? $quote['in_fee_per_pallet'] ?? 0, 2); ?></td>
                    <td>$<?php echo number_format($quote['exit_fee_per_pallet'] ?? $quote['out_fee_per_pallet'] ?? 0, 2); ?></td>
                    <td>$<?php echo number_format($quote['monthly_storage_cost_per_pallet'], 2); ?></td>
                    <td>
                        <?php if ($doc_count > 0): ?>
                            <div class="docs-cell">
                                <?php 
                                $docs = $quote_documents[$idx] ?? [];
                                foreach ($docs as $doc): 
                                    $size = $doc['file_size'];
                                    if ($size < 1024) {
                                        $formatted_size = $size . ' B';
                                    } elseif ($size < 1024 * 1024) {
                                        $formatted_size = round($size / 1024, 1) . ' KB';
                                    } else {
                                        $formatted_size = round($size / (1024 * 1024), 1) . ' MB';
                                    }
                                ?>
                                    <div class="doc-item-inline">
                                        <i class="fas fa-file-alt" style="color: #488C9A;"></i>
                                        <a href="download_document.php?id=<?php echo $doc['id']; ?>" target="_blank" class="doc-link" title="<?php echo htmlspecialchars($doc['original_file_name']); ?>">
                                            <?php echo htmlspecialchars(strlen($doc['original_file_name']) > 25 ? substr($doc['original_file_name'], 0, 25) . '...' : $doc['original_file_name']); ?>
                                        </a>
                                        <span class="doc-size">(<?php echo $formatted_size; ?>)</span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <span style="color: #9ca3af; font-style: italic;">No documents</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </table>
    <?php else: ?>
        <p>A quote will be provided by Solterra Solutions shortly.</p>
    <?php endif; ?>

    <p class="disclaimer">This number is an estimate and is for budgeting purposes only.</p>

    <div class="container">
        <div class="left-side">
            <!-- Display estimate details in a non-editable form -->
            <form>
                <label for="estimate_name">Estimate Name:</label>
                <input type="text" id="estimate_name" name="estimate_name" value="<?php echo htmlspecialchars($name); ?>" readonly>

                <label for="project_location">Project Location:</label>
                <input type="text" id="project_location" name="project_location" value="<?php echo htmlspecialchars($estimate_data['project_location']); ?>" readonly>

                <label for="estimated_storage_start">Estimated Storage Start:</label>
                <input type="date" id="estimated_storage_start" name="estimated_storage_start" value="<?php echo htmlspecialchars($estimate_data['estimated_storage_start']); ?>" readonly>

                <label for="estimated_number_of_pallets">Estimated Number of Pallets:</label>
                <input type="number" id="estimated_number_of_pallets" name="estimated_number_of_pallets" value="<?php echo htmlspecialchars($estimate_data['estimated_number_of_pallets']); ?>" readonly>

                <label>Estimated Pallet Dimensions (L x W x H in inches):</label>
                <input type="text" value="<?php echo htmlspecialchars($estimate_data['pallet_length'] . ' x ' . $estimate_data['pallet_width'] . ' x ' . $estimate_data['pallet_height']); ?>" readonly>

                <label for="stackable">Stackable:</label>
                <input type="text" id="stackable" name="stackable" value="<?php echo $estimate_data['stackable'] ? 'Yes' : 'No'; ?>" readonly>

                <label for="square_feet">Calculated Square Feet:</label>
                <input type="text" id="square_feet" name="square_feet" value="<?php echo number_format($estimate_data['square_feet'], 2); ?> sq ft" readonly>
            </form>
        </div>

        <div class="right-side">
            <!-- Display the map -->
            <div id="map"></div>
        </div>
    </div>
</main>

<!-- Load the Google Maps JavaScript API -->
<script src="https://maps.googleapis.com/maps/api/js?key=REDACTED_GOOGLE_MAPS_KEY"></script>

<script>
    // JavaScript code to display the map and warehouse locations
    function initMap() {
        var map = new google.maps.Map(document.getElementById('map'), {
            zoom: 6,
            center: {lat: 34.0489, lng: -111.0937} // Center over Arizona
        });

        var geocoder = new google.maps.Geocoder();

        // Get warehouse locations from PHP
        var warehouseQuotes = <?php echo json_encode($estimate_data['quotes']); ?>;
        var projectLocation = "<?php echo addslashes($estimate_data['project_location']); ?>";

        // Geocode and place the project location marker
        geocoder.geocode({'address': projectLocation}, function(results, status) {
            if (status === 'OK') {
                var projectMarker = new google.maps.Marker({
                    map: map,
                    position: results[0].geometry.location,
                    title: 'Project Location',
                    icon: 'http://maps.google.com/mapfiles/ms/icons/blue-dot.png' // Different color icon
                });

                var projectInfoWindow = new google.maps.InfoWindow({
                    content: '<strong>Project Location</strong><br>' + projectLocation
                });

                projectMarker.addListener('click', function() {
                    projectInfoWindow.open(map, projectMarker);
                });

                // Adjust map center to fit all markers
                var bounds = new google.maps.LatLngBounds();
                bounds.extend(results[0].geometry.location);

                // Geocode and place warehouse markers
                warehouseQuotes.forEach(function(quote) {
                    var location = quote.warehouse_location;
                    geocodeAddress(geocoder, map, location, quote, bounds);
                });
            } else {
                console.error('Geocode was not successful for the following reason: ' + status);
            }
        });
    }

    function geocodeAddress(geocoder, map, address, quote, bounds) {
        geocoder.geocode({'address': address}, function(results, status) {
            if (status === 'OK') {
                var marker = new google.maps.Marker({
                    map: map,
                    position: results[0].geometry.location,
                    title: address
                });

                var infoWindow = new google.maps.InfoWindow({
                    content: '<strong>' + address + '</strong><br>' +
                                             'Entry Fee: $' + (quote.entry_fee_per_pallet || quote.in_fee_per_pallet || 0).toFixed(2) + ' per pallet<br>' +
                'Exit Fee: $' + (quote.exit_fee_per_pallet || quote.out_fee_per_pallet || 0).toFixed(2) + ' per pallet<br>' +
                             'Monthly Storage Fee: $' + quote.monthly_storage_cost_per_pallet.toFixed(2) + ' per pallet'
                });

                marker.addListener('click', function() {
                    infoWindow.open(map, marker);
                });

                bounds.extend(results[0].geometry.location);
                map.fitBounds(bounds);
            } else {
                console.error('Geocode was not successful for the following reason: ' + status);
            }
        });
    }

    // Initialize the map when the page loads
    window.onload = initMap;
</script>
</body>
</html>
