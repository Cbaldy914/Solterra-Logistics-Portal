<?php
session_name("logistics_session");
session_start();

// Authentication and Role Check
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
    header("Location: login.php");
    exit();
}

$role = $_SESSION['role'];
$user_id = $_SESSION['user_id'];

// Only allow admin and global_admin access
$is_admin = ($role === 'admin' || $role === 'global_admin');
$is_global_admin = ($role === 'global_admin');

if (!$is_admin) {
    header("Location: unauthorized.php");
    exit();
}

// Database connection
require_once '../config.php';
$conn = getDBConnection();
if (!$conn) {
    die("Database connection failed.");
}

// Get project_id from GET parameter
$project_id = isset($_GET['project_id']) ? intval($_GET['project_id']) : 0;
if ($project_id <= 0) {
    die("Invalid project ID.");
}

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

// Verify project access
$project_access_sql = "SELECT p.project_name, p.account_id FROM projects p WHERE p.id = ?";
if ($role === 'admin' && $account_id_for_admin) {
    $project_access_sql .= " AND p.account_id = ?";
}

$project_access_stmt = $conn->prepare($project_access_sql);
if ($role === 'admin' && $account_id_for_admin) {
    $project_access_stmt->bind_param("ii", $project_id, $account_id_for_admin);
} else {
    $project_access_stmt->bind_param("i", $project_id);
}
$project_access_stmt->execute();
$project_access_stmt->bind_result($project_name, $project_account_id);
if (!$project_access_stmt->fetch()) {
    die("Access denied to this project.");
}
$project_access_stmt->close();

// Get project information (timezone comes from projects table now)
$project_info_sql = "SELECT timezone FROM projects WHERE id = ? LIMIT 1";
$project_info_stmt = $conn->prepare($project_info_sql);
$project_info_stmt->bind_param("i", $project_id);
$project_info_stmt->execute();
$project_info_stmt->bind_result($site_timezone);
if (!$project_info_stmt->fetch()) {
    die("Project not found.");
}
$project_info_stmt->close();

// Set defaults if not configured
if (empty($site_timezone)) {
    $site_timezone = 'UTC';
}
$appointment_duration = 30; // Default appointment duration

$site_tz = new DateTimeZone($site_timezone);

// CSRF Token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// Helper functions
function json_response($data) {
    header('Content-Type: application/json');
    echo json_encode($data);
    exit();
}

function check_csrf() {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
            json_response(['success'=>false,'error'=>'Invalid CSRF token']);
        }
    }
}

function local_to_utc($datetime_str, $site_tz) {
    if(empty($datetime_str)) return null;
    $local_dt = new DateTime($datetime_str, $site_tz);
    $local_dt->setTimezone(new DateTimeZone('UTC'));
    return $local_dt->format('Y-m-d H:i:s');
}

function utc_to_local($datetime_str, $site_tz) {
    if(empty($datetime_str)) return '';
    $utc_dt = new DateTime($datetime_str, new DateTimeZone('UTC'));
    $utc_dt->setTimezone($site_tz);
    return $utc_dt->format('Y-m-d H:i:s');
}

function processDamageReport($conn, $appointment_id, $bol_number, $delivery_date) {
    try {
        // Get pallet damage data
        $pallet_damages = isset($_POST['pallet_damages']) ? json_decode($_POST['pallet_damages'], true) : [];
        $pallet_actuals = isset($_POST['pallet_actuals']) ? json_decode($_POST['pallet_actuals'], true) : [];
        $pallet_accepted = isset($_POST['pallet_accepted']) ? json_decode($_POST['pallet_accepted'], true) : [];
        $pallet_notes = isset($_POST['pallet_notes']) ? json_decode($_POST['pallet_notes'], true) : [];
        
        // Handle photo uploads first
        $pictures = [];
        if (isset($_FILES['damage_pictures']) && is_array($_FILES['damage_pictures']['name'])) {
            $upload_dir = 'uploads/damage_photos/';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }
            
            $count = count($_FILES['damage_pictures']['name']);
            for ($i = 0; $i < $count; $i++) {
                if ($_FILES['damage_pictures']['error'][$i] === UPLOAD_ERR_OK) {
                    $filename = 'damage_' . $appointment_id . '_' . time() . '_' . $i . '.jpg';
                    $filepath = $upload_dir . $filename;
                    move_uploaded_file($_FILES['damage_pictures']['tmp_name'][$i], $filepath);
                    $pictures[] = $filepath;
                }
            }
        }
        
        // First, delete any existing warranty claims for this appointment
        $delete_existing = $conn->prepare("DELETE FROM warranty_claims WHERE scheduling_id = ?");
        $delete_existing->bind_param("i", $appointment_id);
        $delete_existing->execute();
        $delete_existing->close();
        
        // Get all unique pallet IDs that have any kind of issue
        $all_pallet_ids = array_unique(array_merge(
            array_keys($pallet_damages),
            array_keys($pallet_actuals),
            array_keys($pallet_accepted)
        ));
        
        // Process each pallet individually
        foreach ($all_pallet_ids as $pallet_id) {
            // Get pallet info
            $pallet_stmt = $conn->prepare("SELECT wattage, quantity FROM inventory_pallets WHERE id = ?");
            $pallet_stmt->bind_param("i", $pallet_id);
            $pallet_stmt->execute();
            $pallet_stmt->bind_result($wattage, $original_qty);
            $pallet_stmt->fetch();
            $pallet_stmt->close();
            
            $expected_qty = intval($original_qty);
            $actual_qty = isset($pallet_actuals[$pallet_id]) ? intval($pallet_actuals[$pallet_id]) : $expected_qty;
            $damaged_qty = isset($pallet_damages[$pallet_id]) ? intval($pallet_damages[$pallet_id]) : 0;
            $accepted_qty = isset($pallet_accepted[$pallet_id]) ? intval($pallet_accepted[$pallet_id]) : $actual_qty;
            $notes = isset($pallet_notes[$pallet_id]) ? trim($pallet_notes[$pallet_id]) : '';
            
            // Determine issue type
            $has_damage = ($damaged_qty > 0);
            $has_quantity_discrepancy = ($actual_qty != $expected_qty);
            
            $issue_type = '';
            if ($has_damage && $has_quantity_discrepancy) {
                $issue_type = 'both';
            } elseif ($has_damage) {
                $issue_type = 'damaged';
            } elseif ($has_quantity_discrepancy) {
                $issue_type = 'quantity_discrepancy';
            } else {
                // No issues, skip this pallet
                continue;
            }
            
            // Create warranty claim for this pallet
            $insert_warranty = $conn->prepare("
                INSERT INTO warranty_claims 
                (scheduling_id, bol_number, delivery_date, status, notes, pictures, 
                 pallet_id, issue_type, expected_quantity, actual_quantity, 
                 damaged_quantity, accepted_quantity)
                VALUES (?, ?, ?, 'Pending', ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $insert_warranty->bind_param("issssissiii", 
                $appointment_id,
                $bol_number,
                $delivery_date,
                $notes,
                json_encode($pictures), // Store pictures on each claim
                $pallet_id,
                $issue_type,
                $expected_qty,
                $actual_qty,
                $damaged_qty,
                $accepted_qty
            );
            $insert_warranty->execute();
            $insert_warranty->close();
            
            // Update pallet status based on issues
            if ($has_damage) {
                $new_status = ($damaged_qty >= $actual_qty) ? 'Damaged - Total Loss' : 'Partially Damaged';
                $update_pallet = $conn->prepare("UPDATE inventory_pallets SET status = ? WHERE id = ?");
                $update_pallet->bind_param("si", $new_status, $pallet_id);
                $update_pallet->execute();
                $update_pallet->close();
            }
            
            // Note: We do NOT update the pallet quantity in inventory_pallets when there's a discrepancy.
            // The original expected quantity should remain unchanged in the database.
            // The actual received quantity is recorded in the warranty_claims table for tracking purposes.
        }
        
    } catch (Exception $e) {
        throw new Exception("Error processing damage report: " . $e->getMessage());
    }
}

function processSafetyIncident($conn, $appointment_id, $bol_number) {
    try {
        $safety_notes = trim($_POST['safety_notes'] ?? '');
        $report_driver = ($_POST['report_driver'] ?? 'no') === 'yes' ? 'Yes' : 'No';
        
        // Handle safety photo uploads
        $safety_pictures = [];
        if (isset($_FILES['safety_pictures']) && is_array($_FILES['safety_pictures']['name'])) {
            $upload_dir = 'uploads/safety_photos/';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }
            
            $count = count($_FILES['safety_pictures']['name']);
            for ($i = 0; $i < $count; $i++) {
                if ($_FILES['safety_pictures']['error'][$i] === UPLOAD_ERR_OK) {
                    $filename = 'safety_' . $appointment_id . '_' . time() . '_' . $i . '.jpg';
                    $filepath = $upload_dir . $filename;
                    move_uploaded_file($_FILES['safety_pictures']['tmp_name'][$i], $filepath);
                    $safety_pictures[] = $filepath;
                }
            }
        }
        
        // Create or update safety incident
        $check_safety = $conn->prepare("SELECT id FROM site_safety WHERE scheduling_id = ?");
        $check_safety->bind_param("i", $appointment_id);
        $check_safety->execute();
        $check_safety->store_result();
        
        if ($check_safety->num_rows > 0) {
            // Update existing safety incident
            $update_safety = $conn->prepare("
                UPDATE site_safety 
                SET bol_number = ?, notes = ?, pictures = ?, report_driver = ?
                WHERE scheduling_id = ?
            ");
            $update_safety->bind_param("ssssi", 
                $bol_number,
                $safety_notes,
                json_encode($safety_pictures),
                $report_driver,
                $appointment_id
            );
            $update_safety->execute();
            $update_safety->close();
        } else {
            // Create new safety incident
            $insert_safety = $conn->prepare("
                INSERT INTO site_safety 
                (scheduling_id, bol_number, notes, pictures, report_driver)
                VALUES (?, ?, ?, ?, ?)
            ");
            $insert_safety->bind_param("issss", 
                $appointment_id,
                $bol_number,
                $safety_notes,
                json_encode($safety_pictures),
                $report_driver
            );
            $insert_safety->execute();
            $insert_safety->close();
        }
        $check_safety->close();
        
    } catch (Exception $e) {
        throw new Exception("Error processing safety incident: " . $e->getMessage());
    }
}

// Load operating hours for the project
$days_oh = [];
for ($i = 0; $i < 7; $i++) {
    $days_oh[$i] = [];
}

$oh_stmt = $conn->prepare("
    SELECT day_of_week, start_time, end_time
    FROM site_operating_hours
    WHERE project_id = ?
    ORDER BY day_of_week, start_time
");
$oh_stmt->bind_param("i", $project_id);
$oh_stmt->execute();
$oh_res = $oh_stmt->get_result();
while($row = $oh_res->fetch_assoc()) {
    $days_oh[$row['day_of_week']][] = [
        'start_time' => $row['start_time'],
        'end_time' => $row['end_time']
    ];
}
$oh_stmt->close();

// Handle AJAX actions
$action = $_REQUEST['action'] ?? '';

if ($action) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        check_csrf();
    }

    // Load appointments
    if ($action === 'load_appointments') {
        $view = $_GET['view'] ?? 'day';
        $current_date = $_GET['date'] ?? date('Y-m-d');
        $dt = new DateTime($current_date);
        $appointments = [];

        $day_start = $dt->format('Y-m-d') . " 00:00:00";
        $day_end = $dt->format('Y-m-d') . " 23:59:59";

        // Date range depends on view
        if ($view === 'day') {
            $start_str = $day_start;
            $end_str = $day_end;
        } elseif ($view === 'week') {
            $monday = clone $dt;
            $monday->setTime(0, 0, 0);
            $monday->modify('-' . (($monday->format('w') + 6) % 7) . ' day');
            $sunday = clone $monday;
            $sunday->modify('+6 day');
            $start_str = $monday->format('Y-m-d 00:00:00');
            $end_str = $sunday->format('Y-m-d 23:59:59');
        } else {
            // Month
            $month_start = new DateTime($dt->format('Y-m-01'));
            $month_end = (clone $month_start)->modify('+1 month -1 day');
            $start_str = $month_start->format('Y-m-d 00:00:00');
            $end_str = $month_end->format('Y-m-d 23:59:59');
        }

        // Convert local time ranges to UTC for database query
        $start_utc = local_to_utc($start_str, $site_tz);
        $end_utc = local_to_utc($end_str, $site_tz);

        $stmt = $conn->prepare("
            SELECT s.id, s.start_time, s.bol_number, s.delivery_id,
                   s.arrival_time, s.departure_time, s.proof_of_delivery,
                   s.is_closed,
                   d.status_of_delivery,
                   (CASE WHEN w.id IS NOT NULL THEN 1 ELSE 0 END) as has_warranty,
                   (CASE WHEN ss.id IS NOT NULL THEN 1 ELSE 0 END) as has_safety,
                   w.status as warranty_status
            FROM site_scheduling s
            LEFT JOIN deliveries d ON s.delivery_id = d.id
            LEFT JOIN warranty_claims w ON s.id = w.scheduling_id
            LEFT JOIN site_safety ss ON s.id = ss.scheduling_id
            WHERE s.project_id = ?
              AND s.start_time BETWEEN ? AND ?
        ");
        $stmt->bind_param("iss", $project_id, $start_utc, $end_utc);
        $stmt->execute();
        $res = $stmt->get_result();
        while($row = $res->fetch_assoc()) {
            $row['start_time'] = utc_to_local($row['start_time'], $site_tz);
            $row['arrival_time'] = utc_to_local($row['arrival_time'], $site_tz);
            $row['departure_time'] = utc_to_local($row['departure_time'], $site_tz);
            $appointments[] = $row;
        }
        $stmt->close();

        json_response(['success' => true, 'appointments' => $appointments]);
    }

    // Get delivery data for scheduling
    if ($action === 'get_delivery_data') {
        $delivery_id = isset($_GET['delivery_id']) ? intval($_GET['delivery_id']) : 0;
        if ($delivery_id > 0) {
            $delivery_stmt = $conn->prepare("
                SELECT d.id, d.bol_number, d.wattage, d.quantity, d.supplier,
                       d.anticipated_delivery_date, d.status_of_delivery,
                       p.project_name
                FROM deliveries d
                LEFT JOIN projects p ON d.project_id = p.id
                WHERE d.id = ? AND d.project_id = ?
            ");
            $delivery_stmt->bind_param("ii", $delivery_id, $project_id);
            $delivery_stmt->execute();
            $delivery_res = $delivery_stmt->get_result();
            
            if ($delivery_data = $delivery_res->fetch_assoc()) {
                json_response(['success' => true, 'delivery' => $delivery_data]);
            } else {
                json_response(['success' => false, 'error' => 'Delivery not found']);
            }
            $delivery_stmt->close();
        } else {
            json_response(['success' => false, 'error' => 'Invalid delivery ID']);
        }
    }

    // List available deliveries for dropdown
    if ($action === 'list_deliveries') {
        $stmt = $conn->prepare(
            "SELECT id, bol_number, wattage, quantity, supplier
             FROM deliveries
             WHERE project_id = ?
               AND status_of_delivery = 'In Transit to Project'
               AND IFNULL(scheduled, 0) = 0
             ORDER BY bol_number"
        );
        $stmt->bind_param("i", $project_id);
        $stmt->execute();
        $res = $stmt->get_result();
        $deliveries = [];
        while ($row = $res->fetch_assoc()) {
            $deliveries[] = $row;
        }
        $stmt->close();
        json_response(['success' => true, 'deliveries' => $deliveries]);
    }

    // Add appointment (linked to delivery)
    if ($action === 'add_appointment') {
        $delivery_id = isset($_POST['delivery_id']) ? intval($_POST['delivery_id']) : 0;
        $start_local = $_POST['start_datetime'] ?? '';
        
        if (empty($start_local)) {
            json_response(['success' => false, 'error' => 'Please choose a time slot.']);
        }
        
        // Debug logging for timezone conversion
        error_log("Scheduling: Local time input: $start_local, Site timezone: $site_timezone");
        
        $start_utc = local_to_utc($start_local, $site_tz);
        error_log("Scheduling: Converted to UTC: $start_utc");

        // Regular appointment with delivery
        if ($delivery_id <= 0) {
            json_response(['success' => false, 'error' => 'Valid delivery required for appointment.']);
        }
        
        // Get delivery data
        $delivery_stmt = $conn->prepare("
            SELECT bol_number, wattage, quantity, supplier
            FROM deliveries 
            WHERE id = ? AND project_id = ?
        ");
        $delivery_stmt->bind_param("ii", $delivery_id, $project_id);
        $delivery_stmt->execute();
        $delivery_res = $delivery_stmt->get_result();
        
        if ($delivery_data = $delivery_res->fetch_assoc()) {
            $wattage_json = json_encode([['watt' => $delivery_data['wattage'], 'qty' => $delivery_data['quantity']]]);
            $ref_nums = trim($_POST['reference_numbers'] ?? '');
            $descr = trim($_POST['description'] ?? '');
            
            $stmt = $conn->prepare("
                INSERT INTO site_scheduling
                (project_id, delivery_id, start_time, bol_number, wattage,
                 reference_numbers, description, is_closed)
                VALUES (?, ?, ?, ?, ?, ?, ?, 0)
            ");
            $stmt->bind_param("iisssss",
                $project_id,
                $delivery_id,
                $start_utc,
                $delivery_data['bol_number'],
                $wattage_json,
                $ref_nums,
                $descr
            );
            
            if ($stmt->execute()) {
                // Update delivery as scheduled
                $update_delivery = $conn->prepare("UPDATE deliveries SET scheduled = 1 WHERE id = ?");
                $update_delivery->bind_param("i", $delivery_id);
                $update_delivery->execute();
                $update_delivery->close();
                
                json_response(['success' => true]);
            } else {
                json_response(['success' => false, 'error' => $stmt->error]);
            }
            $stmt->close();
        } else {
            json_response(['success' => false, 'error' => 'Delivery not found']);
        }
        $delivery_stmt->close();
    }

    // Get appointment details for editing
    if ($action === 'get_appointment') {
        $appointment_id = isset($_GET['appointment_id']) ? intval($_GET['appointment_id']) : 0;
        if ($appointment_id > 0) {
            $stmt = $conn->prepare("
                SELECT s.id, s.start_time, s.bol_number, s.delivery_id, s.wattage,
                       s.reference_numbers, s.description, s.arrival_time, s.departure_time,
                       s.proof_of_delivery, s.is_closed,
                       d.status_of_delivery, d.supplier, d.quantity as delivery_quantity,
                       CASE 
                           WHEN d.supplier LIKE '%-%' THEN TRIM(SUBSTRING_INDEX(d.supplier, '-', 1))
                           ELSE d.supplier
                       END AS manufacturer_name,
                       CASE
                           WHEN d.origin_type = 'warehouse' THEN w.name
                           WHEN d.origin_type = 'manufacturer' THEN 
                               CASE 
                                   WHEN d.supplier LIKE '%-%' THEN TRIM(SUBSTRING_INDEX(d.supplier, '-', 1))
                                   ELSE d.supplier
                               END
                           ELSE d.supplier
                       END AS origin_name
                FROM site_scheduling s
                LEFT JOIN deliveries d ON s.delivery_id = d.id
                LEFT JOIN warehouses w ON d.origin_type = 'warehouse' AND d.origin_id = w.id
                WHERE s.id = ? AND s.project_id = ?
            ");
            $stmt->bind_param("ii", $appointment_id, $project_id);
            $stmt->execute();
            $res = $stmt->get_result();
            
            if ($appointment = $res->fetch_assoc()) {
                // Convert UTC times to local
                $appointment['start_time'] = utc_to_local($appointment['start_time'], $site_tz);
                $appointment['arrival_time'] = utc_to_local($appointment['arrival_time'], $site_tz);
                $appointment['departure_time'] = utc_to_local($appointment['departure_time'], $site_tz);
                
                json_response(['success' => true, 'appointment' => $appointment]);
            } else {
                json_response(['success' => false, 'error' => 'Appointment not found']);
            }
            $stmt->close();
        } else {
            json_response(['success' => false, 'error' => 'Invalid appointment ID']);
        }
    }

    // Edit appointment
    if ($action === 'edit_appointment') {
        $appointment_id = isset($_POST['appointment_id']) ? intval($_POST['appointment_id']) : 0;
        $bol_number = trim($_POST['bol_number'] ?? '');
        $reference_numbers = trim($_POST['reference_numbers'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $arrival_time = trim($_POST['arrival_time'] ?? '');
        $departure_time = trim($_POST['departure_time'] ?? '');
        $has_damages = $_POST['has_damages'] ?? 'no';
        $has_safety_incident = $_POST['has_safety_incident'] ?? 'no';
        
        if ($appointment_id <= 0) {
            json_response(['success' => false, 'error' => 'Invalid appointment ID']);
        }
        
        $conn->begin_transaction();
        try {
            // Convert local times to UTC
            $arrival_utc = !empty($arrival_time) ? local_to_utc($arrival_time, $site_tz) : null;
            $departure_utc = !empty($departure_time) ? local_to_utc($departure_time, $site_tz) : null;
            
            // Handle POD upload
            $pod_path = null;
            if (isset($_FILES['proof_of_delivery']) && $_FILES['proof_of_delivery']['error'] === UPLOAD_ERR_OK) {
                $upload_dir = 'uploads/pods/';
                if (!is_dir($upload_dir)) {
                    mkdir($upload_dir, 0755, true);
                }
                
                $file_ext = strtolower(pathinfo($_FILES['proof_of_delivery']['name'], PATHINFO_EXTENSION));
                $allowed_exts = ['jpg', 'jpeg', 'png', 'pdf'];
                
                if (in_array($file_ext, $allowed_exts)) {
                    $filename = 'pod_' . $appointment_id . '_' . time() . '.' . $file_ext;
                    $pod_path = $upload_dir . $filename;
                    move_uploaded_file($_FILES['proof_of_delivery']['tmp_name'], $pod_path);
                }
            }
            
            // Update appointment
            $update_sql = "
                UPDATE site_scheduling 
                SET bol_number = ?, reference_numbers = ?, description = ?, 
                    arrival_time = ?, departure_time = ?";
            
            $params = [$bol_number, $reference_numbers, $description, $arrival_utc, $departure_utc];
            $param_types = "sssss";
            
            if ($pod_path) {
                $update_sql .= ", proof_of_delivery = ?";
                $params[] = $pod_path;
                $param_types .= "s";
            }
            
            $update_sql .= " WHERE id = ? AND project_id = ?";
            $params[] = $appointment_id;
            $params[] = $project_id;
            $param_types .= "ii";
            
            $stmt = $conn->prepare($update_sql);
            $stmt->bind_param($param_types, ...$params);
            
            if (!$stmt->execute()) {
                throw new Exception("Failed to update appointment: " . $stmt->error);
            }
            $stmt->close();
            
            // Handle damage reporting
            if ($has_damages === 'yes') {
                processDamageReport($conn, $appointment_id, $bol_number, $arrival_utc);
            } else {
                // Remove existing warranty claims if damages set to no
                $del_warranty = $conn->prepare("DELETE FROM warranty_claims WHERE scheduling_id = ?");
                $del_warranty->bind_param("i", $appointment_id);
                $del_warranty->execute();
                $del_warranty->close();
            }
            
            // Handle safety incident
            if ($has_safety_incident === 'yes') {
                processSafetyIncident($conn, $appointment_id, $bol_number);
            } else {
                // Remove existing safety incidents if set to no
                $del_safety = $conn->prepare("DELETE FROM site_safety WHERE scheduling_id = ?");
                $del_safety->bind_param("i", $appointment_id);
                $del_safety->execute();
                $del_safety->close();
            }
            
            // NEW: Update delivery status if appointment is completed (both arrival and departure times are set)
            if ($arrival_utc && $departure_utc) {
                // Get the delivery_id for this appointment
                $get_delivery_stmt = $conn->prepare("SELECT delivery_id FROM site_scheduling WHERE id = ? AND project_id = ?");
                $get_delivery_stmt->bind_param("ii", $appointment_id, $project_id);
                $get_delivery_stmt->execute();
                $get_delivery_stmt->bind_result($delivery_id_for_update);
                if ($get_delivery_stmt->fetch() && $delivery_id_for_update) {
                    $get_delivery_stmt->close();
                    
                    // Update delivery status to "Delivered to Project", set actual delivery date, and copy POD
                    $update_delivery_sql = "
                        UPDATE deliveries 
                        SET status_of_delivery = 'Delivered to Project', 
                            actual_delivery_date = ?";
                    $delivery_params = [$arrival_time]; // Use local arrival time for delivery date
                    $delivery_param_types = "s";
                    
                    // If POD was uploaded, copy it to the delivery record
                    if ($pod_path) {
                        $update_delivery_sql .= ", proof_of_delivery = ?";
                        $delivery_params[] = $pod_path;
                        $delivery_param_types .= "s";
                    }
                    
                    $update_delivery_sql .= " WHERE id = ?";
                    $delivery_params[] = $delivery_id_for_update;
                    $delivery_param_types .= "i";
                    
                    $update_delivery_stmt = $conn->prepare($update_delivery_sql);
                    $update_delivery_stmt->bind_param($delivery_param_types, ...$delivery_params);
                    
                    if (!$update_delivery_stmt->execute()) {
                        error_log("Failed to update delivery status: " . $update_delivery_stmt->error);
                    } else {
                        // Update associated pallet statuses to "Delivered to Project"
                        $pallet_status_update_count = 0;
                        $new_pallet_status = 'Delivered to Project';
                        
                        // Get all pallets associated with this delivery
                        $get_pallets_stmt = $conn->prepare("
                            SELECT dp.inventory_pallet_id 
                            FROM delivery_pallets dp 
                            WHERE dp.delivery_id = ?
                        ");
                        
                        if ($get_pallets_stmt) {
                            $get_pallets_stmt->bind_param("i", $delivery_id_for_update);
                            $get_pallets_stmt->execute();
                            $pallet_result = $get_pallets_stmt->get_result();
                            $pallet_ids_to_update = [];
                            
                            while ($pallet_row = $pallet_result->fetch_assoc()) {
                                $pallet_ids_to_update[] = $pallet_row['inventory_pallet_id'];
                            }
                            $get_pallets_stmt->close();
                            
                            // Update pallet statuses if we found any pallets
                            if (!empty($pallet_ids_to_update)) {
                                $placeholders = implode(',', array_fill(0, count($pallet_ids_to_update), '?'));
                                $types = 's' . str_repeat('i', count($pallet_ids_to_update)); // status + pallet IDs
                                
                                $update_pallets_stmt = $conn->prepare("
                                    UPDATE inventory_pallets 
                                    SET status = ? 
                                    WHERE id IN ($placeholders)
                                ");
                                
                                if ($update_pallets_stmt) {
                                    $update_pallets_stmt->bind_param($types, $new_pallet_status, ...$pallet_ids_to_update);
                                    if ($update_pallets_stmt->execute()) {
                                        $pallet_status_update_count = $update_pallets_stmt->affected_rows;
                                    }
                                    $update_pallets_stmt->close();
                                }
                            }
                        }
                        
                        // Log the successful update for debugging
                        error_log("Scheduling: Updated delivery {$delivery_id_for_update} to 'Delivered to Project' and {$pallet_status_update_count} associated pallets");
                    }
                    
                    $update_delivery_stmt->close();
                } else {
                    $get_delivery_stmt->close();
                }
            }
            
            $conn->commit();
            json_response(['success' => true]);
            
        } catch (Exception $e) {
            $conn->rollback();
            json_response(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    // Get appointment pallets
    if ($action === 'get_appointment_pallets') {
        $appointment_id = isset($_GET['appointment_id']) ? intval($_GET['appointment_id']) : 0;
        
        if ($appointment_id <= 0) {
            json_response(['success' => false, 'error' => 'Invalid appointment ID']);
        }
        
        // Get delivery_id from appointment
        $delivery_stmt = $conn->prepare("SELECT delivery_id FROM site_scheduling WHERE id = ? AND project_id = ?");
        $delivery_stmt->bind_param("ii", $appointment_id, $project_id);
        $delivery_stmt->execute();
        $delivery_stmt->bind_result($delivery_id);
        if (!$delivery_stmt->fetch()) {
            $delivery_stmt->close();
            json_response(['success' => false, 'error' => 'Appointment not found']);
        }
        $delivery_stmt->close();
        
        // Get pallets for this delivery
        $pallets = [];
        if ($delivery_id) {
            $pallet_stmt = $conn->prepare("
                SELECT ip.id, ip.pallet_identifier, ip.wattage, ip.quantity 
                FROM delivery_pallets dp 
                JOIN inventory_pallets ip ON dp.inventory_pallet_id = ip.id 
                WHERE dp.delivery_id = ? 
                ORDER BY ip.id
            ");
            $pallet_stmt->bind_param("i", $delivery_id);
            $pallet_stmt->execute();
            $pallet_result = $pallet_stmt->get_result();
            
            while ($pallet = $pallet_result->fetch_assoc()) {
                $pallets[] = $pallet;
            }
            $pallet_stmt->close();
        }
        
        json_response(['success' => true, 'pallets' => $pallets]);
    }
    
    // Check for existing damage reports
    if ($action === 'check_damages') {
        $appointment_id = isset($_GET['appointment_id']) ? intval($_GET['appointment_id']) : 0;
        
        if ($appointment_id <= 0) {
            json_response(['success' => false, 'error' => 'Invalid appointment ID']);
        }
        
        $check_stmt = $conn->prepare("SELECT COUNT(*) as count FROM warranty_claims WHERE scheduling_id = ?");
        $check_stmt->bind_param("i", $appointment_id);
        $check_stmt->execute();
        $check_stmt->bind_result($count);
        $check_stmt->fetch();
        $check_stmt->close();
        
        json_response(['success' => true, 'has_damages' => $count > 0]);
    }

    // Get existing damage data for appointment
    if ($action === 'get_damage_data') {
        $appointment_id = isset($_GET['appointment_id']) ? intval($_GET['appointment_id']) : 0;
        
        if ($appointment_id <= 0) {
            json_response(['success' => false, 'error' => 'Invalid appointment ID']);
        }
        
        // Get warranty claims for this appointment
        $damage_stmt = $conn->prepare("
            SELECT w.pallet_id, w.expected_quantity, w.actual_quantity, 
                   w.damaged_quantity, w.accepted_quantity, w.notes,
                   ip.pallet_identifier, ip.wattage
            FROM warranty_claims w
            LEFT JOIN inventory_pallets ip ON w.pallet_id = ip.id
            WHERE w.scheduling_id = ?
        ");
        $damage_stmt->bind_param("i", $appointment_id);
        $damage_stmt->execute();
        $damage_result = $damage_stmt->get_result();
        
        $damage_data = [];
        while ($row = $damage_result->fetch_assoc()) {
            $damage_data[] = $row;
        }
        $damage_stmt->close();
        
        json_response(['success' => true, 'damage_data' => $damage_data]);
    }

    // Delete appointment
    if ($action === 'delete_appointment') {
        $appointment_id = isset($_POST['appointment_id']) ? intval($_POST['appointment_id']) : 0;
        
        if ($appointment_id <= 0) {
            json_response(['success' => false, 'error' => 'Invalid appointment ID']);
        }
        
        $conn->begin_transaction();
        try {
            // First, check if this appointment has a delivery_id to update
            $check_stmt = $conn->prepare("SELECT delivery_id FROM site_scheduling WHERE id = ? AND project_id = ?");
            $check_stmt->bind_param("ii", $appointment_id, $project_id);
            $check_stmt->execute();
            $check_stmt->bind_result($delivery_id);
            $check_stmt->fetch();
            $check_stmt->close();
            
            // Delete associated warranty claims
            $del_warranty = $conn->prepare("DELETE FROM warranty_claims WHERE scheduling_id = ?");
            $del_warranty->bind_param("i", $appointment_id);
            $del_warranty->execute();
            $del_warranty->close();
            
            // Delete associated safety incidents
            $del_safety = $conn->prepare("DELETE FROM site_safety WHERE scheduling_id = ?");
            $del_safety->bind_param("i", $appointment_id);
            $del_safety->execute();
            $del_safety->close();
            
            // Delete the appointment
            $stmt = $conn->prepare("DELETE FROM site_scheduling WHERE id = ? AND project_id = ?");
            $stmt->bind_param("ii", $appointment_id, $project_id);
            
            if (!$stmt->execute()) {
                throw new Exception("Failed to delete appointment: " . $stmt->error);
            }
            $stmt->close();
            
            // If there was a delivery_id, update the delivery to mark as unscheduled
            if ($delivery_id) {
                $update_delivery = $conn->prepare("UPDATE deliveries SET scheduled = 0 WHERE id = ?");
                $update_delivery->bind_param("i", $delivery_id);
                $update_delivery->execute();
                $update_delivery->close();
            }
            
            $conn->commit();
            json_response(['success' => true]);
            
        } catch (Exception $e) {
            $conn->rollback();
            json_response(['success' => false, 'error' => $e->getMessage()]);
        }
    }
    
    exit();
}

// Main page display logic continues...
$view = $_GET['view'] ?? 'day';
$current_date = $_GET['date'] ?? date('Y-m-d');
$delivery_id = $_GET['delivery_id'] ?? 0;
$appointment_id = $_GET['appointment_id'] ?? 0;
$auto_edit = $_GET['auto_edit'] ?? 0;

// Pass operating hours to JS
$operating_hours_js = json_encode($days_oh);

include('header.php');
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Scheduling - <?php echo htmlspecialchars($project_name); ?></title>
    <link rel="stylesheet" href="portal.css">
    <link rel="icon" href="pictures/favicon.png" type="image/png">
    <link rel="shortcut icon" href="pictures/favicon.png" type="image/png">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        /* Modern CSS Reset and Base Styles */
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }
        
        body {
            font-family: 'Poppins', -apple-system, BlinkMacSystemFont, 'Segoe UI', system-ui, sans-serif;
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            min-height: 100vh;
            color: #2c3e50;
            line-height: 1.6;
        }
        
        main {
            margin: 0;
            padding: 20px;
        }
        
        h1 {
            font-weight: 600;
            color: #293E4C;
            margin-bottom: 30px;
            font-size: 2.2rem;
            text-align: left;
            margin-left: 20px;
        }
        
        /* Calendar Container */
        .calendar-container {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            border-radius: 24px;
            padding: 32px;
            box-shadow: 
                0 20px 40px rgba(0, 0, 0, 0.1),
                0 1px 3px rgba(0, 0, 0, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.2);
        }
        
        /* Calendar Header */
        .calendar-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 32px;
            padding: 0 8px;
        }
        
        .calendar-header-left, .calendar-header-right { 
            flex: 1; 
        }
        
        .calendar-header-center { 
            flex: 2; 
            text-align: center; 
            font-weight: 600;
            font-size: 1.5rem;
            color: #293E4C;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 20px;
        }
        
        .calendar-header-center button {
            padding: 8px 12px;
            background: linear-gradient(135deg, #488C9A, #3a6e7f);
            color: white;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            font-size: 1rem;
            transition: all 0.3s ease;
            box-shadow: 0 2px 8px rgba(72, 140, 154, 0.2);
            min-width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .calendar-header-center button:hover {
            background: linear-gradient(135deg, #3a6e7f, #293E4C);
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(72, 140, 154, 0.3);
        }
        
        .calendar-header-center button:active {
            transform: translateY(0);
        }
        
        /* View Toggle Buttons */
        .view-toggle {
            display: flex;
            gap: 4px;
            background: #f8f9fa;
            padding: 6px;
            border-radius: 16px;
            box-shadow: inset 0 2px 4px rgba(0, 0, 0, 0.06);
        }
        
        .view-toggle button {
            padding: 12px 24px;
            background: transparent;
            color: #6c757d;
            border: none;
            border-radius: 12px;
            cursor: pointer;
            font-weight: 600;
            font-size: 0.95rem;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            overflow: hidden;
        }
        
        .view-toggle button::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.4), transparent);
            transition: left 0.5s;
        }
        
        .view-toggle button:hover::before {
            left: 100%;
        }
        
        .view-toggle button.active {
            background: linear-gradient(135deg, #488C9A, #3a6e7f);
            color: white;
            box-shadow: 
                0 4px 15px rgba(72, 140, 154, 0.4),
                inset 0 1px 0 rgba(255, 255, 255, 0.2);
            transform: translateY(-1px);
        }
        
        .view-toggle button:hover:not(.active) {
            background: rgba(72, 140, 154, 0.1);
            color: #488C9A;
            transform: translateY(-1px);
        }
        
        /* Navigation Buttons - Now handled by .calendar-header-center button */
        
        /* Schedule Grid Styling */
        .schedule-grid, .week-grid, .month-grid {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
            background: white;
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 
                0 10px 30px rgba(0, 0, 0, 0.08),
                0 1px 3px rgba(0, 0, 0, 0.05);
        }
        
        .schedule-grid th, .week-grid th, .month-grid th {
            background: linear-gradient(135deg, #488C9A, #3a6e7f);
            color: white;
            padding: 16px 12px;
            text-align: center;
            font-weight: 600;
            font-size: 0.95rem;
            letter-spacing: 0.5px;
            text-transform: uppercase;
            border: none;
            position: relative;
        }
        
        /* Fix Time column header visibility */
        .schedule-grid th.time-col, .week-grid th.time-col {
            background: linear-gradient(135deg, #293E4C, #488C9A) !important;
            color: white !important;
            font-weight: 700;
        }
        
        .schedule-grid th::after, .week-grid th::after, .month-grid th::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            height: 2px;
            background: linear-gradient(90deg, #fbb040, #f39c12);
        }
        
        .schedule-grid td, .week-grid td, .month-grid td {
            border: 1px solid #e9ecef;
            padding: 12px;
            vertical-align: top;
            background: white;
            transition: all 0.2s ease;
            position: relative;
        }
        
        .schedule-grid td:hover, .week-grid td:hover, .month-grid td:hover {
            background: #f8f9fa;
            transform: scale(1.02);
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        }
        
        /* Time Column */
        .time-col {
            position: sticky !important;
            left: 0;
            z-index: 10;
            width: 100px;
            background: linear-gradient(135deg, #f8f9fa, #e9ecef) !important;
            text-align: center !important;
            font-weight: 600;
            font-size: 0.9rem;
            color: #495057;
            border-right: 2px solid #488C9A !important;
        }
        
        /* Appointment Slots */
        .appointment-slot {
            background: linear-gradient(135deg, #f8f9fa, #ffffff);
            padding: 12px;
            border-radius: 12px;
            cursor: pointer;
            margin: 4px 0;
            text-align: center;
            font-weight: 500;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            border: 2px solid transparent;
            position: relative;
            overflow: hidden;
        }
        
        .appointment-slot::before {
            content: '+';
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            font-size: 1.5rem;
            font-weight: bold;
            color: #488C9A;
            opacity: 0.6;
            transition: all 0.3s ease;
        }
        
        .appointment-slot:hover {
            background: linear-gradient(135deg, #488C9A, #3a6e7f);
            color: white;
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(72, 140, 154, 0.3);
            border-color: #488C9A;
        }
        
        .appointment-slot:hover::before {
            color: white;
            opacity: 1;
            transform: translate(-50%, -50%) scale(1.2);
        }
        
        /* Scheduled Appointments */
        .appointment-scheduled {
            background: linear-gradient(135deg, #e3f2fd, #bbdefb);
            border: 2px solid #488C9A;
            color: #293E4C;
            font-weight: 600;
            box-shadow: 0 4px 15px rgba(72, 140, 154, 0.2);
        }
        
        .appointment-scheduled::before {
            display: none;
        }
        
        .appointment-scheduled:hover {
            background: linear-gradient(135deg, #bbdefb, #90caf9);
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(72, 140, 154, 0.3);
        }
        
        /* Completed Appointments */
        .appointment-scheduled.completed {
            background: linear-gradient(135deg, #e8f5e8, #c8e6c9) !important;
            border-color: #4caf50 !important;
            color: #2e7d32 !important;
        }
        
        .appointment-scheduled.completed:hover {
            background: linear-gradient(135deg, #c8e6c9, #a5d6a7) !important;
            box-shadow: 0 8px 25px rgba(76, 175, 80, 0.3) !important;
        }
        
        /* Damaged/Issue Appointments */
        .appointment-scheduled.appointment-damaged {
            background: linear-gradient(135deg, #fff3e0, #ffe0b2) !important;
            border-color: #ff9800 !important;
            color: #e65100 !important;
        }
        
        .appointment-scheduled.appointment-damaged:hover {
            background: linear-gradient(135deg, #ffe0b2, #ffcc80) !important;
            box-shadow: 0 8px 25px rgba(255, 152, 0, 0.3) !important;
        }
        
        /* Closed Slots */
        .closed-slot {
            background: linear-gradient(135deg, #293E4C, #1a252f);
            color: white;
            pointer-events: none;
            cursor: default;
            border-radius: 12px;
            text-align: center;
            padding: 12px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 1px;
            font-size: 0.9rem;
        }
        
        /* Week View Enhancements */
        .week-scroll-wrapper {
            overflow-x: auto;
            border-radius: 16px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.08);
        }
        
        .week-grid .day-header {
            background: linear-gradient(135deg, #488C9A, #3a6e7f);
            color: white;
            font-weight: 600;
            padding: 16px 12px;
            min-width: 140px;
            text-align: center;
        }
        
        /* Month View Enhancements */
        .month-cell {
            position: relative;
            height: 120px;
            width: 14.28%;
            background: white;
            transition: all 0.2s ease;
        }
        
        .month-cell.other-month {
            color: #adb5bd;
            background: #f8f9fa;
        }
        
        .month-cell:hover {
            background: #f0f8ff;
            transform: scale(1.02);
        }
        
        .date-num {
            position: absolute;
            top: 8px;
            left: 8px;
            font-weight: 700;
            font-size: 1.1rem;
            color: #293E4C;
        }
        
        .month-appointment {
            background: linear-gradient(135deg, #488C9A, #3a6e7f);
            color: white;
            padding: 4px 8px;
            margin: 2px 4px;
            border-radius: 8px;
            font-size: 0.8rem;
            cursor: pointer;
            overflow: hidden;
            text-overflow: ellipsis;
            font-weight: 500;
            transition: all 0.2s ease;
            box-shadow: 0 2px 6px rgba(72, 140, 154, 0.3);
        }
        
        .month-appointment:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(72, 140, 154, 0.4);
        }
        
        .month-appointment.completed {
            background: linear-gradient(135deg, #4caf50, #388e3c);
        }
        
        .month-appointment.damaged {
            background: linear-gradient(135deg, #ff9800, #f57c00);
        }
        
        .month-appointment.closed {
            background: linear-gradient(135deg, #6c757d, #495057);
        }
        
        .add-appointment {
            position: absolute;
            bottom: 8px;
            right: 8px;
            width: 28px;
            height: 28px;
            background: linear-gradient(135deg, #488C9A, #3a6e7f);
            color: white;
            border-radius: 50%;
            text-align: center;
            line-height: 28px;
            cursor: pointer;
            font-size: 16px;
            font-weight: bold;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            box-shadow: 0 4px 12px rgba(72, 140, 154, 0.3);
        }
        
        .add-appointment:hover {
            background: linear-gradient(135deg, #3a6e7f, #293E4C);
            transform: scale(1.1) translateY(-2px);
            box-shadow: 0 6px 20px rgba(72, 140, 154, 0.4);
        }
        
        /* Modal Enhancements */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.6);
            backdrop-filter: blur(10px);
            align-items: center;
            justify-content: center;
            z-index: 9999;
            animation: fadeIn 0.3s ease;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        
        .modal-content {
            position: relative;
            background: white;
            padding: 32px;
            border-radius: 24px;
            max-width: 900px;
            width: 95%;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: 
                0 25px 50px rgba(0, 0, 0, 0.25),
                0 0 0 1px rgba(255, 255, 255, 0.05);
            transform: scale(0.9);
            animation: modalSlideIn 0.3s cubic-bezier(0.4, 0, 0.2, 1) forwards;
        }
        
        @keyframes modalSlideIn {
            to {
                transform: scale(1);
            }
        }
        
        .modal-content h2 {
            color: #293E4C;
            font-weight: 700;
            margin-bottom: 24px;
            font-size: 1.8rem;
            text-align: center;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #495057;
            font-size: 0.95rem;
        }
        
        .form-group input,
        .form-group textarea,
        .form-group select {
            width: 100%;
            padding: 12px 16px;
            border: 2px solid #e9ecef;
            border-radius: 12px;
            font-size: 1rem;
            transition: all 0.3s ease;
            background: white;
        }
        
        .form-group input:focus,
        .form-group textarea:focus,
        .form-group select:focus {
            outline: none;
            border-color: #488C9A;
            box-shadow: 0 0 0 3px rgba(72, 140, 154, 0.1);
            transform: translateY(-1px);
        }
        
        /* Button Styling */
        .login-btn {
            background: linear-gradient(135deg, #488C9A, #3a6e7f);
            color: white;
            border: none;
            padding: 14px 28px;
            cursor: pointer;
            border-radius: 12px;
            font-weight: 600;
            font-size: 1rem;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            box-shadow: 
                0 4px 15px rgba(72, 140, 154, 0.3),
                inset 0 1px 0 rgba(255, 255, 255, 0.2);
        }
        
        .login-btn:hover {
            transform: translateY(-2px);
            box-shadow: 
                0 8px 25px rgba(72, 140, 154, 0.4),
                inset 0 1px 0 rgba(255, 255, 255, 0.2);
        }
        
        .login-btn:active {
            transform: translateY(0);
        }
        
        /* Close Modal Button */
        .close-modal {
            position: absolute;
            right: 24px;
            top: 24px;
            font-size: 24px;
            font-weight: bold;
            color: #adb5bd;
            cursor: pointer;
            line-height: 1;
            transition: all 0.2s ease;
            width: 32px;
            height: 32px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .close-modal:hover {
            color: #488C9A;
            background: rgba(72, 140, 154, 0.1);
            transform: scale(1.1);
        }
        
        /* Breadcrumb Enhancement */
        .breadcrumb {
            background: rgba(255, 255, 255, 0.9);
            backdrop-filter: blur(10px);
            padding: 12px 20px;
            border-radius: 12px;
            margin-bottom: 20px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
        }
        
        .breadcrumb a {
            color: #488C9A;
            text-decoration: none;
            font-weight: 500;
            transition: all 0.2s ease;
        }
        
        .breadcrumb a:hover {
            color: #3a6e7f;
            text-decoration: underline;
        }
        
        /* Responsive Design */
        @media (max-width: 768px) {
            .calendar-header {
                flex-direction: column;
                gap: 16px;
            }
            
            .calendar-header-left,
            .calendar-header-center,
            .calendar-header-right {
                flex: none;
                width: 100%;
                text-align: center;
            }
            
            .view-toggle {
                justify-content: center;
            }
            
            h1 {
                font-size: 2rem;
            }
            
            .modal-content {
                margin: 20px;
                padding: 24px;
            }
        }
        
                 /* Tab Navigation */
         .tab-navigation {
             display: flex;
             border-bottom: 2px solid #e9ecef;
             margin-bottom: 24px;
             background: #f8f9fa;
             border-radius: 12px 12px 0 0;
             padding: 4px;
         }
         
         .tab-btn {
             flex: 1;
             padding: 12px 20px;
             background: transparent;
             border: none;
             cursor: pointer;
             font-weight: 500;
             color: #6c757d;
             border-radius: 8px;
             transition: all 0.3s ease;
             position: relative;
         }
         
         .tab-btn.active {
             background: linear-gradient(135deg, #488C9A, #3a6e7f);
             color: white;
             box-shadow: 0 2px 8px rgba(72, 140, 154, 0.3);
         }
         
         .tab-btn:hover:not(.active) {
             background: rgba(72, 140, 154, 0.1);
             color: #488C9A;
         }
         
         /* Tab Content */
         .tab-content {
             display: none;
         }
         
         .tab-content.active {
             display: block;
         }
         
         /* Radio Groups */
         .radio-group {
             display: flex;
             gap: 20px;
             margin-top: 10px;
         }
         
         .radio-label {
             display: flex;
             align-items: center;
             gap: 8px;
             cursor: pointer;
             padding: 8px 16px;
             border-radius: 8px;
             transition: all 0.2s ease;
             border: 2px solid transparent;
         }
         
         .radio-label:hover {
             background: rgba(72, 140, 154, 0.1);
         }
         
         .radio-label input[type="radio"] {
             width: 18px;
             height: 18px;
             accent-color: #488C9A;
         }
         
         .radio-label input[type="radio"]:checked + span {
             color: #488C9A;
             font-weight: 600;
         }
         
         /* Form Actions */
         .form-actions {
             display: flex;
             gap: 12px;
             justify-content: center;
             margin-top: 24px;
             padding-top: 20px;
             border-top: 1px solid #e9ecef;
         }
         
         .login-btn.danger {
             background: linear-gradient(135deg, #dc3545, #c82333);
         }
         
         .login-btn.danger:hover {
             background: linear-gradient(135deg, #c82333, #bd2130);
         }
         
         /* Damage/Safety Forms */
         .damage-form, .safety-form {
             background: #f8f9fa;
             border-radius: 12px;
             padding: 20px;
             margin-top: 16px;
             border: 2px solid #e9ecef;
             overflow-x: auto;
             width: 100%;
         }
         
         .pallet-damage-table {
             width: 100%;
             border-collapse: collapse;
             margin-top: 16px;
             background: white;
             border-radius: 8px;
             overflow-x: auto;
             display: block;
             box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
         }
         
         .pallet-damage-table thead,
         .pallet-damage-table tbody,
         .pallet-damage-table tr {
             display: table;
             width: 100%;
             table-layout: fixed;
         }
         
         .pallet-damage-table th,
         .pallet-damage-table td {
             padding: 12px;
             text-align: left;
             border-bottom: 1px solid #e9ecef;
         }
         
         .pallet-damage-table th {
             background: linear-gradient(135deg, #488C9A, #3a6e7f);
             color: white;
             font-weight: 600;
             font-size: 0.9rem;
         }
         
         .pallet-damage-table tr:hover {
             background: #f8f9fa;
         }

         .pallet-damage-table tr.damage-highlighted {
             background-color: #ffebee !important;
             border-left: 4px solid #f44336 !important;
         }

         .pallet-damage-table tr.damage-highlighted:hover {
             background-color: #ffcdd2 !important;
         }
         
         .damage-input {
             width: 70px;
             min-width: 70px;
             max-width: 70px;
             padding: 6px 8px;
             border: 1px solid #ddd;
             border-radius: 6px;
             text-align: center;
             font-size: 0.9rem;
         }
         
         .damage-input:focus {
             border-color: #488C9A;
             outline: none;
             box-shadow: 0 0 0 2px rgba(72, 140, 154, 0.2);
         }
         
         /* Notes field styling to prevent overflow */
         .pallet-damage-table input[type="text"] {
             width: 100%;
             max-width: 140px;
             min-width: 100px;
             padding: 6px 8px;
             font-size: 0.9rem;
             border: 1px solid #ddd;
             border-radius: 6px;
             word-wrap: break-word;
             overflow-wrap: break-word;
             box-sizing: border-box;
         }
         
         .pallet-damage-table input[type="text"]:focus {
             border-color: #488C9A;
             outline: none;
             box-shadow: 0 0 0 2px rgba(72, 140, 154, 0.2);
         }
         
         /* File Upload Styling */
         .file-upload-container {
             position: relative;
             margin-top: 16px;
         }
         
         .file-upload-button {
             display: inline-flex;
             align-items: center;
             justify-content: center;
             gap: 8px;
             padding: 14px 24px;
             background: linear-gradient(135deg, #488C9A, #3a6e7f);
             color: white !important;
             border-radius: 12px;
             cursor: pointer;
             transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
             border: none;
             font-weight: 600;
             font-size: 0.95rem;
             box-shadow: 0 4px 12px rgba(72, 140, 154, 0.3);
             position: relative;
             overflow: hidden;
             width: fit-content;
         }
         
         .file-upload-button::before {
             content: '';
             position: absolute;
             top: 0;
             left: -100%;
             width: 100%;
             height: 100%;
             background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.2), transparent);
             transition: left 0.5s;
         }
         
         .file-upload-button:hover::before {
             left: 100%;
         }
         
         .file-upload-button:hover {
             background: linear-gradient(135deg, #3a6e7f, #293E4C);
             transform: translateY(-2px);
             box-shadow: 0 6px 20px rgba(72, 140, 154, 0.4);
         }
         
         .file-upload-button:active {
             transform: translateY(0);
         }
         
         .file-upload-button input[type="file"] {
             position: absolute;
             opacity: 0;
             width: 100%;
             height: 100%;
             cursor: pointer;
             top: 0;
             left: 0;
         }
         
         /* Scrollbar Styling */
         ::-webkit-scrollbar {
             width: 8px;
             height: 8px;
         }
         
         ::-webkit-scrollbar-track {
             background: #f1f1f1;
             border-radius: 4px;
         }
         
         ::-webkit-scrollbar-thumb {
             background: linear-gradient(135deg, #488C9A, #3a6e7f);
             border-radius: 4px;
         }
         
         ::-webkit-scrollbar-thumb:hover {
             background: linear-gradient(135deg, #3a6e7f, #293E4C);
         }
    </style>
</head>
<body>
    <main>
        <!-- Breadcrumb -->
        <div class="breadcrumb" style="display: flex; margin-bottom: 20px; margin-top: 10px;">
            <a href="dashboard.php" style="color: #488C9A; text-decoration: none;">Dashboard</a>
            <span class="separator" style="margin: 0 8px; color: #6c757d;">&raquo;</span>
            <a href="project_overview.php?project_id=<?php echo $project_id; ?>" style="color: #488C9A; text-decoration: none;"><?php echo htmlspecialchars($project_name); ?></a>
            <span class="separator" style="margin: 0 8px; color: #6c757d;">&raquo;</span>
            <span>Scheduling</span>
        </div>

        <h1>Scheduling - <?php echo htmlspecialchars($project_name); ?></h1>

        <!-- Calendar Interface -->
        <div class="calendar-container">
            <div class="calendar-header">
                <div class="calendar-header-left">
                    <div class="view-toggle">
                        <button onclick="changeView('day')" id="dayBtn">Day</button>
                        <button onclick="changeView('week')" id="weekBtn">Week</button>
                        <button onclick="changeView('month')" id="monthBtn">Month</button>
                    </div>
                </div>
                <div class="calendar-header-center">
                    <button onclick="prev()">&lt;</button>
                    <span id="currentTitle">Loading...</span>
                    <button onclick="next()">&gt;</button>
                </div>
                <div class="calendar-header-right" style="text-align:right;">
                    <!-- Future: Add operating hours management button -->
                </div>
            </div>
            <div id="calendarContent">Loading calendar...</div>
        </div>

        <!-- Add Appointment Modal -->
        <div id="addAppointmentModal" class="modal">
            <div class="modal-content">
                <span class="close-modal" onclick="closeAddModal()">&times;</span>
                <h2>Schedule Delivery</h2>
                <form id="addApptForm">
                    <input type="hidden" name="action" value="add_appointment">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                    <input type="hidden" name="start_datetime" id="add_start_datetime">
                    <input type="hidden" name="delivery_id" id="add_delivery_id">

                    <div class="form-group" id="bolSelectGroup" style="display:none;">
                        <label for="bol_select">BOL Number</label>
                        <select id="bol_select"></select>
                    </div>

                    <div class="form-group" id="timeSelectionGroup" style="display: none;">
                        <label for="appointment_time">Select Time:</label>
                        <input type="time" id="appointment_time" name="appointment_time" value="08:00">
                    </div>

                    <div class="form-group">
                        <label>Delivery Date &amp; Time:</label>
                        <div id="selectedDateTimeDisplay"></div>
                    </div>

                    <div class="form-group" id="deliveryInfoGroup">
                        <label>Delivery Information:</label>
                        <div id="deliveryInfoDisplay" style="padding:10px; background:#f8f9fa; border:1px solid #ddd; border-radius:4px;">
                            <div>BOL#: <span id="deliveryBOL">-</span></div>
                            <div>Supplier: <span id="deliverySupplier">-</span></div>
                            <div>Wattage: <span id="deliveryWattage">-</span>W</div>
                            <div>Quantity: <span id="deliveryQuantity">-</span></div>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Reference Numbers (optional)</label>
                        <input type="text" name="reference_numbers">
                    </div>
                    
                    <div class="form-group">
                        <label>Description (optional)</label>
                        <textarea name="description" rows="3"></textarea>
                    </div>



                    <button type="submit" class="login-btn">Schedule Delivery</button>
                </form>
            </div>
        </div>

        <!-- Edit Appointment Modal -->
        <div id="editAppointmentModal" class="modal">
            <div class="modal-content">
                <span class="close-modal" onclick="closeEditModal()">&times;</span>
                <h2>Edit Appointment</h2>
                <form id="editApptForm">
                    <input type="hidden" name="action" value="edit_appointment">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                    <input type="hidden" name="appointment_id" id="edit_appointment_id">

                    <!-- Tab Navigation -->
                    <div class="tab-navigation">
                        <button type="button" class="tab-btn active" onclick="showTab('details')">Details</button>
                        <button type="button" class="tab-btn" onclick="showTab('delivery')">Delivery Status</button>
                        <button type="button" class="tab-btn" onclick="showTab('damages')">Damage/Safety <span style="font-size: 0.8em; color: #dc3545;">*</span></button>
                    </div>

                    <!-- Details Tab -->
                    <div id="details-tab" class="tab-content active">
                        <div class="form-group">
                            <label>BOL Number</label>
                            <input type="text" name="bol_number" id="edit_bol_number" readonly>
                        </div>

                        <div class="form-group">
                            <label>Delivery Date &amp; Time</label>
                            <div id="editDateTimeDisplay"></div>
                        </div>

                        <div class="form-group" id="deliveryInfoEditGroup" style="display: none;">
                            <label>Delivery Information</label>
                            <div style="padding:10px; background:#f8f9fa; border:1px solid #ddd; border-radius:4px;">
                                <div>Origin: <span id="editDeliveryOrigin">-</span></div>
                                <div>Manufacturer: <span id="editDeliveryManufacturer">-</span></div>
                                <div>Wattage: <span id="editDeliveryWattage">-</span>W</div>
                                <div>Quantity: <span id="editDeliveryQuantity">-</span></div>
                            </div>
                        </div>

                        <div class="form-group">
                            <label>Reference Numbers</label>
                            <input type="text" name="reference_numbers" id="edit_reference_numbers">
                        </div>
                        
                        <div class="form-group">
                            <label>Description</label>
                            <textarea name="description" id="edit_description" rows="3"></textarea>
                        </div>
                    </div>

                    <!-- Delivery Status Tab -->
                    <div id="delivery-tab" class="tab-content">
                        <div class="form-group">
                            <label>Expected Delivery Time</label>
                            <input type="datetime-local" id="edit_expected_time" readonly 
                                   style="background-color: #f8f9fa; color: #6c757d; cursor: not-allowed;">
                        </div>

                        <div class="form-group">
                            <label>Arrival Time</label>
                            <input type="datetime-local" name="arrival_time" id="edit_arrival_time" onchange="checkLateness()">
                            <div id="lateness_message" style="margin-top: 8px; font-weight: 600;"></div>
                        </div>

                        <div class="form-group">
                            <label>Departure Time</label>
                            <input type="datetime-local" name="departure_time" id="edit_departure_time">
                        </div>

                        <div class="form-group">
                            <label>Proof of Delivery</label>
                            <input type="file" name="proof_of_delivery" accept="image/*,.pdf">
                            <div id="existing_pod_container" style="margin-top: 10px;"></div>
                        </div>
                    </div>

                    <!-- Damage/Safety Tab -->
                    <div id="damages-tab" class="tab-content">
                        <!-- Damage Reporting -->
                        <div class="form-group">
                            <label>Damages or Quantity Discrepancies</label>
                            <div class="radio-group">
                                <label class="radio-label">
                                    <input type="radio" name="has_damages" value="no" checked onchange="toggleDamageFields(false)">
                                    <span>No</span>
                                </label>
                                <label class="radio-label">
                                    <input type="radio" name="has_damages" value="yes" onchange="toggleDamageFields(true)">
                                    <span>Yes</span>
                                </label>
                            </div>
                            <div id="damage_fields" style="display: none; margin-top: 20px;"></div>
                        </div>

                        <!-- Safety Incident -->
                        <div class="form-group">
                            <label>Safety Incident</label>
                            <div class="radio-group">
                                <label class="radio-label">
                                    <input type="radio" name="has_safety_incident" value="no" checked onchange="toggleSafetyFields(false)">
                                    <span>No</span>
                                </label>
                                <label class="radio-label">
                                    <input type="radio" name="has_safety_incident" value="yes" onchange="toggleSafetyFields(true)">
                                    <span>Yes</span>
                                </label>
                            </div>
                            <div id="safety_fields" style="display: none; margin-top: 20px;"></div>
                        </div>
                    </div>

                    <!-- Form Actions -->
                    <div class="form-actions">
                        <button type="submit" class="login-btn">Update Appointment</button>
                        <button type="button" class="login-btn danger" onclick="deleteAppointment()">Delete Appointment</button>
                    </div>
                </form>
            </div>
        </div>
    </main>

    <script>
        const projectId = <?php echo $project_id; ?>;
        const csrfToken = '<?php echo $csrf_token; ?>';
        const operatingHours = <?php echo $operating_hours_js; ?>;
        const deliveryId = <?php echo $delivery_id; ?>;
        const appointmentId = <?php echo $appointment_id; ?>;
        const autoEdit = <?php echo $auto_edit; ?>;
        const appointmentDuration = <?php echo $appointment_duration; ?>;
        const siteTimezone = '<?php echo $site_timezone; ?>';
        
        let currentView = '<?php echo $view; ?>';
        let currentDate = '<?php echo $current_date; ?>';
        
        document.addEventListener('DOMContentLoaded', function() {
            // Set active view button
            document.getElementById(currentView + 'Btn').classList.add('active');
            
            // Load appointments for current view
            loadAppointments(currentView, currentDate);
            
            // If delivery_id is provided, pre-load delivery data
            if (deliveryId > 0) {
                loadDeliveryDataForScheduling(deliveryId);
            }
            
            // If appointment_id and auto_edit are provided, automatically open the edit modal
            if (appointmentId > 0 && autoEdit == 1) {
                // Wait a moment for the appointments to load, then open the edit modal
                setTimeout(function() {
                    openEditModal(appointmentId);
                }, 1000);
            }
            
            // Setup form submission
            document.getElementById('addApptForm').addEventListener('submit', function(e) {
                e.preventDefault();
                submitAppointment();
            });
            
            // Setup edit form submission
            document.getElementById('editApptForm').addEventListener('submit', function(e) {
                e.preventDefault();
                submitEditAppointment();
            });
        });
        
        function changeView(newView) {
            // Update button states
            document.querySelectorAll('.view-toggle button').forEach(btn => btn.classList.remove('active'));
            document.getElementById(newView + 'Btn').classList.add('active');
            
            currentView = newView;
            loadAppointments(currentView, currentDate);
        }
        
        function prev() {
            let dt = parseLocalDate(currentDate);
            if (currentView === 'day') {
                dt.setDate(dt.getDate() - 1);
            } else if (currentView === 'week') {
                dt.setDate(dt.getDate() - 7);
            } else {
                dt.setMonth(dt.getMonth() - 1);
            }
            currentDate = formatDateKey(dt);
            loadAppointments(currentView, currentDate);
        }

        function next() {
            let dt = parseLocalDate(currentDate);
            if (currentView === 'day') {
                dt.setDate(dt.getDate() + 1);
            } else if (currentView === 'week') {
                dt.setDate(dt.getDate() + 7);
            } else {
                dt.setMonth(dt.getMonth() + 1);
            }
            currentDate = formatDateKey(dt);
            loadAppointments(currentView, currentDate);
        }
        
        function loadAppointments(view, date) {
            fetch(`scheduling.php?action=load_appointments&view=${view}&date=${date}&project_id=${projectId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        renderCalendar(view, date, data.appointments);
                    } else {
                        document.getElementById('calendarContent').innerHTML = 
                            '<p>Error loading appointments: ' + (data.error || 'Unknown error') + '</p>';
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    document.getElementById('calendarContent').innerHTML = '<p>Network error loading appointments.</p>';
                });
        }
        
        function renderCalendar(view, date, appointments) {
            const titleEl = document.getElementById('currentTitle');
            
            if (view === 'day') {
                titleEl.textContent = formatDate(date);
                renderDayView(date, appointments);
            } else if (view === 'week') {
                renderWeekView(date, appointments);
            } else {
                renderMonthView(date, appointments);
            }
        }
        
        function renderDayView(date, appointments) {
            const dayOfWeek = parseLocalDate(date).getDay();
            const dayHours = operatingHours[dayOfWeek];
            
            if (!dayHours || dayHours.length === 0) {
                document.getElementById('calendarContent').innerHTML = '<p>This day is closed.</p>';
                return;
            }
            
            // Get earliest and latest hours
            let earliestStart = '23:59:59';
            let latestEnd = '00:00:00';
            
            dayHours.forEach(hours => {
                if (hours.start_time < earliestStart) earliestStart = hours.start_time;
                if (hours.end_time > latestEnd) latestEnd = hours.end_time;
            });
            
            const startTime = new Date(date + 'T' + earliestStart);
            const endTime = new Date(date + 'T' + latestEnd);
            
            let html = '<table class="schedule-grid"><tr><th class="time-col">Time</th><th>Appointments</th></tr>';
            
            // Create appointment lookup
            const appointmentMap = {};
            appointments.forEach(apt => {
                const startKey = apt.start_time.replace(' ', 'T');
                appointmentMap[startKey] = apt;
            });
            
            // Generate time slots
            const current = new Date(startTime);
            while (current < endTime) {
                const timeStr = current.toLocaleTimeString([], {hour: '2-digit', minute: '2-digit'});
                const slotKey = formatLocalISO(current);
                
                html += '<tr>';
                html += `<td class="time-col">${timeStr}</td>`;
                
                if (isTimeSlotOpen(current, dayHours)) {
                    if (appointmentMap[slotKey]) {
                        html += renderAppointmentCell(appointmentMap[slotKey]);
                    } else {
                        const slotTime = formatLocalDateTime(current);
                        html += `<td><div class="appointment-slot" onclick="openAddModal('${slotTime}')">+</div></td>`;
                    }
                } else {
                    html += '<td><div class="closed-slot">Closed</div></td>';
                }
                
                html += '</tr>';
                current.setMinutes(current.getMinutes() + appointmentDuration);
            }
            
            html += '</table>';
            document.getElementById('calendarContent').innerHTML = html;
        }
        
        function renderWeekView(date, appointments) {
            const startOfWeek = parseLocalDate(date);
            const dayOfWeek = startOfWeek.getDay();
            startOfWeek.setDate(startOfWeek.getDate() - dayOfWeek);
            
            // Update title
            const endOfWeek = new Date(startOfWeek);
            endOfWeek.setDate(endOfWeek.getDate() + 6);
            document.getElementById('currentTitle').textContent =
                `${formatDate(formatDateKey(startOfWeek))} - ${formatDate(formatDateKey(endOfWeek))}`;
            
            let html = '<div class="week-scroll-wrapper"><table class="week-grid">';
            
            // Header with days
            html += '<thead><tr><th class="time-col">Time</th>';
            const days = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
            for (let i = 0; i < 7; i++) {
                const currentDay = new Date(startOfWeek);
                currentDay.setDate(startOfWeek.getDate() + i);
                const dayStr = formatDateKey(currentDay);
                html += `<th class="day-header">${days[i]}<br>${currentDay.getDate()}</th>`;
            }
            html += '</tr></thead><tbody>';
            
            // Find overall time range
            let earliestStart = '23:59:59';
            let latestEnd = '00:00:00';
            
            for (let day = 0; day < 7; day++) {
                const dayHours = operatingHours[day];
                if (dayHours && dayHours.length > 0) {
                    dayHours.forEach(hours => {
                        if (hours.start_time < earliestStart) earliestStart = hours.start_time;
                        if (hours.end_time > latestEnd) latestEnd = hours.end_time;
                    });
                }
            }
            
            // Create appointment lookup by day and time
            const appointmentsByDay = {};
            appointments.forEach(apt => {
                const aptDate = apt.start_time.split(' ')[0];
                const aptTime = apt.start_time.replace(' ', 'T');
                if (!appointmentsByDay[aptDate]) {
                    appointmentsByDay[aptDate] = {};
                }
                appointmentsByDay[aptDate][aptTime] = apt;
            });
            
            // Generate time slots
            const startTime = new Date('2000-01-01T' + earliestStart);
            const endTime = new Date('2000-01-01T' + latestEnd);
            
            while (startTime < endTime) {
                const timeStr = startTime.toLocaleTimeString([], {hour: '2-digit', minute: '2-digit'});
                html += `<tr><td class="time-col">${timeStr}</td>`;
                
                // For each day of the week
                for (let i = 0; i < 7; i++) {
                    const currentDay = new Date(startOfWeek);
                    currentDay.setDate(startOfWeek.getDate() + i);
                const dayStr = formatDateKey(currentDay);
                    const dayOfWeek = currentDay.getDay();
                    const dayHours = operatingHours[dayOfWeek];
                    
                    const slotDateTime = new Date(dayStr + 'T' + startTime.toTimeString().substring(0, 8));
                    const slotKey = formatLocalISO(slotDateTime);
                    
                    if (dayHours && dayHours.length > 0 && isTimeSlotOpen(slotDateTime, dayHours)) {
                        const appointment = appointmentsByDay[dayStr] && appointmentsByDay[dayStr][slotKey];
                        if (appointment) {
                            html += renderAppointmentCell(appointment);
                        } else {
                            const slotTime = formatLocalDateTime(slotDateTime);
                            html += `<td><div class="appointment-slot" onclick="openAddModal('${slotTime}')">+</div></td>`;
                        }
                    } else {
                        html += '<td><div class="closed-slot">Closed</div></td>';
                    }
                }
                
                html += '</tr>';
                startTime.setMinutes(startTime.getMinutes() + appointmentDuration);
            }
            
            html += '</tbody></table></div>';
            document.getElementById('calendarContent').innerHTML = html;
        }
        
        function renderMonthView(date, appointments) {
            const month = parseLocalDate(date);
            const year = month.getFullYear();
            const monthIndex = month.getMonth();
            
            // Update title
            document.getElementById('currentTitle').textContent = 
                month.toLocaleDateString('en-US', { month: 'long', year: 'numeric' });
            
            // Get first day of month and calculate start of calendar grid
            const firstDay = new Date(year, monthIndex, 1);
            const startOfGrid = new Date(firstDay);
            startOfGrid.setDate(startOfGrid.getDate() - firstDay.getDay());
            
            // Get last day of month and calculate end of calendar grid
            const lastDay = new Date(year, monthIndex + 1, 0);
            const endOfGrid = new Date(lastDay);
            endOfGrid.setDate(endOfGrid.getDate() + (6 - lastDay.getDay()));
            
            let html = '<table class="month-grid">';
            
            // Header with day names
            html += '<thead><tr>';
            const dayNames = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
            dayNames.forEach(day => {
                html += `<th>${day}</th>`;
            });
            html += '</tr></thead><tbody>';
            
            // Create appointment lookup by date
            const appointmentsByDate = {};
            appointments.forEach(apt => {
                // The start_time is already in local timezone from the server
                const aptDate = apt.start_time.split(' ')[0];
                if (!appointmentsByDate[aptDate]) {
                    appointmentsByDate[aptDate] = [];
                }
                appointmentsByDate[aptDate].push(apt);
            });
            
            // Generate calendar grid
            let currentDate = new Date(startOfGrid);
            while (currentDate <= endOfGrid) {
                html += '<tr>';
                
                // Generate one week
                for (let day = 0; day < 7; day++) {
                    const dateStr = formatDateKey(currentDate);
                    const dayNumber = currentDate.getDate();
                    const isCurrentMonth = currentDate.getMonth() === monthIndex;
                    const dayOfWeek = currentDate.getDay();
                    const dayHours = operatingHours[dayOfWeek];
                    
                    let cellClass = 'month-cell';
                    if (!isCurrentMonth) {
                        cellClass += ' other-month';
                    }
                    
                    let cellContent = `<div class="date-num">${dayNumber}</div>`;
                    
                    // Add appointments for this day
                    if (appointmentsByDate[dateStr]) {
                        appointmentsByDate[dateStr].forEach(apt => {
                            // The start_time is already in local timezone from server, just extract time part
                            const time = apt.start_time.split(' ')[1].substring(0, 5);
                            const bolNumber = apt.bol_number || 'No BOL';
                            let aptClass = 'month-appointment';
                            
                            if (apt.is_closed === '1') {
                                aptClass += ' closed';
                                cellContent += `<div class="${aptClass}">CLOSED</div>`;
                            } else {
                                const isCompleted = apt.arrival_time && apt.departure_time;
                                const hasIssues = apt.has_warranty === 1 || apt.has_safety === 1;
                                let statusIcon = '';
                                
                                if (isCompleted) {
                                    if (hasIssues) {
                                        aptClass += ' damaged';
                                        statusIcon = ' ⚠️';
                                    } else {
                                        aptClass += ' completed';
                                        statusIcon = ' ✅';
                                    }
                                } else if (hasIssues) {
                                    aptClass += ' damaged';
                                    statusIcon = ' ⚠️';
                                }
                                
                                cellContent += `<div class="${aptClass}" onclick="openEditModal(${apt.id})">${time} - ${bolNumber}${statusIcon}</div>`;
                            }
                        });
                    }
                    
                    // Add slot for new appointments if day is open
                    if (isCurrentMonth && dayHours && dayHours.length > 0) {
                        const slotTime = dateStr + ' ' + dayHours[0].start_time;
                        cellContent += `<div class="add-appointment" onclick="openAddModal('${slotTime}')">+</div>`;
                    }
                    
                    html += `<td class="${cellClass}">${cellContent}</td>`;
                    currentDate.setDate(currentDate.getDate() + 1);
                }
                
                html += '</tr>';
            }
            
            html += '</tbody></table>';
            document.getElementById('calendarContent').innerHTML = html;
        }
        
        function isTimeSlotOpen(dateTime, dayHours) {
            const timeStr = dateTime.toTimeString().substring(0, 8);
            return dayHours.some(hours => 
                timeStr >= hours.start_time && timeStr < hours.end_time
            );
        }
        
        function renderAppointmentCell(appointment) {
            let classes = ['appointment-slot', 'appointment-scheduled'];
            let displayText = appointment.bol_number || 'No BOL';
            
            if (appointment.is_closed === '1') {
                return '<td><div class="closed-slot">CLOSED</div></td>';
            }
            
            // Determine status and color based on completion and issues
            const isCompleted = appointment.arrival_time && appointment.departure_time;
            const hasIssues = appointment.has_warranty === 1 || appointment.has_safety === 1;
            
            if (isCompleted) {
                if (hasIssues) {
                    // Orange for completed deliveries with issues
                    classes.push('appointment-damaged');
                    displayText += ' ⚠️';
                } else {
                    // Green for completed deliveries without issues
                    classes.push('completed');
                    displayText += ' ✅';
                }
            } else if (hasIssues) {
                // Orange for incomplete deliveries with reported issues
                classes.push('appointment-damaged');
                displayText += ' ⚠️';
            }
            // Default blue for scheduled appointments without completion or issues
            
            return `<td><div class="${classes.join(' ')}" onclick="openEditModal(${appointment.id})">${displayText}</div></td>`;
        }
        
        function parseLocalDate(str) {
            if (str.includes('T') || str.includes(' ')) {
                return new Date(str.replace(' ', 'T'));
            }
            return new Date(str + 'T00:00:00');
        }

        function pad(n) {
            return String(n).padStart(2, '0');
        }

        function formatDateKey(date) {
            return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
        }

        function formatLocalISO(date) {
            return `${formatDateKey(date)}T${pad(date.getHours())}:${pad(date.getMinutes())}:${pad(date.getSeconds())}`;
        }

        function formatLocalDateTime(date) {
            return formatLocalISO(date).replace('T', ' ');
        }

        function formatDate(dateStr) {
            const date = parseLocalDate(dateStr);
            return date.toLocaleDateString('en-US', {
                weekday: 'long',
                year: 'numeric',
                month: 'long',
                day: 'numeric'
            });
        }

        function formatDateTime(dateStr) {
            const date = parseLocalDate(dateStr);
            return date.toLocaleString('en-US', {
                year: 'numeric',
                month: 'long',
                day: 'numeric',
                hour: '2-digit',
                minute: '2-digit'
            });
        }
        
        function openAddModal(datetime) {
            // Check if this is coming from month view (time is just date with start time)
            const isMonthView = currentView === 'month';

            if (isMonthView) {
                // For month view, show time selection
                const datePart = datetime.split(' ')[0]; // Get just the date part
                document.getElementById('add_start_datetime').value = datePart;
                document.getElementById('timeSelectionGroup').style.display = 'block';
                document.getElementById('selectedDateTimeDisplay').textContent = formatDate(datePart);
                const timeInput = document.getElementById('appointment_time');
                timeInput.onchange = function() {
                    const combined = datePart + ' ' + this.value;
                    document.getElementById('selectedDateTimeDisplay').textContent = formatDateTime(combined);
                };
            } else {
                // For day/week view, use the exact datetime
                document.getElementById('add_start_datetime').value = datetime;
                document.getElementById('timeSelectionGroup').style.display = 'none';
                document.getElementById('selectedDateTimeDisplay').textContent = formatDateTime(datetime);
            }

            // Show/hide delivery selection based on whether we have a delivery_id
            if (deliveryId > 0) {
                document.getElementById('add_delivery_id').value = deliveryId;
                document.getElementById('deliveryInfoGroup').style.display = 'block';
                document.getElementById('bolSelectGroup').style.display = 'none';
            } else {
                document.getElementById('deliveryInfoGroup').style.display = 'none';
                document.getElementById('bolSelectGroup').style.display = 'block';
                loadAvailableDeliveries();
            }

            document.getElementById('addAppointmentModal').style.display = 'flex';
        }
        
        function closeAddModal() {
            document.getElementById('addAppointmentModal').style.display = 'none';
        }
        
        function openEditModal(appointmentId) {
            // Fetch appointment details
            fetch(`scheduling.php?action=get_appointment&appointment_id=${appointmentId}&project_id=${projectId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const appointment = data.appointment;

                        const aptDate = appointment.start_time.split(' ')[0];
                        if (currentDate !== aptDate) {
                            currentDate = aptDate;
                            loadAppointments(currentView, currentDate);
                        }

                        // Populate form fields
                        document.getElementById('edit_appointment_id').value = appointment.id;
                        document.getElementById('edit_bol_number').value = appointment.bol_number || '';
                        document.getElementById('edit_reference_numbers').value = appointment.reference_numbers || '';
                        document.getElementById('edit_description').value = appointment.description || '';
                        document.getElementById('editDateTimeDisplay').textContent = formatDateTime(appointment.start_time);

                        // Format datetime for input fields
                        if (appointment.arrival_time) {
                            document.getElementById('edit_arrival_time').value = appointment.arrival_time.replace(' ', 'T').substring(0, 16);
                        } else {
                            // Default to expected delivery time if no arrival time is set
                            document.getElementById('edit_arrival_time').value = appointment.start_time.replace(' ', 'T').substring(0, 16);
                        }

                        if (appointment.departure_time) {
                            document.getElementById('edit_departure_time').value = appointment.departure_time.replace(' ', 'T').substring(0, 16);
                        } else {
                            document.getElementById('edit_departure_time').value = '';
                        }

                        // Show modal
                        document.getElementById('editAppointmentModal').style.display = 'flex';
                    } else {
                        alert('Error loading appointment: ' + (data.error || 'Unknown error'));
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Network error loading appointment.');
                });
        }
        
        function closeEditModal() {
            document.getElementById('editAppointmentModal').style.display = 'none';
        }
        
        function loadDeliveryDataForScheduling(deliveryId) {
            fetch(`scheduling.php?action=get_delivery_data&delivery_id=${deliveryId}&project_id=${projectId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const delivery = data.delivery;
                        document.getElementById('deliveryBOL').textContent = delivery.bol_number || 'No BOL';
                        document.getElementById('deliverySupplier').textContent = delivery.supplier || 'Unknown';
                        document.getElementById('deliveryWattage').textContent = delivery.wattage || '0';
                        document.getElementById('deliveryQuantity').textContent = delivery.quantity || '0';
                    }
                })
                .catch(error => {
                    console.error('Error loading delivery data:', error);
                });
        }

        function loadAvailableDeliveries() {
            fetch(`scheduling.php?action=list_deliveries&project_id=${projectId}`)
                .then(response => response.json())
                .then(data => {
                    const select = document.getElementById('bol_select');
                    select.innerHTML = '<option value="">Select BOL</option>';
                    if (data.success) {
                        data.deliveries.forEach(del => {
                            const opt = document.createElement('option');
                            opt.value = del.id;
                            opt.textContent = del.bol_number;
                            opt.dataset.supplier = del.supplier;
                            opt.dataset.wattage = del.wattage;
                            opt.dataset.quantity = del.quantity;
                            select.appendChild(opt);
                        });
                    }
                })
                .catch(err => console.error('Error loading deliveries', err));

            document.getElementById('bol_select').addEventListener('change', function() {
                const selected = this.options[this.selectedIndex];
                document.getElementById('add_delivery_id').value = this.value;
                if (this.value) {
                    document.getElementById('deliveryBOL').textContent = selected.textContent;
                    document.getElementById('deliverySupplier').textContent = selected.dataset.supplier || '-';
                    document.getElementById('deliveryWattage').textContent = selected.dataset.wattage || '0';
                    document.getElementById('deliveryQuantity').textContent = selected.dataset.quantity || '0';
                    document.getElementById('deliveryInfoGroup').style.display = 'block';
                } else {
                    document.getElementById('deliveryInfoGroup').style.display = 'none';
                }
            });
        }
        
        function submitAppointment() {
            const formData = new FormData(document.getElementById('addApptForm'));
            
            // Handle time selection for month view
            if (currentView === 'month') {
                const datePart = document.getElementById('add_start_datetime').value;
                const timePart = document.getElementById('appointment_time').value;
                const fullDateTime = datePart + ' ' + timePart + ':00';
                formData.set('start_datetime', fullDateTime);
                console.log('Month view scheduling:', fullDateTime); // Debug logging
            }
            
            fetch(`scheduling.php?project_id=${projectId}`, {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('Appointment scheduled successfully!');
                    closeAddModal();
                    loadAppointments(currentView, currentDate);
                } else {
                    alert('Error: ' + (data.error || 'Unknown error'));
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Network error occurred.');
            });
        }
        
        function submitEditAppointment() {
            const formData = new FormData(document.getElementById('editApptForm'));
            
            fetch(`scheduling.php?project_id=${projectId}`, {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('Appointment updated successfully!');
                    closeEditModal();
                    loadAppointments(currentView, currentDate);
                } else {
                    alert('Error: ' + (data.error || 'Unknown error'));
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Network error occurred.');
            });
        }
        
        function deleteAppointment() {
            const appointmentId = document.getElementById('edit_appointment_id').value;
            
            if (confirm('Are you sure you want to delete this appointment?')) {
                const formData = new FormData();
                formData.append('action', 'delete_appointment');
                formData.append('csrf_token', csrfToken);
                formData.append('appointment_id', appointmentId);
                
                fetch(`scheduling.php?project_id=${projectId}`, {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert('Appointment deleted successfully!');
                        closeEditModal();
                        loadAppointments(currentView, currentDate);
                    } else {
                        alert('Error: ' + (data.error || 'Unknown error'));
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Network error occurred.');
                });
            }
        }
        
        // Tab Management
        function showTab(tabName) {
            // Check if trying to access damages tab without required completion times
            if (tabName === 'damages') {
                const arrivalTime = document.getElementById('edit_arrival_time').value;
                const departureTime = document.getElementById('edit_departure_time').value;
                
                if (!arrivalTime || !departureTime) {
                    alert('Please enter both Arrival Time and Departure Time in the Delivery Status tab before reporting damages or safety incidents.\n\nDamage and safety reporting can only be done after delivery completion.');
                    return; // Don't switch to damages tab
                }
            }
            
            // Hide all tab contents
            document.querySelectorAll('.tab-content').forEach(tab => {
                tab.classList.remove('active');
            });
            
            // Remove active class from all tab buttons
            document.querySelectorAll('.tab-btn').forEach(btn => {
                btn.classList.remove('active');
            });
            
            // Show selected tab content
            document.getElementById(tabName + '-tab').classList.add('active');
            
            // Add active class to clicked tab button
            document.querySelector(`.tab-btn[onclick="showTab('${tabName}')"]`).classList.add('active');
        }
        
        // Damage Fields Management
        function toggleDamageFields(show) {
            const fields = document.getElementById('damage_fields');
            fields.style.display = show ? 'block' : 'none';
            
            if (show) {
                loadPalletDamageForm();
            }
        }
        
        // Safety Fields Management
        function toggleSafetyFields(show) {
            const fields = document.getElementById('safety_fields');
            fields.style.display = show ? 'block' : 'none';
            
            if (show) {
                fields.innerHTML = `
                    <div class="safety-form">
                        <div class="form-group">
                            <label>Safety Notes</label>
                            <textarea name="safety_notes" rows="3" placeholder="Describe the safety incident..."></textarea>
                        </div>
                        <div class="form-group">
                            <label>Report Driver?</label>
                            <div class="radio-group">
                                <label class="radio-label">
                                    <input type="radio" name="report_driver" value="no" checked>
                                    <span>No</span>
                                </label>
                                <label class="radio-label">
                                    <input type="radio" name="report_driver" value="yes">
                                    <span>Yes</span>
                                </label>
                            </div>
                        </div>
                        <div class="file-upload-container">
                            <label class="file-upload-button">
                                📷 Upload Safety Photos
                                <input type="file" name="safety_pictures[]" multiple accept="image/*">
                            </label>
                        </div>
                    </div>
                `;
            }
        }
        
        // Load Pallet Damage Form
        function loadPalletDamageForm() {
            const appointmentId = document.getElementById('edit_appointment_id').value;
            
            // Fetch associated pallets for this appointment
            fetch(`scheduling.php?action=get_appointment_pallets&appointment_id=${appointmentId}&project_id=${projectId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        renderPalletDamageForm(data.pallets);
                    } else {
                        console.error('Error loading pallets:', data.error);
                        // Fallback to basic form
                        renderBasicDamageForm();
                    }
                })
                .catch(error => {
                    console.error('Network error:', error);
                    renderBasicDamageForm();
                });
        }
        
        // Render Pallet-Level Damage Form
        function renderPalletDamageForm(pallets) {
            const container = document.getElementById('damage_fields');
            
            let html = `
                <div class="damage-form">
                    <h4>Pallet-Level Damage Reporting</h4>
                    <p>Report damages and quantity discrepancies for specific pallets:</p>
                    
                    <div style="overflow-x: auto; width: 100%;">
                        <table class="pallet-damage-table">
                            <thead>
                                <tr>
                                    <th style="min-width: 100px;">Pallet ID</th>
                                    <th style="min-width: 80px;">Wattage</th>
                                    <th style="min-width: 90px;">Expected Qty</th>
                                    <th style="min-width: 100px;">Actual QTY</th>
                                    <th style="min-width: 110px;">Damaged Modules</th>
                                    <th style="min-width: 120px;">Accepted Modules</th>
                                    <th style="min-width: 150px;">Notes</th>
                                </tr>
                            </thead>
                            <tbody>
            `;
            
            pallets.forEach(pallet => {
                html += `
                    <tr>
                        <td><strong>${pallet.pallet_identifier || 'ID: ' + pallet.id}</strong></td>
                        <td>${pallet.wattage}W</td>
                        <td>${pallet.quantity}</td>
                        <td>
                            <input type="number" 
                                   name="pallet_actual[${pallet.id}]" 
                                   class="damage-input" 
                                   min="0" 
                                   value="${pallet.quantity}"
                                   onchange="updateAcceptedQuantity(${pallet.id}, ${pallet.quantity})">
                        </td>
                        <td>
                            <input type="number" 
                                   name="pallet_damaged[${pallet.id}]" 
                                   class="damage-input" 
                                   min="0" 
                                   max="${pallet.quantity}" 
                                   value="0"
                                   onchange="updateAcceptedQuantity(${pallet.id}, ${pallet.quantity})">
                        </td>
                        <td>
                            <input type="number" 
                                   name="pallet_accepted[${pallet.id}]" 
                                   class="damage-input" 
                                   min="0" 
                                   value="${pallet.quantity}">
                        </td>
                        <td>
                            <input type="text" 
                                   name="pallet_notes[${pallet.id}]" 
                                   placeholder="Damage details..."
                                   class="notes-input">
                        </td>
                    </tr>
                `;
            });
            
            html += `
                        </tbody>
                    </table>
                    </div>
                    
                    <div class="file-upload-container">
                        <label class="file-upload-button">
                            📸 Upload Damage Photos
                            <input type="file" name="damage_pictures[]" multiple accept="image/*">
                        </label>
                    </div>
                </div>
            `;
            
            container.innerHTML = html;
            
            // Populate existing damage data if available
            if (window.existingDamageData) {
                setTimeout(() => populateExistingDamageData(), 100);
            }
        }
        
        // Fallback Basic Damage Form
        function renderBasicDamageForm() {
            const container = document.getElementById('damage_fields');
            container.innerHTML = `
                <div class="damage-form">
                    <h4>Damage Reporting</h4>
                    <div class="form-group">
                        <label>Damaged Modules Count</label>
                        <input type="number" name="damaged_count" min="0" value="0">
                    </div>
                    <div class="form-group">
                        <label>Damage Description</label>
                        <textarea name="damage_description" rows="3" placeholder="Describe the damage..."></textarea>
                    </div>
                    <div class="file-upload-container">
                        <label class="file-upload-button">
                            📸 Upload Damage Photos
                            <input type="file" name="damage_pictures[]" multiple accept="image/*">
                        </label>
                    </div>
                </div>
            `;
        }
        
        // Enhanced Submit Edit Appointment
        function submitEditAppointment() {
            const formData = new FormData(document.getElementById('editApptForm'));
            
            // Handle damage reporting
            const hasDamages = document.querySelector('input[name="has_damages"]:checked').value;
            if (hasDamages === 'yes') {
                // Collect pallet-level damage data
                const palletDamages = {};
                const palletActuals = {};
                const palletAccepted = {};
                const palletNotes = {};
                
                document.querySelectorAll('input[name^="pallet_damaged["]').forEach(input => {
                    const palletId = input.name.match(/\d+/)[0];
                    palletDamages[palletId] = input.value;
                });
                
                document.querySelectorAll('input[name^="pallet_actual["]').forEach(input => {
                    const palletId = input.name.match(/\d+/)[0];
                    palletActuals[palletId] = input.value;
                });
                
                document.querySelectorAll('input[name^="pallet_accepted["]').forEach(input => {
                    const palletId = input.name.match(/\d+/)[0];
                    palletAccepted[palletId] = input.value;
                });
                
                document.querySelectorAll('input[name^="pallet_notes["]').forEach(input => {
                    const palletId = input.name.match(/\d+/)[0];
                    palletNotes[palletId] = input.value;
                });
                
                formData.append('pallet_damages', JSON.stringify(palletDamages));
                formData.append('pallet_actuals', JSON.stringify(palletActuals));
                formData.append('pallet_accepted', JSON.stringify(palletAccepted));
                formData.append('pallet_notes', JSON.stringify(palletNotes));
            }
            
            // Handle safety incident
            const hasSafety = document.querySelector('input[name="has_safety_incident"]:checked').value;
            formData.append('has_safety_incident', hasSafety);
            
            fetch(`scheduling.php?project_id=${projectId}`, {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('Appointment updated successfully!');
                    closeEditModal();
                    loadAppointments(currentView, currentDate);
                } else {
                    alert('Error: ' + (data.error || 'Unknown error'));
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Network error occurred.');
            });
        }
        
        // Enhanced Open Edit Modal
        function openEditModal(appointmentId) {
            // Reset tabs to first tab
            showTab('details');

            // Fetch appointment details
            fetch(`scheduling.php?action=get_appointment&appointment_id=${appointmentId}&project_id=${projectId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const appointment = data.appointment;

                        const aptDate = appointment.start_time.split(' ')[0];
                        if (currentDate !== aptDate) {
                            currentDate = aptDate;
                            loadAppointments(currentView, currentDate);
                        }

                        // Populate form fields
                        document.getElementById('edit_appointment_id').value = appointment.id;
                        document.getElementById('edit_bol_number').value = appointment.bol_number || '';
                        document.getElementById('edit_reference_numbers').value = appointment.reference_numbers || '';
                        document.getElementById('edit_description').value = appointment.description || '';

                        document.getElementById('editDateTimeDisplay').textContent = formatDateTime(appointment.start_time);
                        
                        // Show delivery information if available
                        if (appointment.origin_name || appointment.delivery_quantity || appointment.wattage) {
                            document.getElementById('editDeliveryOrigin').textContent = appointment.origin_name || 'Unknown';
                            document.getElementById('editDeliveryManufacturer').textContent = appointment.manufacturer_name || 'Unknown';
                            
                            // Parse wattage from JSON if it exists, otherwise use delivery wattage
                            let wattageDisplay = 'Unknown';
                            if (appointment.wattage) {
                                try {
                                    const wattageData = JSON.parse(appointment.wattage);
                                    if (Array.isArray(wattageData) && wattageData.length > 0) {
                                        // Just show the wattage values, not the quantities
                                        wattageDisplay = wattageData.map(w => `${w.watt}`).join(', ');
                                    }
                                } catch (e) {
                                    // If parsing fails, try to use as direct value
                                    wattageDisplay = appointment.wattage;
                                }
                            }
                            document.getElementById('editDeliveryWattage').innerHTML = wattageDisplay;
                            document.getElementById('editDeliveryQuantity').textContent = appointment.delivery_quantity || 'Unknown';
                            document.getElementById('deliveryInfoEditGroup').style.display = 'block';
                        } else {
                            document.getElementById('deliveryInfoEditGroup').style.display = 'none';
                        }
                        
                        // Set expected time (read-only)
                        document.getElementById('edit_expected_time').value = appointment.start_time.replace(' ', 'T').substring(0, 16);
                        
                        // Format datetime for input fields
                        if (appointment.arrival_time) {
                            document.getElementById('edit_arrival_time').value = appointment.arrival_time.replace(' ', 'T').substring(0, 16);
                        } else {
                            // Default to expected delivery time if no arrival time is set
                            document.getElementById('edit_arrival_time').value = appointment.start_time.replace(' ', 'T').substring(0, 16);
                        }
                        
                        if (appointment.departure_time) {
                            document.getElementById('edit_departure_time').value = appointment.departure_time.replace(' ', 'T').substring(0, 16);
                        } else {
                            document.getElementById('edit_departure_time').value = '';
                        }
                        
                        // Check lateness after setting arrival time (including default)
                        checkLateness();
                        
                        // Add event listeners to update damage tab state when times change
                        document.getElementById('edit_arrival_time').addEventListener('change', function() {
                            checkLateness(); // This will also call updateDamageTabState
                        });
                        
                        document.getElementById('edit_departure_time').addEventListener('change', function() {
                            updateDamageTabState(); // Update tab state when departure time changes
                        });
                        
                        // Initial update of damage tab state
                        updateDamageTabState();
                        
                        // Handle existing POD
                        const podContainer = document.getElementById('existing_pod_container');
                        if (appointment.proof_of_delivery) {
                            podContainer.innerHTML = `<a href="${appointment.proof_of_delivery}" target="_blank">View Current POD</a>`;
                        } else {
                            podContainer.innerHTML = '';
                        }
                        
                        // Check for existing damage reports
                        checkExistingDamageReports(appointmentId);
                        
                        // Show modal
                        document.getElementById('editAppointmentModal').style.display = 'flex';
                    } else {
                        alert('Error loading appointment: ' + (data.error || 'Unknown error'));
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Network error loading appointment.');
                });
        }
        
        // Check for existing damage reports
        function checkExistingDamageReports(appointmentId) {
            // Clear any existing damage data first
            window.existingDamageData = null;
            
            fetch(`scheduling.php?action=check_damages&appointment_id=${appointmentId}&project_id=${projectId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.has_damages) {
                        document.querySelector('input[name="has_damages"][value="yes"]').checked = true;
                        toggleDamageFields(true);
                        // Load existing damage data
                        loadExistingDamageData(appointmentId);
                    } else {
                        // Ensure no damage is selected if no damage data exists
                        document.querySelector('input[name="has_damages"][value="no"]').checked = true;
                        toggleDamageFields(false);
                    }
                })
                .catch(error => {
                    console.error('Error checking damages:', error);
                });
        }

        // Load existing damage data for editing
        function loadExistingDamageData(appointmentId) {
            fetch(`scheduling.php?action=get_damage_data&appointment_id=${appointmentId}&project_id=${projectId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success && data.damage_data) {
                        // Store damage data for when the form is rendered
                        window.existingDamageData = data.damage_data;
                        // If form is already loaded, populate it
                        if (document.getElementById('damage_fields').innerHTML) {
                            populateExistingDamageData();
                        }
                    }
                })
                .catch(error => {
                    console.error('Error loading damage data:', error);
                });
        }

        // Populate existing damage data into the form
        function populateExistingDamageData() {
            if (!window.existingDamageData) return;

            window.existingDamageData.forEach(damage => {
                const palletId = damage.pallet_id;
                
                // Populate actual quantity received
                const actualInput = document.querySelector(`input[name="pallet_actual[${palletId}]"]`);
                if (actualInput) {
                    actualInput.value = damage.actual_quantity || 0;
                }
                
                // Populate damaged modules
                const damagedInput = document.querySelector(`input[name="pallet_damaged[${palletId}]"]`);
                if (damagedInput) {
                    damagedInput.value = damage.damaged_quantity || 0;
                }
                
                // Populate accepted modules
                const acceptedInput = document.querySelector(`input[name="pallet_accepted[${palletId}]"]`);
                if (acceptedInput) {
                    acceptedInput.value = damage.accepted_quantity || 0;
                }
                
                // Populate notes
                const notesInput = document.querySelector(`input[name="pallet_notes[${palletId}]"]`);
                if (notesInput) {
                    notesInput.value = damage.notes || '';
                }
                
                // Highlight problematic rows
                const row = damagedInput?.closest('tr');
                if (row) {
                    const expectedQty = parseInt(damage.expected_quantity || 0);
                    const actualQty = parseInt(damage.actual_quantity || 0);
                    const damagedQty = parseInt(damage.damaged_quantity || 0);
                    
                    if (damagedQty > 0 || expectedQty !== actualQty) {
                        row.classList.add('damage-highlighted');
                    }
                }
            });
        }

        // Update accepted quantity when actual or damaged quantities change
        function updateAcceptedQuantity(palletId, expectedQty) {
            const actualInput = document.querySelector(`input[name="pallet_actual[${palletId}]"]`);
            const damagedInput = document.querySelector(`input[name="pallet_damaged[${palletId}]"]`);
            const acceptedInput = document.querySelector(`input[name="pallet_accepted[${palletId}]"]`);
            
            if (actualInput && damagedInput && acceptedInput) {
                const actualQty = parseInt(actualInput.value) || 0;
                const damagedQty = parseInt(damagedInput.value) || 0;
                const acceptedQty = Math.max(0, actualQty - damagedQty);
                acceptedInput.value = acceptedQty;
            }
        }
        
        // Check if driver is late
        function checkLateness() {
            const expectedTime = document.getElementById('edit_expected_time').value;
            const arrivalTime = document.getElementById('edit_arrival_time').value;
            const messageDiv = document.getElementById('lateness_message');
            
            if (!arrivalTime || !expectedTime) {
                messageDiv.innerHTML = '';
                updateDamageTabState(); // Update tab state when times change
                return;
            }
            
            const expectedDate = new Date(expectedTime);
            const arrivalDate = new Date(arrivalTime);
            
            if (arrivalDate > expectedDate) {
                // Driver is late
                const diffMs = arrivalDate - expectedDate;
                const diffMinutes = Math.floor(diffMs / (1000 * 60));
                const diffHours = Math.floor(diffMinutes / 60);
                const remainingMinutes = diffMinutes % 60;
                
                let latenessText = '';
                if (diffHours > 0) {
                    latenessText = `${diffHours} hour${diffHours > 1 ? 's' : ''}`;
                    if (remainingMinutes > 0) {
                        latenessText += ` and ${remainingMinutes} minute${remainingMinutes > 1 ? 's' : ''}`;
                    }
                } else {
                    latenessText = `${diffMinutes} minute${diffMinutes > 1 ? 's' : ''}`;
                }
                
                messageDiv.innerHTML = `<span style="color: #dc3545; background: #f8d7da; padding: 6px 12px; border-radius: 6px; border: 1px solid #f5c6cb;">⚠️ Driver is <strong>${latenessText} late</strong></span>`;
            } else if (arrivalDate < expectedDate) {
                // Driver is early
                const diffMs = expectedDate - arrivalDate;
                const diffMinutes = Math.floor(diffMs / (1000 * 60));
                const diffHours = Math.floor(diffMinutes / 60);
                const remainingMinutes = diffMinutes % 60;
                
                let earlyText = '';
                if (diffHours > 0) {
                    earlyText = `${diffHours} hour${diffHours > 1 ? 's' : ''}`;
                    if (remainingMinutes > 0) {
                        earlyText += ` and ${remainingMinutes} minute${remainingMinutes > 1 ? 's' : ''}`;
                    }
                } else {
                    earlyText = `${diffMinutes} minute${diffMinutes > 1 ? 's' : ''}`;
                }
                
                messageDiv.innerHTML = `<span style="color: #155724; background: #d4edda; padding: 6px 12px; border-radius: 6px; border: 1px solid #c3e6cb;">✅ Driver arrived <strong>${earlyText} early</strong></span>`;
            } else {
                // Driver is on time
                messageDiv.innerHTML = `<span style="color: #155724; background: #d4edda; padding: 6px 12px; border-radius: 6px; border: 1px solid #c3e6cb;">✅ Driver arrived <strong>on time</strong></span>`;
            }
            
            // Update damage tab state whenever times change
            updateDamageTabState();
        }
        
        // Close modals when clicking outside
        window.addEventListener('click', function(event) {
            const addModal = document.getElementById('addAppointmentModal');
            const editModal = document.getElementById('editAppointmentModal');
            
            if (event.target === addModal) {
                closeAddModal();
            }
            if (event.target === editModal) {
                closeEditModal();
            }
        });
        
        // Update the visual state of the Damage/Safety tab based on completion status
        function updateDamageTabState() {
            const arrivalTime = document.getElementById('edit_arrival_time').value;
            const departureTime = document.getElementById('edit_departure_time').value;
            const damageTab = document.querySelector('.tab-btn[onclick="showTab(\'damages\')"]');
            const isDamageTabActive = document.getElementById('damages-tab').classList.contains('active');
            const asterisk = damageTab.querySelector('span');
            
            if (arrivalTime && departureTime) {
                // Enable the tab
                damageTab.style.opacity = '1';
                damageTab.style.cursor = 'pointer';
                damageTab.style.color = '';
                damageTab.title = '';
                if (asterisk) asterisk.style.display = 'none'; // Hide asterisk when enabled
            } else {
                // Disable the tab visually
                damageTab.style.opacity = '0.5';
                damageTab.style.cursor = 'not-allowed';
                damageTab.style.color = '#999';
                damageTab.title = 'Complete delivery (enter arrival and departure times) before reporting damages or safety incidents';
                if (asterisk) asterisk.style.display = 'inline'; // Show asterisk when disabled
                
                // If currently on damage tab and times are incomplete, switch to delivery status tab
                if (isDamageTabActive) {
                    showTab('delivery');
                }
            }
        }
    </script>
</body>
</html>

<?php
$conn->close();
?> 