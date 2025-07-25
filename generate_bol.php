<?php
session_name("logistics_session");
session_start();

// 1) Verify login
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

// 2) Verify user is admin or global_admin
if (!in_array($_SESSION['role'], ['admin', 'global_admin'])) {
    die("Access denied. You must be an admin to view this page.");
}

require __DIR__ . '/vendor/autoload.php';
use Dompdf\Dompdf;
use Dompdf\Options;

// Database connection
require_once '../config.php'; 
$conn = getDBConnection();
if (!$conn) {
    die("Connection failed");
}

$role = $_SESSION['role'];
$user_id = $_SESSION['user_id'];

// Account access control for admin users
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

// Check if we have delivery_ids parameter
if (!isset($_GET['delivery_ids']) || empty($_GET['delivery_ids'])) {
    die("No delivery IDs provided. Please access this page from the shipment creation process.");
}

$delivery_ids_param = $_GET['delivery_ids'];
$delivery_ids = array_map('intval', explode(',', $delivery_ids_param));

if (empty($delivery_ids)) {
    die("Invalid delivery IDs provided.");
}

// Get project_id for breadcrumb navigation (from URL or session)
$project_id_for_breadcrumb = 0;
if (isset($_GET['project_id']) && $_GET['project_id'] > 0) {
    $project_id_for_breadcrumb = intval($_GET['project_id']);
} elseif (isset($_SESSION['shipment_origin_project_id']) && $_SESSION['shipment_origin_project_id'] > 0) {
    $project_id_for_breadcrumb = $_SESSION['shipment_origin_project_id'];
}

// Create placeholders for IN clause
$placeholders = implode(',', array_fill(0, count($delivery_ids), '?'));

// Prepare parameters array - delivery_ids will be used twice (subquery + main query)
$params = array_merge($delivery_ids, $delivery_ids);
$types = str_repeat('i', count($delivery_ids) * 2);

// Query to get delivery information with related data
$sql = "
    SELECT 
        d.*,
        p.project_name,
        p.street_address as project_street,
        p.city as project_city,
        p.state as project_state,
        p.zip_code as project_zip,
        w.name as warehouse_name,
        w.street_address as warehouse_street,
        w.city as warehouse_city,
        w.state as warehouse_state,
        w.zip_code as warehouse_zip,
        CASE 
            WHEN ml.location_name IS NOT NULL AND m.name IS NOT NULL THEN CONCAT(m.name, ' - ', ml.location_name)
            ELSE COALESCE(ml.location_name, m.name, d.supplier)
        END as manufacturer_name,
        COALESCE(ml.street_address, m.street_address) as manufacturer_street,
        COALESCE(ml.city, m.city) as manufacturer_city,
        COALESCE(ml.state, m.state) as manufacturer_state,
        COALESCE(ml.zip_code, m.zip_code) as manufacturer_zip
    FROM deliveries d
    LEFT JOIN projects p ON d.project_id = p.id
    LEFT JOIN warehouses w ON d.warehouse_id = w.id
    -- Get manufacturer location from any pallet in this delivery
    LEFT JOIN (
        SELECT dp.delivery_id, MIN(ip.manufacturer_location_id) as manufacturer_location_id
        FROM delivery_pallets dp
        JOIN inventory_pallets ip ON dp.inventory_pallet_id = ip.id
        WHERE dp.delivery_id IN ($placeholders)
        GROUP BY dp.delivery_id
    ) first_pallet ON d.id = first_pallet.delivery_id
    LEFT JOIN manufacturer_locations ml ON d.origin_type = 'manufacturer' AND first_pallet.manufacturer_location_id = ml.id
    LEFT JOIN manufacturers m ON ml.manufacturer_id = m.id OR (d.origin_type = 'manufacturer' AND m.name = TRIM(SUBSTRING_INDEX(d.supplier, ' - ', 1)))
    WHERE d.id IN ($placeholders)
";

// Add account filtering for admin users
if ($role === 'admin' && $account_id_for_admin) {
    $sql .= " AND (p.account_id = ? OR p.account_id IS NULL)";
    $types .= 'i';
    $params[] = $account_id_for_admin;
}

$sql .= " ORDER BY d.id";

$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();

$deliveries = [];
while ($row = $result->fetch_assoc()) {
    $deliveries[] = $row;
}
$stmt->close();

if (empty($deliveries)) {
    die("No deliveries found or you don't have permission to access these deliveries.");
}

// Group deliveries by BOL number
$bol_groups = [];
foreach ($deliveries as $delivery) {
    $bol_number = $delivery['bol_number'] ?? 'Unknown';
    if (!isset($bol_groups[$bol_number])) {
        $bol_groups[$bol_number] = [];
    }
    $bol_groups[$bol_number][] = $delivery;
}

// Determine which BOL group to display
$selected_bol = $_GET['bol_select'] ?? array_keys($bol_groups)[0];
$current_deliveries = $bol_groups[$selected_bol] ?? $deliveries;

// Get the first delivery from the selected BOL group for primary BOL info
$primary_delivery = $current_deliveries[0];

// Determine Ship From address based on origin
$ship_from_name = '';
$ship_from_address = '';
$ship_from_city_state_zip = '';

if ($primary_delivery['origin_type'] === 'manufacturer') {
    $ship_from_name = $primary_delivery['manufacturer_name'] ?? 'Unknown Manufacturer';
    $ship_from_address = $primary_delivery['manufacturer_street'] ?? '';
    $ship_from_city_state_zip = trim(implode(', ', array_filter([
        $primary_delivery['manufacturer_city'],
        $primary_delivery['manufacturer_state'],
        $primary_delivery['manufacturer_zip']
    ])));
} elseif ($primary_delivery['origin_type'] === 'warehouse') {
    $ship_from_name = $primary_delivery['warehouse_name'] ?? 'Unknown Warehouse';
    $ship_from_address = $primary_delivery['warehouse_street'] ?? '';
    $ship_from_city_state_zip = trim(implode(', ', array_filter([
        $primary_delivery['warehouse_city'],
        $primary_delivery['warehouse_state'],
        $primary_delivery['warehouse_zip']
    ])));
} else {
    $ship_from_name = $primary_delivery['supplier'] ?? 'Unknown Origin';
}

// Determine Ship To address based on delivery status and destination
$ship_to_name = '';
$ship_to_address = '';
$ship_to_city_state_zip = '';

// Check if this is shipping to a project or warehouse based on the delivery status and IDs
if (stripos($primary_delivery['status_of_delivery'], 'project') !== false && $primary_delivery['project_id']) {
    // Shipping to project
    $ship_to_name = $primary_delivery['project_name'] ?? 'Unknown Project';
    $ship_to_address = $primary_delivery['project_street'] ?? '';
    $ship_to_city_state_zip = trim(implode(', ', array_filter([
        $primary_delivery['project_city'],
        $primary_delivery['project_state'],
        $primary_delivery['project_zip']
    ])));
} elseif (stripos($primary_delivery['status_of_delivery'], 'warehouse') !== false && $primary_delivery['warehouse_id']) {
    // Shipping to warehouse
    $ship_to_name = $primary_delivery['warehouse_name'] ?? 'Unknown Warehouse';
    $ship_to_address = $primary_delivery['warehouse_street'] ?? '';
    $ship_to_city_state_zip = trim(implode(', ', array_filter([
        $primary_delivery['warehouse_city'],
        $primary_delivery['warehouse_state'],
        $primary_delivery['warehouse_zip']
    ])));
} else {
    // Fallback: try project first, then warehouse
    if ($primary_delivery['project_id']) {
        $ship_to_name = $primary_delivery['project_name'] ?? 'Unknown Project';
        $ship_to_address = $primary_delivery['project_street'] ?? '';
        $ship_to_city_state_zip = trim(implode(', ', array_filter([
            $primary_delivery['project_city'],
            $primary_delivery['project_state'],
            $primary_delivery['project_zip']
        ])));
    } elseif ($primary_delivery['warehouse_id']) {
        $ship_to_name = $primary_delivery['warehouse_name'] ?? 'Unknown Warehouse';
        $ship_to_address = $primary_delivery['warehouse_street'] ?? '';
        $ship_to_city_state_zip = trim(implode(', ', array_filter([
            $primary_delivery['warehouse_city'],
            $primary_delivery['warehouse_state'],
            $primary_delivery['warehouse_zip']
        ])));
    }
}

// Calculate totals for the selected BOL group only
$total_pallets = 0;
$total_modules = 0;
$total_weight = 0; // We'll need to estimate this

foreach ($current_deliveries as $delivery) {
    // Estimate pallets from quantity (assuming modules per pallet)
    $modules_per_pallet = 30; // Default assumption, can be adjusted
    $estimated_pallets = ceil($delivery['quantity'] / $modules_per_pallet);
    $total_pallets += $estimated_pallets;
    $total_modules += $delivery['quantity'];
    
    // Estimate weight (solar modules are typically 40-50 lbs each)
    $weight_per_module = 45; // lbs
    $total_weight += $delivery['quantity'] * $weight_per_module;
}

// Handle form submission for PDF generation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate_pdf'])) {
    // If BOL selection was submitted via POST, use it to determine the correct deliveries
    if (isset($_POST['bol_select']) && isset($bol_groups[$_POST['bol_select']])) {
        $current_deliveries = $bol_groups[$_POST['bol_select']];
    }
    
    // Get form data
    $form_data = [
        'bol_number' => $_POST['bol_number'] ?? '',
        'pickup_date' => $_POST['pickup_date'] ?? '',
        'ship_from_name' => $_POST['ship_from_name'] ?? '',
        'ship_from_address' => $_POST['ship_from_address'] ?? '',
        'ship_from_city_state_zip' => $_POST['ship_from_city_state_zip'] ?? '',
        'ship_from_sid' => $_POST['ship_from_sid'] ?? '',
        'ship_from_fob' => $_POST['ship_from_fob'] ?? '',
        'ship_to_name' => $_POST['ship_to_name'] ?? '',
        'ship_to_address' => $_POST['ship_to_address'] ?? '',
        'ship_to_city_state_zip' => $_POST['ship_to_city_state_zip'] ?? '',
        'ship_to_cid' => $_POST['ship_to_cid'] ?? '',
        'ship_to_fob' => $_POST['ship_to_fob'] ?? '',
        'carrier_name' => $_POST['carrier_name'] ?? '',
        'trailer_number' => $_POST['trailer_number'] ?? '',
        'seal_numbers' => $_POST['seal_numbers'] ?? '',
        'references' => $_POST['references'] ?? '',
        'freight_billed_to' => $_POST['freight_billed_to'] ?? 'shipper',
        'qty' => $_POST['qty'] ?? '',
        'type' => $_POST['type'] ?? '',
        'pieces' => $_POST['pieces'] ?? '',
        'weight' => $_POST['weight'] ?? '',
        'additional_info' => $_POST['additional_info'] ?? '',
        'load_instructions' => $_POST['load_instructions'] ?? ''
    ];
    
    // Generate BOL HTML
    $html = generateBolHtml($form_data, $current_deliveries);
    
    // Create PDF
    $options = new Options();
    $options->set('isRemoteEnabled', true);
    $options->set('isHtml5ParserEnabled', true);
    $dompdf = new Dompdf($options);
    $dompdf->loadHtml($html);
    $dompdf->setPaper('Letter', 'portrait');
    $dompdf->render();
    
    $filename = "BOL_" . ($form_data['bol_number'] ?: 'NoNumber') . ".pdf";
    
    // If there's a shipment success message, store it for display after PDF generation
    if (isset($_SESSION['shipment_success_for_bol'])) {
        $successMsg = $_SESSION['shipment_success_for_bol'];
        $schedulingLink = $_SESSION['shipment_scheduling_link'] ?? '';
        unset($_SESSION['shipment_success_for_bol']);
        unset($_SESSION['shipment_scheduling_link']);
        
        // Store completion message for when they return
        $completeMessage = $successMsg . " BOL has been generated.";
        if ($schedulingLink) {
            $completeMessage .= " <a href='{$schedulingLink}' style='color: #488C9A; text-decoration: underline;'>Schedule Delivery</a>";
        }
        $_SESSION['bol_completion_message'] = $completeMessage;
        
        // Keep the origin project ID for navigation back to the shipment page
        // It will be cleaned up when the user returns to create_shipment.php
    }
    
    $dompdf->stream($filename, ["Attachment" => false]);
    exit;
}

// Function to generate BOL HTML for PDF
function generateBolHtml($form_data, $deliveries) {
    // Convert logo to base64 to embed in PDF
    $logoPath = __DIR__ . '/pictures/header_logo.png';
    $logoBase64 = '';
    if (file_exists($logoPath)) {
        $logoData = file_get_contents($logoPath);
        $logoBase64 = 'data:image/png;base64,' . base64_encode($logoData);
    }
    
    $html = '
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <style>
            @page {
                margin: 0.4in;
                size: letter;
            }
            body {
                font-family: Arial, sans-serif;
                font-size: 10px;
                line-height: 1.2;
                margin: 0;
                padding: 0;
                color: #000;
            }
            .bol-container {
                border: 3px solid #000;
                width: 100%;
            }
            
            /* Header Section */
            .bol-header {
                border-bottom: 2px solid #000;
                padding: 10px;
                position: relative;
                min-height: 80px;
            }
            .company-section {
                float: left;
                width: 45%;
                padding-right: 15px;
                text-align: center;
            }
            .company-logo {
                max-height: 90px;
                max-width: 250px;
                display: block;
                margin: 0 auto;
            }
            .company-name {
                font-size: 16px;
                font-weight: bold;
                margin-top: 5px;
                color: #488C9A;
                text-align: center;
            }
            .bol-title-section {
                float: right;
                width: 50%;
                text-align: center;
                border: 2px solid #000;
                padding: 10px;
                background-color: #f8f9fa;
            }
            .bol-title {
                font-size: 20px;
                font-weight: bold;
                margin-bottom: 8px;
                text-decoration: underline;
            }
            .bol-info-grid {
                display: table;
                width: 100%;
                margin-top: 5px;
            }
            .bol-info-row {
                display: table-row;
            }
            .bol-info-label, .bol-info-value {
                display: table-cell;
                padding: 4px 6px;
                border: 1px solid #000;
                font-size: 10px;
            }
            .bol-info-label {
                background-color: #488C9A;
                color: white;
                font-weight: bold;
                width: 40%;
            }
            
            /* Main Content */
            .clear { clear: both; }
            .address-container {
                display: table;
                width: 100%;
                border-collapse: collapse;
                margin-top: 5px;
            }
            .address-section {
                display: table-cell;
                width: 50%;
                border: 2px solid #000;
                vertical-align: top;
                padding: 0;
            }
            .section-header {
                background-color: #488C9A;
                color: white;
                padding: 6px;
                text-align: center;
                font-weight: bold;
                font-size: 11px;
                border-bottom: 1px solid #000;
            }
            .address-content {
                padding: 10px;
            }
            .field-row {
                margin-bottom: 4px;
                font-size: 10px;
                line-height: 1.2;
            }
            .field-label {
                font-weight: bold;
                display: inline-block;
                width: 25%;
                vertical-align: top;
            }
            .field-value {
                display: inline-block;
                width: 70%;
            }
            
            /* Carrier and References */
            .carrier-refs-container {
                border: 2px solid #000;
                margin-top: 5px;
                padding: 0;
            }
            .carrier-header {
                background-color: #488C9A;
                color: white;
                padding: 6px;
                text-align: center;
                font-weight: bold;
                font-size: 11px;
                border-bottom: 1px solid #000;
            }
            .carrier-content {
                padding: 10px;
            }
            .carrier-grid {
                display: table;
                width: 100%;
                margin-bottom: 8px;
            }
            .carrier-row {
                display: table-row;
            }
            .carrier-cell {
                display: table-cell;
                width: 33%;
                padding: 6px 8px;
                border: 1px solid #ccc;
                font-size: 10px;
                vertical-align: top;
            }
            .carrier-label {
                font-weight: bold;
                display: block;
                margin-bottom: 2px;
            }
            
            /* Freight Section */
            .freight-container {
                border: 2px solid #000;
                margin-top: 5px;
            }
            .freight-checkboxes {
                padding: 10px;
                display: table;
                width: 100%;
            }
            .checkbox-item {
                display: table-cell;
                width: 33%;
                text-align: center;
                font-size: 10px;
            }
            .checkbox {
                width: 12px;
                height: 12px;
                border: 2px solid #000;
                display: inline-block;
                margin-right: 5px;
                vertical-align: middle;
            }
            .checked {
                background-color: #000;
            }
            
            /* Load Table */
            .load-table {
                width: 100%;
                border-collapse: collapse;
                border: 2px solid #000;
                margin-top: 5px;
            }
            .load-table th {
                background-color: #488C9A;
                color: white;
                font-weight: bold;
                padding: 8px 6px;
                border: 1px solid #000;
                text-align: center;
                font-size: 10px;
            }
            .load-table td {
                border: 1px solid #000;
                padding: 8px 6px;
                text-align: center;
                font-size: 10px;
                vertical-align: top;
                min-height: 30px;
            }
            
            /* Load Instructions */
            .load-instructions {
                border: 2px solid #000;
                margin-top: 5px;
            }
            .instructions-content {
                padding: 8px;
                font-size: 9px;
                line-height: 1.2;
            }
            
            /* Signature Sections */
            .signature-container {
                display: table;
                width: 100%;
                border-collapse: collapse;
                margin-top: 8px;
            }
            .signature-section {
                display: table-cell;
                width: 25%;
                border: 2px solid #000;
                vertical-align: top;
                min-height: 100px;
            }
            .signature-header {
                background-color: #488C9A;
                color: white;
                padding: 5px;
                text-align: center;
                font-weight: bold;
                font-size: 9px;
                border-bottom: 1px solid #000;
            }
            .signature-content {
                padding: 8px;
                height: 85px;
            }
            .signature-text {
                font-size: 7px;
                margin-bottom: 8px;
                line-height: 1.1;
            }
            .signature-line {
                border-bottom: 1px solid #000;
                margin: 37px 0 5px 0;
                height: 1px;
            }
            .signature-options {
                font-size: 7px;
                margin-top: 6px;
            }
            
            /* Note section */
            .note-section {
                border: 2px solid #000;
                padding: 8px;
                font-size: 7px;
                line-height: 1.1;
                margin-top: 8px;
            }
            
            /* Receiver signature */
            .receiver-section {
                border: 2px solid #000;
                padding: 8px;
                margin-top: 5px;
                height: 50px;
            }
            .receiver-title {
                font-weight: bold;
                font-size: 10px;
                margin-bottom: 40px;
            }
        </style>
    </head>
    <body>
        <div class="bol-container">
            <!-- Header -->
            <div class="bol-header">
                <div class="company-section">';
    
    if ($logoBase64) {
        $html .= '<img src="' . $logoBase64 . '" alt="Solterra Solutions" class="company-logo">';
    } else {
        $html .= '<div class="company-name">SOLTERRA<br>SOLUTIONS</div>';
    }
    
    $html .= '</div>
                <div class="bol-title-section">
                    <div class="bol-title">BILL OF LADING</div>
                    <div class="bol-info-grid">
                        <div class="bol-info-row">
                            <div class="bol-info-label">BOL NO:</div>
                            <div class="bol-info-value">' . htmlspecialchars($form_data['bol_number']) . '</div>
                        </div>
                        <div class="bol-info-row">
                            <div class="bol-info-label">Pickup Date:</div>
                            <div class="bol-info-value">' . htmlspecialchars(date('n/j/y', strtotime($form_data['pickup_date']))) . '</div>
                        </div>
                    </div>
                </div>
                <div class="clear"></div>
            </div>
            
            <!-- Ship From/To -->
            <div class="address-container">
                <div class="address-section">
                    <div class="section-header">Ship From</div>
                    <div class="address-content">
                        <div class="field-row">
                            <span class="field-label">Name:</span>
                            <span class="field-value">' . htmlspecialchars($form_data['ship_from_name']) . '</span>
                        </div>
                        <div class="field-row">
                            <span class="field-label">Address:</span>
                            <span class="field-value">' . htmlspecialchars($form_data['ship_from_address']) . '</span>
                        </div>
                        <div class="field-row">
                            <span class="field-label">City/State/Zip:</span>
                            <span class="field-value">' . htmlspecialchars($form_data['ship_from_city_state_zip']) . '</span>
                        </div>
                        <div class="field-row">
                            <span class="field-label">SID#:</span>
                            <span class="field-value">' . htmlspecialchars($form_data['ship_from_sid']) . '</span>
                        </div>
                        <div class="field-row">
                            <span class="field-label">FOB:</span>
                            <span class="field-value">' . htmlspecialchars($form_data['ship_from_fob']) . '</span>
                        </div>
                    </div>
                </div>
                
                <div class="address-section">
                    <div class="section-header">Ship To</div>
                    <div class="address-content">
                        <div class="field-row">
                            <span class="field-label">Name:</span>
                            <span class="field-value">' . htmlspecialchars($form_data['ship_to_name']) . '</span>
                        </div>
                        <div class="field-row">
                            <span class="field-label">Address:</span>
                            <span class="field-value">' . htmlspecialchars($form_data['ship_to_address']) . '</span>
                        </div>
                        <div class="field-row">
                            <span class="field-label">City/State/Zip:</span>
                            <span class="field-value">' . htmlspecialchars($form_data['ship_to_city_state_zip']) . '</span>
                        </div>
                        <div class="field-row">
                            <span class="field-label">CID#:</span>
                            <span class="field-value">' . htmlspecialchars($form_data['ship_to_cid']) . '</span>
                        </div>
                        <div class="field-row">
                            <span class="field-label">FOB:</span>
                            <span class="field-value">' . htmlspecialchars($form_data['ship_to_fob']) . '</span>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Carrier Information and References -->
            <div class="carrier-refs-container">
                <div class="carrier-header">Carrier Information</div>
                <div class="carrier-content">
                    <div class="carrier-grid">
                        <div class="carrier-row">
                            <div class="carrier-cell">
                                <span class="carrier-label">Carrier name:</span>
                                ' . htmlspecialchars($form_data['carrier_name']) . '
                            </div>
                            <div class="carrier-cell">
                                <span class="carrier-label">Trailer number:</span>
                                ' . htmlspecialchars($form_data['trailer_number']) . '
                            </div>
                            <div class="carrier-cell">
                                <span class="carrier-label">Seal number(s):</span>
                                ' . htmlspecialchars($form_data['seal_numbers']) . '
                            </div>
                        </div>
                    </div>
                    <div style="margin-top: 8px; font-size: 10px; line-height: 1.2;">
                        <strong>References:</strong><br>
                        ' . nl2br(htmlspecialchars($form_data['references'])) . '
                    </div>
                </div>
            </div>
            
            <!-- Freight Charges -->
            <div class="freight-container">
                <div class="section-header">Freight Charges Billed To</div>
                <div class="freight-checkboxes">
                    <div class="checkbox-item">
                        <span class="checkbox' . ($form_data['freight_billed_to'] === 'shipper' ? ' checked' : '') . '"></span> Shipper
                    </div>
                    <div class="checkbox-item">
                        <span class="checkbox' . ($form_data['freight_billed_to'] === 'consignee' ? ' checked' : '') . '"></span> Consignee
                    </div>
                    <div class="checkbox-item">
                        <span class="checkbox' . ($form_data['freight_billed_to'] === 'third_party' ? ' checked' : '') . '"></span> Third Party
                    </div>
                </div>
            </div>
            
            <!-- Load Table -->
            <table class="load-table">
                <thead>
                    <tr>
                        <th>Qty</th>
                        <th>Type</th>
                        <th># of Pieces</th>
                        <th>Weight</th>
                        <th>Additional Info</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>' . htmlspecialchars($form_data['qty']) . '</td>
                        <td>' . htmlspecialchars($form_data['type']) . '</td>
                        <td>' . htmlspecialchars($form_data['pieces']) . '</td>
                        <td>' . htmlspecialchars($form_data['weight']) . '</td>
                        <td style="text-align: left; padding-left: 8px;">' . nl2br(htmlspecialchars($form_data['additional_info'])) . '</td>
                    </tr>
                </tbody>
            </table>
            
            <!-- Load Instructions -->
            <div class="load-instructions">
                <div class="section-header">Load Instructions:</div>
                <div class="instructions-content">
                    ' . nl2br(htmlspecialchars($form_data['load_instructions'])) . '
                </div>
            </div>
            
            <!-- Note -->
            <div class="note-section">
                <strong>NOTE:</strong> Liability Limitation for loss or damage in this shipment may be applicable. See 49 U.S.C. 14706(C)(1)(A) and (B).<br><br>
                RECEIVED, subject to individually determined rates or contracts that have been agreed upon in writing between the carrier and shipper, if applicable, otherwise to the rates, classifications and rules that have been established by the carrier and are available to the shipper, on request. The shipper hereby certifies that he/she is familiar with all the terms and conditions of the NMFC Uniform Straight Bill of Lading, including those on the back thereof, and the said terms and conditions are hereby agreed to by the shipper and accepted for him/herself and his/her assigns.
            </div>
            
            <!-- Signature Section -->
            <div class="signature-container">
                <div class="signature-section">
                    <div class="signature-header">SHIPPER SIGNATURE / DATE</div>
                    <div class="signature-content">
                        <div class="signature-text">
                            This is to certify that the above named materials are properly classified, packaged, marked and labeled, and are in proper condition for transportation according to the applicable regulations of the DOT.
                        </div>
                        <div class="signature-line"></div>
                    </div>
                </div>
                
                <div class="signature-section">
                    <div class="signature-header">Trailer Loaded</div>
                    <div class="signature-content">
                        <div class="signature-text">
                            __ By Shipper<br>
                            __ By Driver
                        </div>
                    </div>
                </div>
                
                <div class="signature-section">
                    <div class="signature-header">Freight Counted</div>
                    <div class="signature-content">
                        <div class="signature-text" style="margin-bottom: 10px;">
                            __ By Shipper<br>
                            __ By Driver/pallets
                        </div>
                        <div class="signature-text">
                            __ said to contain<br>
                            __ By Driver/pieces
                        </div>
                    </div>
                </div>
                
                <div class="signature-section">
                    <div class="signature-header">CARRIER SIGNATURE / PICKUP DATE</div>
                    <div class="signature-content">
                        <div class="signature-text">
                            Carrier acknowledges receipt of packages and required placards. Carrier certifies emergency response info was made available and/or carrier has the DOT emergency response guidebook or equivalent documentation in the vehicle. Property described above is received in good order, except as otherwise noted.
                        </div>
                        <div class="signature-line"></div>
                    </div>
                </div>
            </div>
            
            <!-- Receiver Signature -->
            <div class="receiver-section">
                <div class="receiver-title">Receiver Signature / Date</div>
                <div style="border-bottom: 1px solid #000; margin-top: 10px;"></div>
            </div>
        </div>
    </body>
    </html>';
    
    return $html;
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Generate Bill of Lading</title>
    <link rel="stylesheet" href="portal.css">
    <link rel="icon" href="pictures/favicon.png" type="image/x-icon">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        .bol-container {
            max-width: 800px;
            margin: 20px auto;
            background: white;
            padding: 20px;
            border: 2px solid #000;
            font-family: Arial, sans-serif;
        }
        
        .bol-header {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 20px;
            align-items: center;
        }
        
        .company-logo {
            text-align: left;
        }
        
        .company-logo img {
            max-height: 80px;
            max-width: 200px;
        }
        
        .bol-title {
            text-align: center;
            font-size: 24px;
            font-weight: bold;
            margin-bottom: 10px;
        }
        
        .bol-info {
            text-align: right;
        }
        
        .bol-info-row {
            margin-bottom: 5px;
        }
        
        .bol-main {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 20px;
        }
        
        .address-section {
            border: 1px solid #000;
            padding: 10px;
        }
        
        .address-section h3 {
            background-color: #488C9A;
            color: white;
            margin: -10px -10px 10px -10px;
            padding: 8px 10px;
            font-size: 14px;
            text-align: center;
        }
        
        .form-group {
            margin-bottom: 10px;
        }
        
        .form-group label {
            font-weight: bold;
            display: block;
            margin-bottom: 3px;
            font-size: 12px;
        }
        
        .form-group input,
        .form-group textarea {
            width: 100%;
            padding: 4px;
            border: 1px solid #ccc;
            font-size: 12px;
            box-sizing: border-box;
        }
        
        .carrier-section {
            grid-column: span 2;
            border: 1px solid #000;
            padding: 10px;
            margin-bottom: 20px;
        }
        
        .carrier-section h3 {
            background-color: #488C9A;
            color: white;
            margin: -10px -10px 10px -10px;
            padding: 8px 10px;
            font-size: 14px;
            text-align: center;
        }
        
        .carrier-grid {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 15px;
        }
        
        .freight-section {
            grid-column: span 2;
            border: 1px solid #000;
            padding: 10px;
            margin-bottom: 20px;
        }
        
        .freight-section h3 {
            background-color: #488C9A;
            color: white;
            margin: -10px -10px 10px -10px;
            padding: 8px 10px;
            font-size: 14px;
            text-align: center;
        }
        
        .load-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }
        
        .load-table th,
        .load-table td {
            border: 1px solid #000;
            padding: 8px;
            text-align: center;
            font-size: 12px;
        }
        
        .load-table th {
            background-color: #488C9A;
            color: white;
        }
        
        .load-instructions {
            grid-column: span 2;
            border: 1px solid #000;
            padding: 10px;
            margin-bottom: 20px;
        }
        
        .load-instructions h3 {
            background-color: #488C9A;
            color: white;
            margin: -10px -10px 10px -10px;
            padding: 8px 10px;
            font-size: 14px;
            text-align: center;
        }
        
        .signature-section {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-top: 30px;
        }
        
        .signature-box {
            border: 1px solid #000;
            padding: 10px;
            min-height: 100px;
        }
        
        .signature-box h4 {
            margin: 0 0 10px 0;
            font-size: 12px;
            text-align: center;
            background-color: #f0f0f0;
            padding: 5px;
        }
        

        
        .success-message {
            background-color: #d4edda;
            color: #155724;
            padding: 15px;
            border: 1px solid #c3e6cb;
            border-radius: 4px;
            margin-bottom: 20px;
            text-align: center;
        }
        
        @media print {
            .bol-container {
                border: none;
                margin: 0;
                padding: 0;
            }
        }
    </style>
</head>
<body>
<?php include 'header.php'; ?>

<main>
    <?php if (isset($_SESSION['shipment_success_for_bol'])): ?>
        <div style="background-color: #d4edda; color: #155724; padding: 15px; border: 1px solid #c3e6cb; border-radius: 4px; margin-bottom: 20px; text-align: center;">
            <strong>✓ <?php echo htmlspecialchars($_SESSION['shipment_success_for_bol']); ?></strong>
            <br><small>Complete the BOL information below to generate your Bill of Lading document.</small>
        </div>
    <?php endif; ?>

    <?php if (count($bol_groups) > 1): ?>
        <div style="background-color: #f8f9fa; border: 1px solid #e9ecef; border-radius: 4px; padding: 15px; margin-bottom: 20px;">
            <div style="display: flex; align-items: center; gap: 15px; flex-wrap: wrap;">
                <span style="font-weight: 600; color: #488C9A;">Multiple BOLs Detected:</span>
                <div style="display: flex; align-items: center; gap: 10px;">
                    <label for="bolSelect" style="font-weight: 500;">Select BOL:</label>
                    <select id="bolSelect" onchange="switchBol()" style="padding: 6px 10px; border: 1px solid #ccc; border-radius: 4px; background: white;">
                        <?php foreach ($bol_groups as $bol_number => $group): ?>
                            <option value="<?php echo htmlspecialchars($bol_number); ?>" 
                                    <?php echo ($bol_number === $selected_bol) ? 'selected' : ''; ?>>
                                BOL: <?php echo htmlspecialchars($bol_number); ?> (<?php echo count($group); ?> deliveries)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div style="color: #6c757d; font-size: 0.9em;">
                    Showing BOL <strong><?php echo htmlspecialchars($selected_bol); ?></strong> 
                    (<?php echo count($current_deliveries); ?> <?php echo count($current_deliveries) === 1 ? 'delivery' : 'deliveries'; ?>)
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- Top Action Buttons -->
    <div style="margin-bottom: 30px; display: flex; gap: 15px; align-items: center;">

        
        <?php 
        $back_url = "create_shipment.php";
        if ($project_id_for_breadcrumb > 0) {
            $back_url .= "?project_id=" . $project_id_for_breadcrumb;
        }
        ?>
        <a href="<?php echo $back_url; ?>" style="
            background: #6c757d;
            color: white;
            text-decoration: none;
            padding: 12px 24px;
            border-radius: 4px;
            font-weight: 600;
            font-size: 16px;
            transition: all 0.3s ease;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            display: inline-block;
        " onmouseover="this.style.background='#5a6268'; this.style.transform='translateY(-1px)'; this.style.boxShadow='0 4px 8px rgba(0,0,0,0.15)'" onmouseout="this.style.background='#6c757d'; this.style.transform='translateY(0)'; this.style.boxShadow='0 2px 4px rgba(0,0,0,0.1)'">
            ← Back to Shipments
        </a>
        <button type="submit" name="generate_pdf" formtarget="_blank" form="bolForm" style="
            background: #488C9A;
            color: white;
            border: none;
            padding: 12px 24px;
            border-radius: 4px;
            font-weight: 600;
            font-size: 16px;
            cursor: pointer;
            transition: all 0.3s ease;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        " onmouseover="this.style.background='#3A6E7F'; this.style.transform='translateY(-1px)'; this.style.boxShadow='0 4px 8px rgba(0,0,0,0.15)'" onmouseout="this.style.background='#488C9A'; this.style.transform='translateY(0)'; this.style.boxShadow='0 2px 4px rgba(0,0,0,0.1)'">
            📄 Generate BOL PDF
        </button>
    </div>

    <form method="POST" action="" id="bolForm">
        <div class="bol-container">
            <!-- BOL Header -->
            <div class="bol-header">
                <div class="company-logo">
                    <img src="pictures/header_logo.png" alt="Solterra Solutions" />
                </div>
                <div>
                    <div class="bol-title">BILL OF LADING</div>
                    <div class="bol-info">
                        <div class="bol-info-row">
                            <strong>BOL NO:</strong> 
                            <input type="text" name="bol_number" value="<?php echo htmlspecialchars($selected_bol !== 'Unknown' ? $selected_bol : ($primary_delivery['bol_number'] ?? '')); ?>" style="width: 120px; margin-left: 5px;">
                        </div>
                        <div class="bol-info-row">
                            <strong>Pickup Date:</strong> 
                            <input type="date" name="pickup_date" value="<?php echo htmlspecialchars($primary_delivery['left_warehouse_date'] ?? date('Y-m-d')); ?>" style="width: 140px; margin-left: 5px;">
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Ship From/To Section -->
            <div class="bol-main">
                <div class="address-section">
                    <h3>Ship From</h3>
                    <div class="form-group">
                        <label>Name:</label>
                        <input type="text" name="ship_from_name" value="<?php echo htmlspecialchars($ship_from_name); ?>">
                    </div>
                    <div class="form-group">
                        <label>Address:</label>
                        <input type="text" name="ship_from_address" value="<?php echo htmlspecialchars($ship_from_address); ?>">
                    </div>
                    <div class="form-group">
                        <label>City/State/Zip:</label>
                        <input type="text" name="ship_from_city_state_zip" value="<?php echo htmlspecialchars($ship_from_city_state_zip); ?>">
                    </div>
                    <div class="form-group">
                        <label>SID#:</label>
                        <input type="text" name="ship_from_sid" value="15141075">
                    </div>
                    <div class="form-group">
                        <label>FOB:</label>
                        <input type="text" name="ship_from_fob" value="">
                    </div>
                </div>
                
                <div class="address-section">
                    <h3>Ship To</h3>
                    <div class="form-group">
                        <label>Name:</label>
                        <input type="text" name="ship_to_name" value="<?php echo htmlspecialchars($ship_to_name); ?>">
                    </div>
                    <div class="form-group">
                        <label>Address:</label>
                        <input type="text" name="ship_to_address" value="<?php echo htmlspecialchars($ship_to_address); ?>">
                    </div>
                    <div class="form-group">
                        <label>City/State/Zip:</label>
                        <input type="text" name="ship_to_city_state_zip" value="<?php echo htmlspecialchars($ship_to_city_state_zip); ?>">
                    </div>
                    <div class="form-group">
                        <label>CID#:</label>
                        <input type="text" name="ship_to_cid" value="15141076">
                    </div>
                    <div class="form-group">
                        <label>FOB:</label>
                        <input type="text" name="ship_to_fob" value="">
                    </div>
                </div>
            </div>
            
            <!-- Carrier Information -->
            <div class="carrier-section">
                <h3>Carrier Information</h3>
                <div class="carrier-grid">
                    <div class="form-group">
                        <label>Carrier name:</label>
                        <input type="text" name="carrier_name" value="" placeholder="ABC123 Trucking">
                    </div>
                    <div class="form-group">
                        <label>Trailer number:</label>
                        <input type="text" name="trailer_number" value="" placeholder="">
                    </div>
                    <div class="form-group">
                        <label>Seal number(s):</label>
                        <input type="text" name="seal_numbers" value="" placeholder="">
                    </div>
                </div>
                <div class="form-group" style="margin-top: 10px;">
                    <label>References:</label>
                    <textarea name="references" rows="2" placeholder="Reference this BOL number upon pickup."></textarea>
                </div>
            </div>
            
            <!-- Freight Charges -->
            <div class="freight-section">
                <h3>Freight Charges Billed To</h3>
                <div style="display: flex; gap: 20px; align-items: center;">
                    <label style="display: flex; align-items: center; gap: 5px;">
                        <input type="radio" name="freight_billed_to" value="shipper" checked> Shipper
                    </label>
                    <label style="display: flex; align-items: center; gap: 5px;">
                        <input type="radio" name="freight_billed_to" value="consignee"> Consignee
                    </label>
                    <label style="display: flex; align-items: center; gap: 5px;">
                        <input type="radio" name="freight_billed_to" value="third_party"> Third Party
                    </label>
                </div>
            </div>
            
            <!-- Load Information Table -->
            <table class="load-table">
                <thead>
                    <tr>
                        <th>Qty</th>
                        <th>Type</th>
                        <th># of Pieces</th>
                        <th>Weight</th>
                        <th>Additional Info</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><input type="text" name="qty" value="<?php echo $total_pallets; ?>" style="width: 60px; text-align: center; border: none;"></td>
                        <td><input type="text" name="type" value="Pallets" style="width: 80px; text-align: center; border: none;"></td>
                        <td><input type="text" name="pieces" value="<?php echo $total_modules; ?>" style="width: 80px; text-align: center; border: none;"></td>
                        <td><input type="text" name="weight" value="<?php echo number_format($total_weight); ?>" style="width: 80px; text-align: center; border: none;"></td>
                        <td>
                            <input type="text" name="additional_info" value="Truckload of <?php echo $primary_delivery['wattage']; ?>W modules. <?php echo $total_modules; ?> modules <?php echo $total_pallets; ?> pallets" 
                                   style="width: 100%; border: none; padding: 4px;">
                        </td>
                    </tr>
                </tbody>
            </table>
            
            <!-- Load Instructions -->
            <div class="load-instructions">
                <h3>Load Instructions:</h3>
                <textarea name="load_instructions" rows="4" style="width: 100%; border: none; resize: vertical;">Driver must read and be provided the <?php echo htmlspecialchars($ship_to_name); ?> Driver Handout. All drivers must arrive with proper PPE including, Steel toe or composite safety boots or shoes. Hard hat w/ safety glasses. Reflective safety vest or similar reflective coat. Long pants/shirt with 4" minimum sleeves.</textarea>
            </div>
            
            <!-- Note about liability -->
            <div style="font-size: 10px; margin-bottom: 20px; border: 1px solid #ccc; padding: 8px;">
                <strong>NOTE:</strong> Liability Limitation for loss or damage in this shipment may be applicable. See 49 U.S.C. 14706(C)(1)(A) and (B).
                <br><br>
                RECEIVED, subject to individually determined rates or contracts that have been agreed upon in writing between the carrier and shipper, if applicable, otherwise to the rates, classifications and rules that have been established by the carrier and are available to the shipper, on request. The shipper hereby certifies that he/she is familiar with all the terms and conditions of the NMFC Uniform Straight Bill of Lading, including those on the back thereof, and the said terms and conditions are hereby agreed to by the shipper and accepted for him/herself and his/her assigns.
            </div>
            
            <!-- Signature Section -->
            <div class="signature-section">
                <div class="signature-box">
                    <h4>SHIPPER SIGNATURE / DATE</h4>
                    <div style="font-size: 10px; margin-bottom: 10px;">
                        This is to certify that the above named materials are properly classified, packaged, marked and labeled, and are in proper condition for transportation according to the applicable regulations of the DOT.
                    </div>
                    <div style="margin-top: 30px; border-bottom: 1px solid #000; margin-bottom: 10px;"></div>
                    <div style="display: flex; justify-content: space-between; font-size: 10px;">
                        <span>Trailer Loaded: __ By Shipper __ By Driver</span>
                        <span>Freight Counted: __ By Shipper __ By Driver/pallets</span>
                    </div>
                    <div style="font-size: 10px; margin-top: 5px;">
                        __ said to contain __ By Driver/pieces
                    </div>
                </div>
                
                <div class="signature-box">
                    <h4>CARRIER SIGNATURE / PICKUP DATE</h4>
                    <div style="font-size: 10px; margin-bottom: 10px;">
                        Carrier acknowledges receipt of packages and required placards. Carrier certifies emergency response info was made available and/or carrier has the DOT emergency response guidebook or equivalent documentation in the vehicle. Property described above is received in good order, except as otherwise noted.
                    </div>
                    <div style="margin-top: 30px;">
                        <strong>Shipper Signature</strong>
                    </div>
                </div>
            </div>
            
            <div style="margin-top: 30px; border: 1px solid #000; padding: 10px; font-size: 10px;">
                <strong>Receiver Signature / Date</strong>
                <div style="margin-top: 20px; border-bottom: 1px solid #000;"></div>
            </div>
        </div>
        <!-- Hidden field to preserve BOL selection -->
        <input type="hidden" name="bol_select" value="<?php echo htmlspecialchars($selected_bol); ?>">
    </form>
</main>

<script>
// Function to switch between BOL groups
function switchBol() {
    const select = document.getElementById('bolSelect');
    if (select) {
        const selectedBol = select.value;
        const currentUrl = new URL(window.location.href);
        currentUrl.searchParams.set('bol_select', selectedBol);
        window.location.href = currentUrl.toString();
    }
}

// Auto-calculate weight when modules change
document.addEventListener('DOMContentLoaded', function() {
    const piecesInput = document.querySelector('input[name="pieces"]');
    const weightInput = document.querySelector('input[name="weight"]');
    
    if (piecesInput && weightInput) {
        piecesInput.addEventListener('input', function() {
            const modules = parseInt(this.value) || 0;
            const weightPerModule = 45; // lbs
            const totalWeight = modules * weightPerModule;
            weightInput.value = totalWeight.toLocaleString();
        });
    }
});
</script>

</body>
</html> 