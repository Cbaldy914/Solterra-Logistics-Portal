<?php
session_name("logistics_session");
session_start();

// Allow access for admin, global_admin, and user roles
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'global_admin', 'user'])) {
    header("Location: unauthorized");
    exit();
}

require_once '../config.php';
$conn = getDBConnection();
if (!$conn) {
    die("Database connection failed.");
}

$role = $_SESSION['role'];
$user_id = $_SESSION['user_id'];
$selected_project_id = isset($_GET['project_id']) ? intval($_GET['project_id']) : 0;
$batch_id = isset($_GET['batch_id']) ? intval($_GET['batch_id']) : 0;

// Account filtering for admin users
$account_id_for_admin = null;
if ($role === 'admin') {
    $sqlAdminAcc = "SELECT account_id FROM customer_account_users WHERE user_id = ? AND role = 'admin' LIMIT 1";
    $stmtAdminAcc = $conn->prepare($sqlAdminAcc);
    if ($stmtAdminAcc) {
        $stmtAdminAcc->bind_param("i", $user_id);
        $stmtAdminAcc->execute();
        $stmtAdminAcc->bind_result($account_id_for_admin);
        $stmtAdminAcc->fetch();
        $stmtAdminAcc->close();
    }
}

// Fetch available projects based on user role
$available_projects = [];
$project_data = null;
$movement_data = [];
$errorMessage = '';

// If batch_id is provided but project_id is not, try to get project_id from the batch
if ($batch_id > 0 && $selected_project_id == 0) {
    $stmtBatch = $conn->prepare("SELECT project_id FROM modules WHERE id = ?");
    if ($stmtBatch) {
        $stmtBatch->bind_param("i", $batch_id);
        $stmtBatch->execute();
        $stmtBatch->bind_result($project_from_batch);
        if ($stmtBatch->fetch()) {
            $selected_project_id = $project_from_batch;
        }
        $stmtBatch->close();
    }
}

try {
    // Get projects based on role
    if ($role === 'global_admin') {
        $stmtProjects = $conn->prepare("
            SELECT p.id, p.project_name, p.street_address, p.city, p.state, p.zip_code, c.name as account_name
            FROM projects p 
            JOIN customer_accounts c ON p.account_id = c.id 
            ORDER BY p.project_name ASC
        ");
    } else if ($role === 'admin' && $account_id_for_admin) {
        $stmtProjects = $conn->prepare("
            SELECT p.id, p.project_name, p.street_address, p.city, p.state, p.zip_code, c.name as account_name
            FROM projects p 
            JOIN customer_accounts c ON p.account_id = c.id 
            WHERE p.account_id = ?
            ORDER BY p.project_name ASC
        ");
        $stmtProjects->bind_param("i", $account_id_for_admin);
    } else {
        throw new Exception("Unable to determine user permissions.");
    }
    
    if ($stmtProjects) {
        $stmtProjects->execute();
        $resultProjects = $stmtProjects->get_result();
        while ($project = $resultProjects->fetch_assoc()) {
            $available_projects[] = $project;
        }
        $stmtProjects->close();
    }

    // If a project is selected, get its movement data
    if ($selected_project_id > 0) {
        // Verify user can access this project
        $can_access = false;
        foreach ($available_projects as $proj) {
            if ($proj['id'] == $selected_project_id) {
                $can_access = true;
                $project_data = $proj;
                break;
            }
        }
        
        if (!$can_access) {
            throw new Exception("Access denied to selected project.");
        }

        // Get pallet movement data for this project
        $stmtMovements = $conn->prepare("
            SELECT DISTINCT
                ip.id as pallet_id,
                ip.pallet_identifier,
                ip.wattage,
                ip.quantity,
                ip.status,
                ip.current_warehouse_id,
                
                -- Module batch info 
                m.vendor_name as manufacturer_name,
                
                -- Try to get manufacturer address by matching name from vendor_name
                mfg.name as manufacturer_company,
                mfg.street_address as mfg_street,
                mfg.city as mfg_city,
                mfg.state as mfg_state,
                mfg.zip_code as mfg_zip,
                
                -- Delivery info (may be NULL for pallets at manufacturer)
                d.id as delivery_id,
                d.bol_number,
                d.supplier,
                d.anticipated_delivery_date,
                d.warehouse_arrival_date,
                d.actual_delivery_date,
                d.status_of_delivery,
                
                -- Warehouse info (delivery destination)
                w.id as delivery_warehouse_id,
                w.name as delivery_warehouse_name,
                w.street_address as delivery_wh_street,
                w.city as delivery_wh_city,
                w.state as delivery_wh_state,
                w.zip_code as delivery_wh_zip,
                
                -- Current warehouse info (where pallet is now)
                w2.id as current_warehouse_id_info,
                w2.name as current_warehouse_name,
                w2.street_address as current_wh_street,
                w2.city as current_wh_city,
                w2.state as current_wh_state,
                w2.zip_code as current_wh_zip,
                
                -- Project info
                p.project_name,
                p.street_address as proj_street,
                p.city as proj_city,
                p.state as proj_state,
                p.zip_code as proj_zip
                
            FROM inventory_pallets ip
            
            -- Link to module batch for manufacturer info
            LEFT JOIN unassigned_module_items umi ON ip.unassigned_module_item_id = umi.id
            LEFT JOIN modules m ON umi.unassigned_module_id = m.id
            
            -- Try to link to manufacturer by matching the first part of vendor_name
            LEFT JOIN manufacturers mfg ON (
                mfg.name = SUBSTRING_INDEX(m.vendor_name, ' - ', 1) 
                OR mfg.short_name = SUBSTRING_INDEX(m.vendor_name, ' - ', 1)
            )
            
            -- Link to project (this ensures we only get pallets for this project)
            JOIN projects p ON m.project_id = p.id
            
            -- LEFT JOIN to deliveries (so we get pallets without deliveries too)
            LEFT JOIN delivery_pallets dp ON ip.id = dp.inventory_pallet_id
            LEFT JOIN deliveries d ON dp.delivery_id = d.id
            
            -- Link to delivery destination warehouses
            LEFT JOIN warehouses w ON d.warehouse_id = w.id
            
            -- Link to current warehouse location
            LEFT JOIN warehouses w2 ON ip.current_warehouse_id = w2.id
            
            WHERE p.id = ?
            ORDER BY ip.status ASC, ip.id ASC
        ");
        
        if ($stmtMovements) {
            $stmtMovements->bind_param("i", $selected_project_id);
            $stmtMovements->execute();
            $resultMovements = $stmtMovements->get_result();
            
            while ($movement = $resultMovements->fetch_assoc()) {
                $movement_data[] = $movement;
            }
            $stmtMovements->close();
        }
        
        // Calculate detailed breakdown similar to module_overview.php
        $detailed_breakdown = [];
        foreach ($movement_data as $movement) {
            $status = $movement['status'];
            $wattage = $movement['wattage'];
            $quantity = $movement['quantity'];
            
            // Create location-specific key for breakdown
            $breakdown_key = '';
            if ($status === 'In Warehouse' && $movement['current_warehouse_name']) {
                $breakdown_key = 'In Warehouse - ' . $movement['current_warehouse_name'];
            } elseif ($status === 'Delivered to Project' && $movement['project_name']) {
                $breakdown_key = 'Delivered to Project - ' . $movement['project_name'];
            } elseif ($status === 'In Transit to Project' && $movement['project_name']) {
                $breakdown_key = 'In Transit to Project - ' . $movement['project_name'];
            } elseif ($status === 'In Transit to Warehouse' && $movement['delivery_warehouse_name']) {
                $breakdown_key = 'In Transit to Warehouse - ' . $movement['delivery_warehouse_name'];
            } else {
                $breakdown_key = $status;
            }
            
            // Initialize if not exists
            if (!isset($detailed_breakdown[$breakdown_key])) {
                $detailed_breakdown[$breakdown_key] = [
                    'pallet_count' => 0,
                    'total_modules' => 0,
                    'wattage_breakdown' => []
                ];
            }
            
            // Update counts
            $detailed_breakdown[$breakdown_key]['pallet_count']++;
            $detailed_breakdown[$breakdown_key]['total_modules'] += $quantity;
            
            // Track wattage breakdown
            if (!isset($detailed_breakdown[$breakdown_key]['wattage_breakdown'][$wattage])) {
                $detailed_breakdown[$breakdown_key]['wattage_breakdown'][$wattage] = [
                    'pallets' => 0,
                    'modules' => 0
                ];
            }
            $detailed_breakdown[$breakdown_key]['wattage_breakdown'][$wattage]['pallets']++;
            $detailed_breakdown[$breakdown_key]['wattage_breakdown'][$wattage]['modules'] += $quantity;
        }
    }

} catch (Exception $e) {
    $errorMessage = $e->getMessage();
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Module Movements</title>
    <link rel="stylesheet" href="portal.css">
    <link rel="icon" href="pictures/favicon.png" type="image/x-icon">
    <link href="https://fonts.googleapis.com/css?family=Poppins:300,400,500,600,700&display=swap" rel="stylesheet">
    <style>
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
        
        .controls-section {
            background-color: #f9f9f9;
            padding: 20px;
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        
        .controls-section h2 {
            margin-top: 0;
            color: #293E4C;
            border-bottom: 2px solid #488C9A;
            padding-bottom: 10px;
            margin-bottom: 15px;
        }
        
        .project-selector {
            display: flex;
            align-items: center;
            gap: 15px;
            flex-wrap: wrap;
        }
        
        .project-selector label {
            font-weight: 600;
            color: #333;
        }
        
        .project-selector select {
            padding: 8px 12px;
            border: 1px solid #ccc;
            border-radius: 4px;
            font-size: 1em;
            min-width: 250px;
        }
        
        .project-selector button {
            padding: 8px 16px;
            background: #488C9A;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-weight: 600;
        }
        
        .project-selector button:hover {
            background: #293E4C;
        }
        
        .project-info {
            background-color: #e8f4f8;
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
        }
        
        .project-info h3 {
            margin-top: 0;
            color: #293E4C;
        }
        
        .map-container {
            width: 100%;
            height: 600px;
            border: 1px solid #ddd;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        
        .map-legend {
            background-color: #f9f9f9;
            padding: 15px;
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        
        .legend-item {
            display: flex;
            align-items: center;
            margin-bottom: 8px;
        }
        
        .legend-marker {
            width: 20px;
            height: 20px;
            border-radius: 50%;
            margin-right: 10px;
        }
        
        .manufacturer-marker { background-color: #3498db; }
        .warehouse-marker { background-color: #f39c12; }
        .project-marker { background-color: #27ae60; }
        
        .movement-summary {
            background-color: #f9f9f9;
            padding: 20px;
            border: 1px solid #e0e0e0;
            border-radius: 8px;
        }
        
        .movement-summary h3 {
            margin-top: 0;
            color: #293E4C;
            border-bottom: 1px solid #ddd;
            padding-bottom: 10px;
        }
        
        .pallet-count {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }
        
        .count-item {
            background: white;
            padding: 15px;
            border-radius: 5px;
            border: 1px solid #ddd;
            text-align: center;
        }
        
        .count-number {
            font-size: 2em;
            font-weight: bold;
            color: #488C9A;
        }
        
        .count-label {
            color: #666;
            font-size: 0.9em;
        }
        
        .error-message {
            color: #721c24;
            background-color: #f8d7da;
            border: 1px solid #f5c6cb;
            padding: 15px;
            border-radius: 4px;
            margin-bottom: 20px;
        }
        
        .no-project-message {
            text-align: center;
            padding: 40px;
            color: #666;
            font-style: italic;
        }
    </style>
</head>
<body>
<?php include 'header.php'; ?>
<main>
    <div class="breadcrumb">
        <a href="<?php echo ($role === 'admin' || $role === 'global_admin') ? 'admin_dashboard.php' : 'dashboard.php'; ?>">Dashboard</a>
        <span class="separator">&raquo;</span>
        <?php if ($batch_id > 0): ?>
            <a href="module_overview.php?batch_id=<?php echo $batch_id; ?>">Module Batch Overview</a>
            <span class="separator">&raquo;</span>
        <?php endif; ?>
        <span>Module Movements</span>
    </div>

    <h1>Module Movement Tracking</h1>

    <?php if (!empty($errorMessage)): ?>
        <div class="error-message">
            <strong>Error:</strong> <?php echo htmlspecialchars($errorMessage); ?>
        </div>
    <?php endif; ?>

    <div class="controls-section">
        <h2>Project Selection</h2>
        <form method="GET" class="project-selector">
            <?php if ($batch_id > 0): ?>
                <input type="hidden" name="batch_id" value="<?php echo $batch_id; ?>">
            <?php endif; ?>
            <label for="project_id">Select Project:</label>
            <select name="project_id" id="project_id" required>
                <option value="">-- Choose a Project --</option>
                <?php foreach ($available_projects as $project): ?>
                    <option value="<?php echo $project['id']; ?>" <?php echo ($project['id'] == $selected_project_id) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($project['project_name']); ?>
                        <?php if ($role === 'global_admin'): ?>
                            (<?php echo htmlspecialchars($project['account_name']); ?>)
                        <?php endif; ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <button type="submit">View Movement Map</button>
        </form>
    </div>

    <?php if ($selected_project_id > 0 && $project_data): ?>
        <div class="project-info">
            <h3><?php echo htmlspecialchars($project_data['project_name']); ?></h3>
            <p><strong>Location:</strong> 
                <?php 
                $address_parts = array_filter([
                    $project_data['street_address'],
                    $project_data['city'],
                    $project_data['state'],
                    $project_data['zip_code']
                ]);
                echo htmlspecialchars(implode(', ', $address_parts));
                ?>
            </p>
            <?php if ($role === 'global_admin'): ?>
                <p><strong>Account:</strong> <?php echo htmlspecialchars($project_data['account_name']); ?></p>
            <?php endif; ?>
        </div>

        <?php if (!empty($movement_data)): ?>
            <!-- Map Legend and Status Breakdown Container -->
            <div style="display: flex; gap: 20px; margin-bottom: 20px;">
                <!-- Map Legend -->
                <div class="map-legend" style="flex: 1; margin-bottom: 0;">
                    <h3>Map Legend</h3>
                    <div class="legend-item">
                        <div class="legend-marker manufacturer-marker"></div>
                        <span>Manufacturer (Starting Point)</span>
                    </div>
                    <div class="legend-item">
                        <div class="legend-marker warehouse-marker"></div>
                        <span>Warehouse (Intermediate Stop)</span>
                    </div>
                    <div class="legend-item">
                        <div class="legend-marker project-marker"></div>
                        <span>Project Site (Final Destination)</span>
                    </div>
                </div>

                <!-- Pallet Status Breakdown -->
                <div class="movement-summary" style="flex: 1; margin-bottom: 0;">
                    <h3>Pallet Status Breakdown</h3>
                    <?php 
                    // Calculate totals for each main status
                    $status_totals = [
                        'At Manufacturer' => ['pallets' => 0, 'modules' => 0],
                        'In Warehouse' => ['pallets' => 0, 'modules' => 0],
                        'Delivered to Project' => ['pallets' => 0, 'modules' => 0]
                    ];
                    
                    // NEW: Check if any modules ever went through a warehouse
                    $warehouse_ever_used = false;
                    foreach ($movement_data as $movement) {
                        if (strpos($movement['status'], 'Warehouse') !== false || 
                            !empty($movement['current_warehouse_id_info']) ||
                            !empty($movement['delivery_warehouse_id'])) {
                            $warehouse_ever_used = true;
                            break;
                        }
                    }
                    // Also check detailed breakdown for any warehouse-related statuses
                    if (!$warehouse_ever_used && !empty($detailed_breakdown)) {
                        foreach ($detailed_breakdown as $status => $data) {
                            if (strpos($status, 'Warehouse') !== false) {
                                $warehouse_ever_used = true;
                                break;
                            }
                        }
                    }
                    
                    // Process the detailed breakdown to get totals
                    if (!empty($detailed_breakdown)) {
                        foreach ($detailed_breakdown as $status => $data) {
                            if (strpos($status, 'At Manufacturer') !== false) {
                                $status_totals['At Manufacturer']['pallets'] += $data['pallet_count'];
                                $status_totals['At Manufacturer']['modules'] += $data['total_modules'];
                            } elseif (strpos($status, 'In Warehouse') !== false) {
                                $status_totals['In Warehouse']['pallets'] += $data['pallet_count'];
                                $status_totals['In Warehouse']['modules'] += $data['total_modules'];
                            } elseif (strpos($status, 'Delivered to Project') !== false) {
                                $status_totals['Delivered to Project']['pallets'] += $data['pallet_count'];
                                $status_totals['Delivered to Project']['modules'] += $data['total_modules'];
                            }
                        }
                    }
                    ?>
                    
                    <div style="display: flex; justify-content: space-between; gap: 15px; align-items: center;">
                        <!-- At Manufacturer -->
                        <div onclick="showDetailedBreakdown('manufacturer')" style="flex: 1; text-align: center; padding: 12px; background-color: #e3f2fd; border-radius: 8px; border: 2px solid #3498db; cursor: pointer; transition: transform 0.2s;" onmouseover="this.style.transform='scale(1.02)'" onmouseout="this.style.transform='scale(1)'">
                            <div style="font-weight: 600; color: #1565c0; margin-bottom: 6px; font-size: 0.9em;">
                                📍 At Manufacturer
                            </div>
                            <div style="color: #1976d2; font-size: 1.1em; font-weight: bold;">
                                <?php echo $status_totals['At Manufacturer']['pallets']; ?> pallets
                            </div>
                            <div style="color: #666; font-size: 0.85em;">
                                <?php echo number_format($status_totals['At Manufacturer']['modules']); ?> modules
                            </div>
                        </div>
                        
                        <?php if ($warehouse_ever_used): ?>
                            <!-- Arrow -->
                            <div style="color: #666; font-size: 1.5em;">→</div>
                            
                            <!-- In Warehouse -->
                            <div onclick="showDetailedBreakdown('warehouse')" style="flex: 1; text-align: center; padding: 12px; background-color: #fff3e0; border-radius: 8px; border: 2px solid #f39c12; cursor: pointer; transition: transform 0.2s;" onmouseover="this.style.transform='scale(1.02)'" onmouseout="this.style.transform='scale(1)'">
                                <div style="font-weight: 600; color: #e65100; margin-bottom: 6px; font-size: 0.9em;">
                                    🏢 In Warehouse
                                </div>
                                <div style="color: #f57c00; font-size: 1.1em; font-weight: bold;">
                                    <?php echo $status_totals['In Warehouse']['pallets']; ?> pallets
                                </div>
                                <div style="color: #666; font-size: 0.85em;">
                                    <?php echo number_format($status_totals['In Warehouse']['modules']); ?> modules
                                </div>
                            </div>
                        <?php endif; ?>
                        
                        <!-- Arrow -->
                        <div style="color: #666; font-size: 1.5em;">→</div>
                        
                        <!-- Delivered to Project -->
                        <div onclick="showDetailedBreakdown('project')" style="flex: 1; text-align: center; padding: 12px; background-color: #e8f5e8; border-radius: 8px; border: 2px solid #27ae60; cursor: pointer; transition: transform 0.2s;" onmouseover="this.style.transform='scale(1.02)'" onmouseout="this.style.transform='scale(1)'">
                            <div style="font-weight: 600; color: #1b5e20; margin-bottom: 6px; font-size: 0.9em;">
                                🎯 Delivered to Project
                            </div>
                            <div style="color: #2e7d32; font-size: 1.1em; font-weight: bold;">
                                <?php echo $status_totals['Delivered to Project']['pallets']; ?> pallets
                            </div>
                            <div style="color: #666; font-size: 0.85em;">
                                <?php echo number_format($status_totals['Delivered to Project']['modules']); ?> modules
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Google Map -->
            <div id="map" class="map-container"></div>
        <?php else: ?>
            <div class="no-project-message">
                <p>No pallet movement data found for this project.</p>
                <p>Pallets may not have been created or shipped yet.</p>
            </div>
        <?php endif; ?>
    <?php elseif ($selected_project_id > 0): ?>
        <div class="no-project-message">
            <p>Selected project not found or you don't have access to it.</p>
        </div>
    <?php else: ?>
        <div class="no-project-message">
            <p>Please select a project above to view its module movement map.</p>
        </div>
    <?php endif; ?>

</main>

<!-- Detailed Breakdown Modal -->
<div id="detailModal" style="display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; overflow: auto; background-color: rgba(0,0,0,0.5);">
    <div style="background-color: #fefefe; margin: 5% auto; padding: 30px; border: 1px solid #888; width: 80%; max-width: 600px; border-radius: 8px; position: relative;">
        <span onclick="closeDetailModal()" style="color: #aaa; position: absolute; top: 15px; right: 25px; font-size: 28px; font-weight: bold; cursor: pointer;">&times;</span>
        <h2 id="modalTitle" style="margin-top: 0; color: #293E4C; border-bottom: 2px solid #488C9A; padding-bottom: 10px;"></h2>
        <div id="modalContent"></div>
    </div>
</div>

<!-- Load Google Maps JavaScript API -->
<script src="https://maps.googleapis.com/maps/api/js?key=AIzaSyCYF3qz_6niMzpTd0yklUX9YNpk73KviBM&libraries=places"></script>

<script>
// Movement data from PHP
const movementData = <?php echo json_encode($movement_data); ?>;
const projectData = <?php echo json_encode($project_data); ?>;
const detailedBreakdown = <?php echo json_encode($detailed_breakdown); ?>;

let map;
let directionsService;
let directionsRenderer;

function initMap() {
    if (!projectData || movementData.length === 0) {
        return;
    }

    // Initialize map centered on the US
    map = new google.maps.Map(document.getElementById('map'), {
        zoom: 4,
        center: { lat: 39.8283, lng: -98.5795 }, // Center of US
        mapTypeId: 'roadmap'
    });

    directionsService = new google.maps.DirectionsService();
    directionsRenderer = new google.maps.DirectionsRenderer({
        suppressMarkers: true, // We'll add custom markers
        polylineOptions: {
            strokeColor: '#488C9A',
            strokeWeight: 3,
            strokeOpacity: 0.8
        }
    });
    directionsRenderer.setMap(map);

    // Process movement data to create markers and routes
    processMovementData();
}

function processMovementData() {
    const locations = new Map();
    const routes = [];
    
    // Process each pallet's movement
    movementData.forEach(movement => {
        // Add manufacturer location for ALL pallets that have manufacturer info (regardless of current status)
        if (movement.manufacturer_name) {
            const mfgKey = 'mfg_' + movement.manufacturer_name;
            if (!locations.has(mfgKey)) {
                // Use manufacturer company name if available, otherwise extract from vendor_name
                const manufacturerName = movement.manufacturer_company || movement.manufacturer_name.split(' - ')[0] || movement.manufacturer_name;
                const manufacturerAddress = buildAddress(movement.mfg_street, movement.mfg_city, movement.mfg_state, movement.mfg_zip);
                
                locations.set(mfgKey, {
                    type: 'manufacturer',
                    name: manufacturerName,
                    address: manufacturerAddress || 'Manufacturer Location', // Fallback if no address
                    pallets: [],
                    marker: null
                });
            }
            // Only add pallets to manufacturer location if they're currently AT manufacturer
            if (movement.status === 'At Manufacturer') {
                locations.get(mfgKey).pallets.push(movement);
            }
        }

        // Add warehouse location with pallets currently IN warehouse
        if (movement.status === 'In Warehouse' && movement.current_warehouse_id_info && movement.current_warehouse_name) {
            const whKey = 'wh_' + movement.current_warehouse_id_info;
            if (!locations.has(whKey)) {
                locations.set(whKey, {
                    type: 'warehouse',
                    name: movement.current_warehouse_name,
                    address: buildAddress(movement.current_wh_street, movement.current_wh_city, movement.current_wh_state, movement.current_wh_zip),
                    pallets: [],
                    marker: null
                });
            }
            locations.get(whKey).pallets.push(movement);
        }

        // Add project location with pallets DELIVERED to project
        if (movement.status === 'Delivered to Project') {
            const projectKey = 'proj_' + projectData.id;
            if (!locations.has(projectKey)) {
                locations.set(projectKey, {
                    type: 'project',
                    name: projectData.project_name,
                    address: buildAddress(projectData.street_address, projectData.city, projectData.state, projectData.zip_code),
                    pallets: [],
                    marker: null
                });
            }
            locations.get(projectKey).pallets.push(movement);
        }
    });

    // Always add project location even if no pallets delivered yet (for future reference)
    const projectKey = 'proj_' + projectData.id;
    if (!locations.has(projectKey)) {
        locations.set(projectKey, {
            type: 'project',
            name: projectData.project_name,
            address: buildAddress(projectData.street_address, projectData.city, projectData.state, projectData.zip_code),
            pallets: [],
            marker: null
        });
    }

    // Geocode and create markers for all locations
    const geocoder = new google.maps.Geocoder();
    const bounds = new google.maps.LatLngBounds();
    let geocodePromises = [];

    locations.forEach((location, key) => {
        if (location.address) {
            const promise = geocodeAddress(geocoder, location.address)
                .then(position => {
                    if (position) {
                        location.position = position;
                        createMarker(location);
                        bounds.extend(position);
                    }
                })
                .catch(error => {
                    console.error('Geocoding failed for:', location.name, error);
                });
            geocodePromises.push(promise);
        }
    });

    // After all geocoding is complete, fit map to bounds
    Promise.all(geocodePromises).then(() => {
        if (!bounds.isEmpty()) {
            map.fitBounds(bounds);
            
            // Ensure minimum zoom level
            google.maps.event.addListenerOnce(map, 'bounds_changed', function() {
                if (map.getZoom() > 15) {
                    map.setZoom(15);
                }
            });
        }
        
        // Prevent marker overlap by adjusting positions
        adjustMarkerPositions(locations);
        
        // Create route lines after markers are positioned
        createRouteLines(locations);
    });
}

function buildAddress(street, city, state, zip) {
    const parts = [street, city, state, zip].filter(part => part && part.trim() !== '');
    return parts.join(', ');
}

function geocodeAddress(geocoder, address) {
    return new Promise((resolve, reject) => {
        geocoder.geocode({ address: address }, (results, status) => {
            if (status === 'OK' && results[0]) {
                resolve(results[0].geometry.location);
            } else {
                reject(new Error('Geocoding failed: ' + status));
            }
        });
    });
}

function createMarker(location) {
    if (!location.position) return;

    let icon;
    const iconSize = 40; // Larger, more prominent bubble-style markers
    
    switch (location.type) {
        case 'manufacturer':
            icon = {
                url: 'data:image/svg+xml;charset=UTF-8,' + encodeURIComponent(
                    '<svg width="' + iconSize + '" height="' + iconSize + '" viewBox="0 0 40 40" xmlns="http://www.w3.org/2000/svg">' +
                    '<defs>' +
                    '<filter id="shadow" x="-50%" y="-50%" width="200%" height="200%">' +
                    '<feDropShadow dx="0" dy="2" stdDeviation="3" flood-opacity="0.3"/>' +
                    '</filter>' +
                    '</defs>' +
                    '<circle cx="20" cy="20" r="18" fill="#3498db" stroke="#FFFFFF" stroke-width="3" filter="url(#shadow)"/>' +
                    '<text x="20" y="26" text-anchor="middle" fill="white" font-size="14" font-weight="bold" font-family="Arial">M</text>' +
                    '</svg>'
                ),
                scaledSize: new google.maps.Size(iconSize, iconSize),
                anchor: new google.maps.Point(iconSize/2, iconSize/2)
            };
            break;
        case 'warehouse':
            icon = {
                url: 'data:image/svg+xml;charset=UTF-8,' + encodeURIComponent(
                    '<svg width="' + iconSize + '" height="' + iconSize + '" viewBox="0 0 40 40" xmlns="http://www.w3.org/2000/svg">' +
                    '<defs>' +
                    '<filter id="shadow" x="-50%" y="-50%" width="200%" height="200%">' +
                    '<feDropShadow dx="0" dy="2" stdDeviation="3" flood-opacity="0.3"/>' +
                    '</filter>' +
                    '</defs>' +
                    '<circle cx="20" cy="20" r="18" fill="#f39c12" stroke="#FFFFFF" stroke-width="3" filter="url(#shadow)"/>' +
                    '<text x="20" y="26" text-anchor="middle" fill="white" font-size="14" font-weight="bold" font-family="Arial">W</text>' +
                    '</svg>'
                ),
                scaledSize: new google.maps.Size(iconSize, iconSize),
                anchor: new google.maps.Point(iconSize/2, iconSize/2)
            };
            break;
        case 'project':
            icon = {
                url: 'data:image/svg+xml;charset=UTF-8,' + encodeURIComponent(
                    '<svg width="' + iconSize + '" height="' + iconSize + '" viewBox="0 0 40 40" xmlns="http://www.w3.org/2000/svg">' +
                    '<defs>' +
                    '<filter id="shadow" x="-50%" y="-50%" width="200%" height="200%">' +
                    '<feDropShadow dx="0" dy="2" stdDeviation="3" flood-opacity="0.3"/>' +
                    '</filter>' +
                    '</defs>' +
                    '<circle cx="20" cy="20" r="18" fill="#27ae60" stroke="#FFFFFF" stroke-width="3" filter="url(#shadow)"/>' +
                    '<text x="20" y="26" text-anchor="middle" fill="white" font-size="14" font-weight="bold" font-family="Arial">P</text>' +
                    '</svg>'
                ),
                scaledSize: new google.maps.Size(iconSize, iconSize),
                anchor: new google.maps.Point(iconSize/2, iconSize/2)
            };
            break;
    }

    const marker = new google.maps.Marker({
        position: location.position,
        map: map,
        title: location.name,
        icon: icon,
        zIndex: 20 // Ensure markers are on top
    });

    // Create enhanced info window with current quantities and wattages
    const totalPallets = location.pallets.length;
    const totalModules = location.pallets.reduce((sum, pallet) => sum + parseInt(pallet.quantity), 0);
    const wattages = [...new Set(location.pallets.map(p => p.wattage))].sort();
    const wattageBreakdown = {};
    
    // Calculate quantities by wattage
    location.pallets.forEach(pallet => {
        const wattage = pallet.wattage;
        if (!wattageBreakdown[wattage]) {
            wattageBreakdown[wattage] = 0;
        }
        wattageBreakdown[wattage] += parseInt(pallet.quantity);
    });

    const wattageDetails = Object.keys(wattageBreakdown)
        .sort()
        .map(w => `${w}W: ${wattageBreakdown[w].toLocaleString()} modules`)
        .join('<br>');

    const infoContent = `
        <div style="max-width: 300px; font-family: 'Poppins', Arial, sans-serif;">
            <h3 style="margin-top: 0; color: #293E4C; font-size: 18px;">${location.name}</h3>
            <p style="margin: 8px 0; color: #666;"><strong>Type:</strong> ${location.type.charAt(0).toUpperCase() + location.type.slice(1)}</p>
            <p style="margin: 8px 0; color: #666; font-size: 14px;"><strong>Address:</strong> ${location.address}</p>
            <hr style="margin: 15px 0; border: none; border-top: 1px solid #eee;">
            <div style="background: #f8f9fa; padding: 12px; border-radius: 6px; margin: 10px 0;">
                <p style="margin: 0 0 8px 0; font-weight: 600; color: #293E4C;">Current Inventory:</p>
                <p style="margin: 4px 0; color: #333;"><strong>Pallets:</strong> ${totalPallets.toLocaleString()}</p>
                <p style="margin: 4px 0; color: #333;"><strong>Total Modules:</strong> ${totalModules.toLocaleString()}</p>
                ${wattageDetails ? `<p style="margin: 8px 0 0 0; color: #666; font-size: 13px;"><strong>Breakdown:</strong><br>${wattageDetails}</p>` : ''}
            </div>
        </div>
    `;

    const infoWindow = new google.maps.InfoWindow({
        content: infoContent
    });

    marker.addListener('click', () => {
        infoWindow.open(map, marker);
    });

    location.marker = marker;
}

function adjustMarkerPositions(locations) {
    const markerPositions = [];
    const minDistance = 0.2; // Even more separation for clean bubble layout
    
    // Collect all markers with their positions
    locations.forEach((location, key) => {
        if (location.marker && location.position) {
            markerPositions.push({
                key: key,
                location: location,
                lat: location.position.lat(),
                lng: location.position.lng(),
                originalLat: location.position.lat(),
                originalLng: location.position.lng()
            });
        }
    });
    
    // Check for overlaps and create clean bubble layout
    for (let i = 0; i < markerPositions.length; i++) {
        for (let j = i + 1; j < markerPositions.length; j++) {
            const marker1 = markerPositions[i];
            const marker2 = markerPositions[j];
            
            const distance = Math.sqrt(
                Math.pow(marker1.lat - marker2.lat, 2) + 
                Math.pow(marker1.lng - marker2.lng, 2)
            );
            
            if (distance < minDistance) {
                // Create a clean radial layout around the center point
                const centerLat = (marker1.originalLat + marker2.originalLat) / 2;
                const centerLng = (marker1.originalLng + marker2.originalLng) / 2;
                
                const offsetDistance = minDistance * 0.8;
                
                // Position markers in a clean circle around center
                const angle1 = (i * Math.PI * 2) / markerPositions.length;
                const angle2 = (j * Math.PI * 2) / markerPositions.length;
                
                marker1.lat = centerLat + Math.sin(angle1) * offsetDistance;
                marker1.lng = centerLng + Math.cos(angle1) * offsetDistance;
                
                marker2.lat = centerLat + Math.sin(angle2) * offsetDistance;
                marker2.lng = centerLng + Math.cos(angle2) * offsetDistance;
                
                // Update marker positions
                const newPosition1 = new google.maps.LatLng(marker1.lat, marker1.lng);
                const newPosition2 = new google.maps.LatLng(marker2.lat, marker2.lng);
                
                marker1.location.marker.setPosition(newPosition1);
                marker1.location.position = newPosition1;
                
                marker2.location.marker.setPosition(newPosition2);
                marker2.location.position = newPosition2;
            }
        }
    }
    
    // Add clean pointer lines from displaced markers back to their actual locations
    markerPositions.forEach(markerPos => {
        const wasDisplaced = (markerPos.lat !== markerPos.originalLat || markerPos.lng !== markerPos.originalLng);
        
        if (wasDisplaced) {
            const originalPosition = new google.maps.LatLng(markerPos.originalLat, markerPos.originalLng);
            const currentPosition = new google.maps.LatLng(markerPos.lat, markerPos.lng);
            
            // Create a clean, simple pointer line (like in the examples)
            const pointerLine = new google.maps.Polyline({
                path: [currentPosition, originalPosition],
                geodesic: false,
                strokeColor: '#FFFFFF', // White line for clean look
                strokeOpacity: 0.9,
                strokeWeight: 3,
                map: map,
                zIndex: 8 // Above routes but below markers
            });
            
            // Add a subtle shadow/outline to the pointer line
            const shadowLine = new google.maps.Polyline({
                path: [currentPosition, originalPosition],
                geodesic: false,
                strokeColor: '#000000',
                strokeOpacity: 0.3,
                strokeWeight: 5,
                map: map,
                zIndex: 7 // Behind the white line
            });
            
            // Add a small dot at the actual location (cleaner than the previous version)
            const actualLocationDot = new google.maps.Marker({
                position: originalPosition,
                map: map,
                icon: {
                    url: 'data:image/svg+xml;charset=UTF-8,' + encodeURIComponent(
                        '<svg width="12" height="12" viewBox="0 0 12 12" xmlns="http://www.w3.org/2000/svg">' +
                        '<circle cx="6" cy="6" r="4" fill="#FFFFFF" stroke="#333333" stroke-width="2"/>' +
                        '<circle cx="6" cy="6" r="2" fill="#333333"/>' +
                        '</svg>'
                    ),
                    scaledSize: new google.maps.Size(12, 12),
                    anchor: new google.maps.Point(6, 6)
                },
                title: `Exact location: ${markerPos.location.name}`,
                zIndex: 15 // Highest priority
            });
            
            // Store references
            markerPos.location.pointerLine = pointerLine;
            markerPos.location.shadowLine = shadowLine;
            markerPos.location.actualLocationDot = actualLocationDot;
        }
    });
}

// Add route visualization after all markers are created
function createRouteLines(locations) {
    if (!map) return;
    
    const routes = new Map();
    
    // Analyze movement patterns to create routes based on delivery history
    movementData.forEach(movement => {
        const manufacturerKey = 'mfg_' + movement.manufacturer_name;
        const projectKey = 'proj_' + projectData.id;
        
        // Determine warehouse key based on where pallet was delivered or is currently stored
        let warehouseKey = null;
        if (movement.current_warehouse_id_info && movement.current_warehouse_name) {
            warehouseKey = 'wh_' + movement.current_warehouse_id_info;
        } else if (movement.delivery_warehouse_id && movement.delivery_warehouse_name) {
            warehouseKey = 'wh_' + movement.delivery_warehouse_id;
        }
        
        // Create manufacturer → warehouse route (for pallets that are currently in warehouse or were delivered to project via warehouse)
        if (warehouseKey && (movement.status === 'In Warehouse' || movement.status === 'Delivered to Project')) {
            const route1Key = `${manufacturerKey}_to_${warehouseKey}`;
            if (!routes.has(route1Key)) {
                routes.set(route1Key, {
                    from: manufacturerKey,
                    to: warehouseKey,
                    pallets: [],
                    modules: 0,
                    color: '#488C9A', // Blue for manufacturer → warehouse
                    type: 'manufacturer_to_warehouse'
                });
            }
            routes.get(route1Key).pallets.push(movement);
            routes.get(route1Key).modules += parseInt(movement.quantity);
        }
        
        // Create warehouse → project route (for pallets delivered to project)
        if (warehouseKey && movement.status === 'Delivered to Project') {
            const route2Key = `${warehouseKey}_to_${projectKey}`;
            if (!routes.has(route2Key)) {
                routes.set(route2Key, {
                    from: warehouseKey,
                    to: projectKey,
                    pallets: [],
                    modules: 0,
                    color: '#27ae60', // Green for warehouse → project
                    type: 'warehouse_to_project'
                });
            }
            routes.get(route2Key).pallets.push(movement);
            routes.get(route2Key).modules += parseInt(movement.quantity);
        }
        
        // Create direct manufacturer → project route (for pallets that skipped warehouse)
        if (!warehouseKey && movement.status === 'Delivered to Project') {
            const directRouteKey = `${manufacturerKey}_to_${projectKey}`;
            if (!routes.has(directRouteKey)) {
                routes.set(directRouteKey, {
                    from: manufacturerKey,
                    to: projectKey,
                    pallets: [],
                    modules: 0,
                    color: '#e74c3c', // Red for direct routes
                    type: 'manufacturer_to_project_direct'
                });
            }
            routes.get(directRouteKey).pallets.push(movement);
            routes.get(directRouteKey).modules += parseInt(movement.quantity);
        }
    });
    
    // Create polylines for each route
    routes.forEach((route, routeKey) => {
        const fromLocation = locations.get(route.from);
        const toLocation = locations.get(route.to);
        
        if (fromLocation && toLocation && fromLocation.position && toLocation.position && route.modules > 0) {
            // Calculate the total modules in the project to determine volume percentage
            const totalProjectModules = movementData.reduce((sum, movement) => sum + parseInt(movement.quantity), 0);
            const volumePercentage = route.modules / totalProjectModules;
            
            // Determine dash pattern based on volume (4 levels + solid)
            let strokePattern = null; // Default is solid line
            
            if (volumePercentage >= 0.9) {
                // Very high volume: solid line
                strokePattern = null;
            } else if (volumePercentage >= 0.65) {
                // High volume: mostly solid with tiny gaps
                strokePattern = [25, 2]; // 25px dash, 2px gap
            } else if (volumePercentage >= 0.35) {
                // Medium volume: moderately solid
                strokePattern = [20, 4]; // 20px dash, 4px gap
            } else if (volumePercentage >= 0.15) {
                // Low volume: more dashed
                strokePattern = [12, 8]; // 12px dash, 8px gap
            } else {
                // Very low volume: heavily dashed
                strokePattern = [8, 12]; // 8px dash, 12px gap
            }
            
            console.log(`Creating route from ${fromLocation.name} to ${toLocation.name}: ${route.modules} modules (${Math.round(volumePercentage * 100)}% of total), pattern: ${strokePattern ? strokePattern.join(',') : 'solid'}`);
            
            const polylineOptions = {
                path: [fromLocation.position, toLocation.position],
                geodesic: true,
                strokeColor: route.color,
                strokeOpacity: 0.8,
                strokeWeight: 4, // Fixed width for all lines
                map: map,
                zIndex: 10
            };
            
            // Add dash pattern if not solid
            if (strokePattern) {
                // Create a dashed line using symbols
                polylineOptions.strokeOpacity = 0.1; // Very faint base line
                polylineOptions.icons = [
                    {
                        icon: {
                            path: 'M 0,-2 0,2',
                            strokeOpacity: 1,
                            strokeWeight: 4,
                            strokeColor: route.color
                        },
                        offset: '0',
                        repeat: (strokePattern[0] + strokePattern[1]) + 'px'
                    }
                ];
            } else {
                // Solid line - no changes needed
            }
            
            const polyline = new google.maps.Polyline(polylineOptions);
            
            // Add hover info window for routes
            const routeInfoWindow = new google.maps.InfoWindow();
            
            polyline.addListener('mouseover', (event) => {
                const routeTypeLabel = route.type === 'manufacturer_to_warehouse' ? 'Manufacturer → Warehouse' :
                                     route.type === 'warehouse_to_project' ? 'Warehouse → Project' :
                                     'Manufacturer → Project (Direct)';
                
                const routeInfo = `
                    <div style="padding: 10px; min-width: 200px;">
                        <strong style="color: ${route.color};">${routeTypeLabel}</strong><br>
                        <strong>From:</strong> ${fromLocation.name}<br>
                        <strong>To:</strong> ${toLocation.name}<br>
                        <strong>Pallets Moved:</strong> ${route.pallets.length.toLocaleString()}<br>
                        <strong>Modules Moved:</strong> ${route.modules.toLocaleString()}<br>
                        <em>Line width represents volume</em>
                    </div>
                `;
                routeInfoWindow.setContent(routeInfo);
                routeInfoWindow.setPosition(event.latLng);
                routeInfoWindow.open(map);
            });
            
            polyline.addListener('mouseout', () => {
                routeInfoWindow.close();
            });
            
            // Store route reference for debugging
            route.polyline = polyline;
        }
    });
    
    console.log(`Created ${routes.size} routes:`, routes);
}

// Initialize map when page loads
google.maps.event.addDomListener(window, 'load', initMap);

// Modal functions
function showDetailedBreakdown(type) {
    const modal = document.getElementById('detailModal');
    const title = document.getElementById('modalTitle');
    const content = document.getElementById('modalContent');
    
    let titleText = '';
    let contentHtml = '';
    
    if (type === 'manufacturer') {
        titleText = '📍 At Manufacturer - Detailed Breakdown';
        contentHtml = generateManufacturerBreakdown();
    } else if (type === 'warehouse') {
        titleText = '🏢 In Warehouse - Detailed Breakdown';
        contentHtml = generateWarehouseBreakdown();
    } else if (type === 'project') {
        titleText = '🎯 Delivered to Project - Detailed Breakdown';
        contentHtml = generateProjectBreakdown();
    }
    
    title.textContent = titleText;
    content.innerHTML = contentHtml;
    modal.style.display = 'block';
}

function closeDetailModal() {
    document.getElementById('detailModal').style.display = 'none';
}

function generateManufacturerBreakdown() {
    let html = '<div style="max-height: 400px; overflow-y: auto;">';
    let hasData = false;
    
    Object.keys(detailedBreakdown).forEach(status => {
        if (status.includes('At Manufacturer')) {
            hasData = true;
            const data = detailedBreakdown[status];
            html += `
                <div style="margin-bottom: 20px; padding: 15px; background-color: #f8f9fa; border-radius: 8px; border-left: 4px solid #3498db;">
                    <h4 style="margin-top: 0; color: #1565c0;">${status}</h4>
                    <p><strong>Total:</strong> ${data.pallet_count} pallets, ${data.total_modules.toLocaleString()} modules</p>
            `;
            
            if (data.wattage_breakdown && Object.keys(data.wattage_breakdown).length > 0) {
                html += '<p><strong>Wattage Breakdown:</strong></p><ul>';
                Object.keys(data.wattage_breakdown).forEach(wattage => {
                    const wattData = data.wattage_breakdown[wattage];
                    html += `<li>${wattage}W: ${wattData.pallets} pallets (${wattData.modules.toLocaleString()} modules)</li>`;
                });
                html += '</ul>';
            }
            html += '</div>';
        }
    });
    
    if (!hasData) {
        html += '<p style="text-align: center; color: #666; font-style: italic;">No modules currently at manufacturer.</p>';
    }
    
    html += '</div>';
    return html;
}

function generateWarehouseBreakdown() {
    let html = '<div style="max-height: 400px; overflow-y: auto;">';
    let hasData = false;
    
    Object.keys(detailedBreakdown).forEach(status => {
        if (status.includes('In Warehouse')) {
            hasData = true;
            const data = detailedBreakdown[status];
            html += `
                <div style="margin-bottom: 20px; padding: 15px; background-color: #f8f9fa; border-radius: 8px; border-left: 4px solid #f39c12;">
                    <h4 style="margin-top: 0; color: #e65100;">${status}</h4>
                    <p><strong>Total:</strong> ${data.pallet_count} pallets, ${data.total_modules.toLocaleString()} modules</p>
            `;
            
            if (data.wattage_breakdown && Object.keys(data.wattage_breakdown).length > 0) {
                html += '<p><strong>Wattage Breakdown:</strong></p><ul>';
                Object.keys(data.wattage_breakdown).forEach(wattage => {
                    const wattData = data.wattage_breakdown[wattage];
                    html += `<li>${wattage}W: ${wattData.pallets} pallets (${wattData.modules.toLocaleString()} modules)</li>`;
                });
                html += '</ul>';
            }
            html += '</div>';
        }
    });
    
    if (!hasData) {
        html += '<p style="text-align: center; color: #666; font-style: italic;">No modules currently in warehouse.</p>';
    }
    
    html += '</div>';
    return html;
}

function generateProjectBreakdown() {
    let html = '<div style="max-height: 400px; overflow-y: auto;">';
    let hasData = false;
    
    Object.keys(detailedBreakdown).forEach(status => {
        if (status.includes('Delivered to Project')) {
            hasData = true;
            const data = detailedBreakdown[status];
            html += `
                <div style="margin-bottom: 20px; padding: 15px; background-color: #f8f9fa; border-radius: 8px; border-left: 4px solid #27ae60;">
                    <h4 style="margin-top: 0; color: #1b5e20;">${status}</h4>
                    <p><strong>Total:</strong> ${data.pallet_count} pallets, ${data.total_modules.toLocaleString()} modules</p>
            `;
            
            if (data.wattage_breakdown && Object.keys(data.wattage_breakdown).length > 0) {
                html += '<p><strong>Wattage Breakdown:</strong></p><ul>';
                Object.keys(data.wattage_breakdown).forEach(wattage => {
                    const wattData = data.wattage_breakdown[wattage];
                    html += `<li>${wattage}W: ${wattData.pallets} pallets (${wattData.modules.toLocaleString()} modules)</li>`;
                });
                html += '</ul>';
            }
            html += '</div>';
        }
    });
    
    if (!hasData) {
        html += '<p style="text-align: center; color: #666; font-style: italic;">No modules have been delivered to project yet.</p>';
    }
    
    html += '</div>';
    return html;
}

// Close modal when clicking outside of it
window.onclick = function(event) {
    const modal = document.getElementById('detailModal');
    if (event.target === modal) {
        closeDetailModal();
    }
}
</script>
</body>
</html> 