<?php
session_name("logistics_session");
session_start();

/* ───────────────────────────── CSRF TOKEN ────────────────────────────── */
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

/* ─────────────────────────── SECURITY / AUTH ─────────────────────────── */
if (!isset($_SESSION['user_id']) ||
    !in_array($_SESSION['role'], ['global_admin', 'admin', 'customer_admin'])) {
    header("Location: unauthorized");
    exit();
}

$role = $_SESSION['role'] ?? 'user';
$is_customer_admin = ($role === 'customer_admin');

/* ───────────────────────── PARAMS & CONNECTION ───────────────────────── */
if (empty($_GET['delivery_id'])) {
    die("Delivery ID missing.");
}
$delivery_id = (int)$_GET['delivery_id'];
$project_id_from_url = isset($_GET['project_id']) ? (int)$_GET['project_id'] : null;

require_once '../config.php';
require_once 'document_helpers.php';
require_once 'delivery_notification_helpers.php';
require_once 'cost_helpers.php';
require_once 'milestone_helpers.php';
$conn = getDBConnection();
if (!$conn) die("Connection failed");

// Initialize messages
$success_message = $_SESSION['edit_delivery_success'] ?? null;
$error_message = $_SESSION['edit_delivery_error'] ?? null;
unset($_SESSION['edit_delivery_success'], $_SESSION['edit_delivery_error']);

/* ───────────────────────── CURRENT DELIVERY ──────────────────────────── */
$stmt = $conn->prepare("SELECT * FROM deliveries WHERE id = ?");
$stmt->bind_param("i", $delivery_id);
$stmt->execute();
$result = $stmt->get_result();
$delivery = $result->fetch_assoc();
$stmt->close();
if (!$delivery) {
    die("Delivery not found.");
}

$context_project_id = $project_id_from_url ?? $delivery['project_id'] ?? null;
$is_unassigned_delivery = is_null($delivery['project_id']);

// Fetch project name if a project_id is associated with the delivery
$project_name_for_title = null;
if ($delivery['project_id']) {
    $stmt_project_name = $conn->prepare("SELECT project_name FROM projects WHERE id = ?");
    if ($stmt_project_name) {
        $stmt_project_name->bind_param("i", $delivery['project_id']);
        $stmt_project_name->execute();
        $stmt_project_name->bind_result($fetched_project_name);
        if ($stmt_project_name->fetch()) {
            $project_name_for_title = $fetched_project_name;
        }
        $stmt_project_name->close();
    } else {
        error_log("Failed to prepare statement to fetch project name: " . $conn->error);
    }
}

// Resolve origin and destination display values
$origin_display = 'Manufacturer';
$origin_address_display = 'Address unavailable';
$destination_display = 'Unassigned / TBD';
$destination_address_display = 'Address unavailable';

$warehouse_lookup = [];
$project_lookup = [];

$warehouse_ids = [];
$project_ids = [];

if (!empty($delivery['warehouse_id'])) {
    $warehouse_ids[] = (int)$delivery['warehouse_id'];
}
if (!empty($delivery['origin_id']) && ($delivery['origin_type'] ?? '') === 'warehouse') {
    $warehouse_ids[] = (int)$delivery['origin_id'];
}
if (!empty($delivery['project_id'])) {
    $project_ids[] = (int)$delivery['project_id'];
}
if (!empty($delivery['origin_id']) && ($delivery['origin_type'] ?? '') === 'project') {
    $project_ids[] = (int)$delivery['origin_id'];
}

$warehouse_ids = array_values(array_unique(array_filter($warehouse_ids)));
$project_ids = array_values(array_unique(array_filter($project_ids)));

if (!function_exists('build_location_address')) {
    function build_location_address($primary_address, $street, $city, $state, $zip) {
        $primary_address = trim((string)$primary_address);
        if ($primary_address !== '') {
            return $primary_address;
        }

        $street = trim((string)$street);
        $city = trim((string)$city);
        $state = trim((string)$state);
        $zip = trim((string)$zip);

        $line_parts = [];
        if ($street !== '') {
            $line_parts[] = $street;
        }

        $city_state = implode(', ', array_filter([$city, $state], static function ($v) {
            return $v !== '';
        }));
        if ($zip !== '') {
            $city_state = trim($city_state . ($city_state !== '' ? ' ' : '') . $zip);
        }
        if ($city_state !== '') {
            $line_parts[] = $city_state;
        }

        return !empty($line_parts) ? implode(', ', $line_parts) : 'Address unavailable';
    }
}

if (!empty($warehouse_ids)) {
    $w_placeholders = implode(',', array_fill(0, count($warehouse_ids), '?'));
    $stmt_wh = $conn->prepare("
        SELECT id, name, address, street_address, city, state, zip_code
        FROM warehouses
        WHERE id IN ($w_placeholders)
    ");
    if ($stmt_wh) {
        $stmt_wh->bind_param(str_repeat('i', count($warehouse_ids)), ...$warehouse_ids);
        $stmt_wh->execute();
        $res_wh = $stmt_wh->get_result();
        while ($w = $res_wh->fetch_assoc()) {
            $warehouse_lookup[(int)$w['id']] = [
                'name' => $w['name'],
                'address' => build_location_address(
                    $w['address'] ?? '',
                    $w['street_address'] ?? '',
                    $w['city'] ?? '',
                    $w['state'] ?? '',
                    $w['zip_code'] ?? ''
                )
            ];
        }
        $stmt_wh->close();
    }
}

if (!empty($project_ids)) {
    $p_placeholders = implode(',', array_fill(0, count($project_ids), '?'));
    $stmt_pr = $conn->prepare("
        SELECT id, project_name, project_address, street_address, city, state, zip_code
        FROM projects
        WHERE id IN ($p_placeholders)
    ");
    if ($stmt_pr) {
        $stmt_pr->bind_param(str_repeat('i', count($project_ids)), ...$project_ids);
        $stmt_pr->execute();
        $res_pr = $stmt_pr->get_result();
        while ($p = $res_pr->fetch_assoc()) {
            $project_lookup[(int)$p['id']] = [
                'name' => $p['project_name'],
                'address' => build_location_address(
                    $p['project_address'] ?? '',
                    $p['street_address'] ?? '',
                    $p['city'] ?? '',
                    $p['state'] ?? '',
                    $p['zip_code'] ?? ''
                )
            ];
        }
        $stmt_pr->close();
    }
}

$origin_type = $delivery['origin_type'] ?? 'manufacturer';
$origin_id = !empty($delivery['origin_id']) ? (int)$delivery['origin_id'] : null;

if ($origin_type === 'warehouse' && $origin_id && isset($warehouse_lookup[$origin_id])) {
    $origin_display = 'Warehouse: ' . $warehouse_lookup[$origin_id]['name'];
    $origin_address_display = $warehouse_lookup[$origin_id]['address'];
} elseif ($origin_type === 'project' && $origin_id && isset($project_lookup[$origin_id])) {
    $origin_display = 'Project: ' . $project_lookup[$origin_id]['name'];
    $origin_address_display = $project_lookup[$origin_id]['address'];
} else {
    $origin_display = 'Manufacturer: ' . ($delivery['supplier'] ?? 'Unknown');
    $origin_address_display = 'Address managed in manufacturer profile';

    $supplier_name = trim((string)($delivery['supplier'] ?? ''));
    if ($supplier_name !== '') {
        $stmt_mfr = $conn->prepare("
            SELECT address, street_address, city, state, zip_code
            FROM manufacturers
            WHERE name = ?
            LIMIT 1
        ");
        if ($stmt_mfr) {
            $stmt_mfr->bind_param("s", $supplier_name);
            $stmt_mfr->execute();
            $res_mfr = $stmt_mfr->get_result();
            if ($mfr = $res_mfr->fetch_assoc()) {
                $origin_address_display = build_location_address(
                    $mfr['address'] ?? '',
                    $mfr['street_address'] ?? '',
                    $mfr['city'] ?? '',
                    $mfr['state'] ?? '',
                    $mfr['zip_code'] ?? ''
                );
            }
            $stmt_mfr->close();
        }
    }
}

$status_label = (string)($delivery['status_of_delivery'] ?? '');
$warehouse_statuses = ['In Transit to Warehouse', 'Delivered to Warehouse'];
$is_warehouse_destination_status = in_array($status_label, $warehouse_statuses, true);

if (
    $is_warehouse_destination_status &&
    !empty($delivery['warehouse_id']) &&
    isset($warehouse_lookup[(int)$delivery['warehouse_id']])
) {
    $destWh = $warehouse_lookup[(int)$delivery['warehouse_id']];
    $destination_display = 'Warehouse: ' . $destWh['name'];
    $destination_address_display = $destWh['address'];
} elseif (!empty($delivery['project_id']) && isset($project_lookup[(int)$delivery['project_id']])) {
    $destProject = $project_lookup[(int)$delivery['project_id']];
    $destination_display = 'Project: ' . $destProject['name'];
    $destination_address_display = $destProject['address'];
} elseif (!empty($delivery['warehouse_id']) && isset($warehouse_lookup[(int)$delivery['warehouse_id']])) {
    $destWh = $warehouse_lookup[(int)$delivery['warehouse_id']];
    $destination_display = 'Warehouse: ' . $destWh['name'];
    $destination_address_display = $destWh['address'];
}

/* ──────────────── FETCH ASSOCIATED PALLETS ──────────────── */
// Fetch ALREADY associated pallets with their current status
$associated_pallets = [];
$damaged_pallets = [];
$calculated_quantity = 0;
$stmt_assoc = $conn->prepare("
    SELECT ip.id, ip.pallet_identifier, ip.wattage, ip.quantity, ip.status
    FROM delivery_pallets dp 
    JOIN inventory_pallets ip ON dp.inventory_pallet_id = ip.id 
    WHERE dp.delivery_id = ? 
    ORDER BY ip.id
");
if ($stmt_assoc) {
    $stmt_assoc->bind_param("i", $delivery_id);
    $stmt_assoc->execute();
    $result_assoc = $stmt_assoc->get_result();
    while ($row = $result_assoc->fetch_assoc()) {
        $associated_pallets[] = $row;
        $calculated_quantity += (int)$row['quantity']; // Sum up quantities from all pallets
        
        // Track damaged pallets separately
        if (strtolower($row['status']) === 'damaged') {
            $damaged_pallets[] = $row;
        }
    }
    $stmt_assoc->close();
} else {
    $error_message .= " Error fetching associated pallets: " . $conn->error;
}

/* ───────────────────────── FETCH CARRIERS ─────────────────────────────── */
$all_carriers = [];
$account_id = null;
if ($role !== 'global_admin') {
    $stmtAcc = $conn->prepare("SELECT account_id FROM customer_account_users WHERE user_id = ? LIMIT 1");
    if ($stmtAcc) {
        $stmtAcc->bind_param("i", $_SESSION['user_id']);
        $stmtAcc->execute();
        $stmtAcc->bind_result($account_id);
        $stmtAcc->fetch();
        $stmtAcc->close();
    }
}
$carrier_acct_clause = ($role === 'global_admin') ? '' : ' AND (account_id IS NULL OR account_id = ?)';
$stmtCarriers = $conn->prepare("SELECT id, name, short_name, carrier_type, is_solterra_managed FROM carriers WHERE is_active = 1{$carrier_acct_clause} ORDER BY is_solterra_managed DESC, name ASC");
if ($stmtCarriers) {
    if ($role !== 'global_admin') {
        $stmtCarriers->bind_param("i", $account_id);
    }
    $stmtCarriers->execute();
    $resCarriers = $stmtCarriers->get_result();
    while ($cr = $resCarriers->fetch_assoc()) {
        $all_carriers[] = $cr;
    }
    $stmtCarriers->close();
}

/* ────────────────────────── UPDATE HANDLER ───────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_delivery'])) {

    /* CSRF */
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        die("Invalid request (CSRF). Refresh the page and try again.");
    }

    $conn->begin_transaction();
    try {
        /* Store old values for change detection */
        $old_status = $delivery['status_of_delivery'] ?? '';
        $old_anticipated_date = $delivery['anticipated_delivery_date'];
        $old_warehouse_arrival = $delivery['warehouse_arrival_date'];
        $old_actual_date = $delivery['actual_delivery_date'];
        $old_left_wh_date = $delivery['left_warehouse_date'];
        
        /* Collect inputs */
        $status             = $delivery['status_of_delivery']; // Managed elsewhere
        $quantity           = $calculated_quantity; // Use calculated quantity from pallets, not form input
        $bol_number         = $_POST['bol_number']         ?? '';

        $anticipated_date   = $_POST['anticipated_delivery_date'] ?: null;
        $warehouse_arrival  = $_POST['warehouse_arrival_date']    ?: null;
        $actual_date        = $_POST['actual_delivery_date']      ?: null;
        $left_wh_date       = $_POST['left_warehouse_date']       ?: null;

        $freight_cost       = isset($_POST['freight_cost']) ? (float)$_POST['freight_cost'] : $delivery['freight_cost'];
        $access_paid        = isset($_POST['accessorial_costs_paid']) ? (float)$_POST['accessorial_costs_paid'] : $delivery['accessorial_costs_paid'];
        $access_charged     = isset($_POST['accessorial_costs']) ? (float)$_POST['accessorial_costs'] : $delivery['accessorial_costs'];
        $customer_cost      = isset($_POST['customer_cost']) ? (float)$_POST['customer_cost'] : $delivery['customer_cost'];
        $miles              = isset($_POST['miles']) ? (float)$_POST['miles'] : $delivery['miles'];
        $carrier_id_val     = isset($_POST['carrier_id']) && $_POST['carrier_id'] !== '' ? intval($_POST['carrier_id']) : null;
        $carrier_ref_num    = trim($_POST['carrier_reference_number'] ?? ($delivery['carrier_reference_number'] ?? ''));

        if ($is_customer_admin) {
            // Customer admins can edit freight and paid accessorials only.
            $access_charged = (float)$delivery['accessorial_costs'];
            $customer_cost = (float)$delivery['customer_cost'];
            $miles = (float)$delivery['miles'];
        }

        // Handle existing POD removal
        $pod = $delivery['proof_of_delivery'];
        if (!empty($_POST['remove_pod']) && $_POST['remove_pod'] == '1') {
            if ($pod && file_exists($pod)) {
                @unlink($pod);
            }
            $pod = null;
        }
        
        // Handle file upload for new POD
        if (isset($_FILES['pod_file']) && $_FILES['pod_file']['error'] === UPLOAD_ERR_OK) {
            try {
                // Get delivery info
                $project_id = $delivery['project_id'];
                $warehouse_id = $delivery['warehouse_id'];
                
                // For warehouse deliveries, we need a project_id - skip if not available for now
                if (!$project_id) {
                    throw new Exception("POD upload requires a project association. Please assign delivery to a project first.");
                }
                
                // Upload POD using new helper function
                $result = uploadPODDocument(
                    $conn, 
                    $project_id, 
                    $delivery_id, 
                    $_FILES['pod_file'], 
                    $status, // Use the new status being set
                    $warehouse_id
                );
                
                // Remove old POD file if exists (from old structure)
                if ($pod && file_exists($pod)) {
                    @unlink($pod);
                }
                
                // Update POD path for backward compatibility
                $pod = $result['file_path'];
                
            } catch (Exception $e) {
                throw new Exception("POD upload failed: " . $e->getMessage());
            }
        }

        /* Update Delivery */
        $sql = "
            UPDATE deliveries SET
                quantity               = ?,
                bol_number             = ?,
                anticipated_delivery_date = ?,
                warehouse_arrival_date = ?,
                actual_delivery_date   = ?,
                left_warehouse_date    = ?,
                freight_cost           = ?,
                accessorial_costs_paid = ?,
                accessorial_costs      = ?,
                customer_cost          = ?,
                proof_of_delivery      = ?,
                miles                  = ?,
                carrier_id             = ?,
                carrier_reference_number = ?
            WHERE id = ?
        ";
        $stmt_update = $conn->prepare($sql);
        if (!$stmt_update) {
            throw new Exception("Prepare update failed: " . $conn->error);
        }

        $stmt_update->bind_param(
            "isssssddddsdisi",
            $quantity, $bol_number,
            $anticipated_date, $warehouse_arrival, $actual_date, $left_wh_date,
            $freight_cost, $access_paid, $access_charged, $customer_cost, $pod, $miles,
            $carrier_id_val, $carrier_ref_num,
            $delivery_id
        );
        if (!$stmt_update->execute()) {
            throw new Exception("Update delivery failed: " . $stmt_update->error);
        }
        $stmt_update->close();

        $status_changed = ($old_status !== $status);
        if ($status_changed) {
            trigger_delivery_milestones_for_status(
                $delivery_id,
                $status,
                $conn,
                $_SESSION['user_id'] ?? null,
                null,
                $actual_date
            );
        }

        /* Update Associated Pallet Statuses to Match Delivery Status */
        $pallet_status_update_count = 0;
        
        // Map delivery statuses to corresponding pallet statuses
        $status_mapping = [
            'Delivered to Project' => 'Delivered to Project',
            'Delivered to Warehouse' => 'In Warehouse',
            'In Transit to Project' => 'In Transit to Project', 
            'In Transit to Warehouse' => 'In Transit to Warehouse',
            'On Water' => 'On Water',
            'Cleared Customs' => 'Cleared Customs',
            'Pending' => 'At Manufacturer' // Assume pallets go back to manufacturer if delivery is pending
        ];
        
        if ($status_changed && isset($status_mapping[$status])) {
            $new_pallet_status = $status_mapping[$status];
            
            // Get all pallets associated with this delivery
            $stmt_get_pallets = $conn->prepare("
                SELECT dp.inventory_pallet_id 
                FROM delivery_pallets dp 
                WHERE dp.delivery_id = ?
            ");
            
            if ($stmt_get_pallets) {
                $stmt_get_pallets->bind_param("i", $delivery_id);
                $stmt_get_pallets->execute();
                $result_pallets = $stmt_get_pallets->get_result();
                $pallet_ids = [];
                
                while ($row = $result_pallets->fetch_assoc()) {
                    $pallet_ids[] = $row['inventory_pallet_id'];
                }
                $stmt_get_pallets->close();
                
                // Update pallet statuses - but preserve "Damaged" status pallets
                if (!empty($pallet_ids)) {
                    $placeholders = implode(',', array_fill(0, count($pallet_ids), '?'));
                    $types = 's' . str_repeat('i', count($pallet_ids)); // status + pallet IDs
                    
                    $stmt_update_pallets = $conn->prepare("
                        UPDATE inventory_pallets 
                        SET status = ? 
                        WHERE id IN ($placeholders) 
                          AND status != 'Damaged'
                    ");
                    
                    if ($stmt_update_pallets) {
                        $stmt_update_pallets->bind_param($types, $new_pallet_status, ...$pallet_ids);
                        if ($stmt_update_pallets->execute()) {
                            $pallet_status_update_count = $stmt_update_pallets->affected_rows;
                        }
                        $stmt_update_pallets->close();
                    }
                }
            }
        }

        /* ──────────────── UPDATE PALLET COSTS ──────────────── */
        // 1. Freight and Accessorial Cost Adjustment (Diff Logic)
        // Calculate old per-pallet costs
        $pallet_count = count($associated_pallets);
        if ($pallet_count > 0) {
            $old_freight_per_pallet = $delivery['freight_cost'] / $pallet_count;
            $old_accessorial_per_pallet = $delivery['accessorial_costs'] / $pallet_count; // Using charged amount

            $new_freight_per_pallet = $freight_cost / $pallet_count;
            $new_accessorial_per_pallet = $access_charged / $pallet_count;

            $freight_diff = $new_freight_per_pallet - $old_freight_per_pallet;
            $accessorial_diff = $new_accessorial_per_pallet - $old_accessorial_per_pallet;

            if (abs($freight_diff) > 0.001 || abs($accessorial_diff) > 0.001) {
                // Get all pallet IDs
                $all_pallet_ids = array_column($associated_pallets, 'id');
                if (!empty($all_pallet_ids)) {
                    $ids_str = implode(',', array_map('intval', $all_pallet_ids));
                    $sql_cost_update = "UPDATE inventory_pallets 
                                        SET freight_cost = freight_cost + ?, 
                                            accessorial_cost = accessorial_cost + ? 
                                        WHERE id IN ($ids_str)";
                    $stmt_cost = $conn->prepare($sql_cost_update);
                    if ($stmt_cost) {
                        $stmt_cost->bind_param("dd", $freight_diff, $accessorial_diff);
                        $stmt_cost->execute();
                        $stmt_cost->close();
                    }
                }
            }
        }

        // 2. Warehouse Cost Snapshot (Only on Departure)
        // If status changed from 'In Warehouse' (or similar) to a transit/delivered state,
        // we assume the pallets are leaving the warehouse and we should snapshot the storage cost.
        // We use the OLD status of the pallets to determine eligibility.
        
        // Define statuses that imply "At Warehouse"
        $warehouse_statuses = ['In Warehouse', 'Delivered to Warehouse'];
        // Define statuses that imply "Left Warehouse"
        $departed_statuses = ['In Transit to Project', 'Delivered to Project', 'In Transit to Warehouse']; // Moving to another WH is also a departure from current

        if ($status_changed && in_array($status, $departed_statuses)) {
            // Check which pallets were previously in warehouse
            $pallets_leaving_ids = [];
            foreach ($associated_pallets as $p) {
                if (in_array($p['status'], $warehouse_statuses)) {
                    $pallets_leaving_ids[] = $p['id'];
                }
            }

            if (!empty($pallets_leaving_ids)) {
                // Calculate cost for this warehouse stay
                // We need the warehouse_id (origin) and arrival/departure dates
                // For a delivery leaving a warehouse, the delivery's warehouse_id is usually the ORIGIN (if origin_type is warehouse)
                // OR we look at the pallet's current_warehouse_id (which might have just been updated? No, we updated it above).
                // Wait, we updated pallet status/location above. 
                // But we have the $associated_pallets array from BEFORE the update.
                
                // We need to know the warehouse they are leaving.
                // If the delivery has 'left_warehouse_date', use it. Otherwise use current date.
                $departure_date_calc = $left_wh_date ? $left_wh_date : date('Y-m-d H:i:s');
                
                // We need to fetch the arrival_date for each pallet to calculate cost
                // We can do this in a loop or batch. Loop is safer for individual arrival dates.
                
                $stmt_get_arrival = $conn->prepare("SELECT id, current_warehouse_id, arrival_date FROM inventory_pallets WHERE id = ?");
                $stmt_update_wh_cost = $conn->prepare("UPDATE inventory_pallets SET warehouse_cost = warehouse_cost + ? WHERE id = ?");
                
                foreach ($pallets_leaving_ids as $pid) {
                    $stmt_get_arrival->bind_param("i", $pid);
                    $stmt_get_arrival->execute();
                    $res_arrival = $stmt_get_arrival->get_result();
                    if ($row_arrival = $res_arrival->fetch_assoc()) {
                        $wh_id = $row_arrival['current_warehouse_id'];
                        $arr_date = $row_arrival['arrival_date'];
                        
                        if ($wh_id && $arr_date) {
                            $cost = calculate_pallet_storage_cost($wh_id, $arr_date, $departure_date_calc, $conn);
                            if ($cost > 0) {
                                $stmt_update_wh_cost->bind_param("di", $cost, $pid);
                                $stmt_update_wh_cost->execute();
                            }
                        }
                    }
                }
                $stmt_get_arrival->close();
                $stmt_update_wh_cost->close();
            }
        }

        $conn->commit();
        
        // Send notifications for any changes
        if ($old_status !== $status) {
            notify_delivery_status_change($delivery_id, $old_status, $status);
        }
        if ($old_anticipated_date !== $anticipated_date) {
            notify_delivery_date_change($delivery_id, 'anticipated_delivery', $old_anticipated_date, $anticipated_date);
        }
        if ($old_warehouse_arrival !== $warehouse_arrival) {
            notify_delivery_date_change($delivery_id, 'warehouse_arrival', $old_warehouse_arrival, $warehouse_arrival);
        }
        if ($old_actual_date !== $actual_date) {
            notify_delivery_date_change($delivery_id, 'actual_delivery', $old_actual_date, $actual_date);
        }
        if ($old_left_wh_date !== $left_wh_date) {
            notify_delivery_date_change($delivery_id, 'left_warehouse', $old_left_wh_date, $left_wh_date);
        }
        
        $success_msg = "Delivery details updated successfully.";
        if ($pallet_status_update_count > 0) {
            $success_msg .= " Also updated status for $pallet_status_update_count associated pallet(s) to '$new_pallet_status'.";
        }
        
        // Add note about preserved damaged pallets
        $preserved_damaged_count = count($damaged_pallets);
        if ($preserved_damaged_count > 0) {
            $success_msg .= " Note: $preserved_damaged_count damaged pallet(s) maintained their 'Damaged' status.";
        }
        $_SESSION['edit_delivery_success'] = $success_msg;

        // Redirect back to THIS edit page to remain in context
        header(
            "Location: edit_delivery.php?delivery_id={$delivery_id}" .
            ($project_id_from_url ? "&project_id={$project_id_from_url}" : "")
        );
        exit;

    } catch (Exception $e) {
        $conn->rollback();
        $_SESSION['edit_delivery_error'] = "Error updating delivery: " . $e->getMessage();
        header(
            "Location: edit_delivery.php?delivery_id={$delivery_id}" .
            ($project_id_from_url ? "&project_id={$project_id_from_url}" : "")
        );
        exit;
    }
}

/* ─────────────────────────── VIEW (FORM) ─────────────────────────────── */
$tracker_params = [];
if (!empty($delivery['project_id'])) {
    $tracker_params['project_id'] = (int)$delivery['project_id'];
} elseif (!empty($project_id_from_url)) {
    $tracker_params['project_id'] = (int)$project_id_from_url;
}
$tracker_url = 'view_project.php' . (!empty($tracker_params) ? ('?' . http_build_query($tracker_params)) : '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Edit Delivery
<?php 
    if ($project_name_for_title) {
        echo " – For " . htmlspecialchars($project_name_for_title);
    } elseif ($is_unassigned_delivery) {
        echo " – Unassigned";
    } 
?>
</title>
<link rel="stylesheet" href="portal.css">
<link rel="icon" href="pictures/favicon.png">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;500;600;700&display=swap" rel="stylesheet">

<style>
  body {
      background: #f8f9fa;
      font-family: 'Poppins', sans-serif;
  }


  .edit-header {
      background: linear-gradient(135deg, #f1f8fa 0%, #ffffff 100%);
      border-radius: 24px;
      padding: 28px 32px;
      margin-bottom: 24px;
      box-shadow: 0 8px 28px rgba(0, 0, 0, 0.06);
      border: 1px solid rgba(72, 140, 154, 0.12);
      position: relative;
      overflow: hidden;
  }

  .edit-header::before {
      content: '';
      position: absolute;
      left: 0;
      top: 0;
      right: 0;
      height: 4px;
      background: linear-gradient(90deg, #488C9A 0%, #293E4C 100%);
  }

  .edit-header-top {
      display: flex;
      align-items: flex-start;
      justify-content: space-between;
      gap: 20px;
      flex-wrap: wrap;
  }

  .edit-header h1 {
      margin: 0;
      font-size: 2.1em;
      font-weight: 700;
      color: #293E4C;
  }

  .edit-subtitle {
      margin-top: 8px;
      color: #64748b;
      font-weight: 500;
      margin-bottom: 0;
  }

  .header-meta {
      display: flex;
      gap: 10px;
      flex-wrap: wrap;
      align-items: center;
  }

  .meta-pill {
      padding: 8px 12px;
      border-radius: 999px;
      font-size: 0.82em;
      font-weight: 600;
      border: 1px solid rgba(72, 140, 154, 0.18);
      color: #34515f;
      background: rgba(72, 140, 154, 0.08);
  }

  .content-card {
      background: linear-gradient(135deg, #ffffff 0%, #f7fbfd 100%);
      border-radius: 18px;
      border: 1px solid rgba(72, 140, 154, 0.12);
      box-shadow: 0 8px 24px rgba(0, 0, 0, 0.05);
      padding: 18px 20px;
      margin-bottom: 20px;
  }

  .message-stack .message {
      border-radius: 10px;
      padding: 12px 14px;
      margin-bottom: 12px;
      font-weight: 500;
  }

  .success-message {
      background-color: #d4edda;
      color: #155724;
      border: 1px solid #c3e6cb;
  }

  .error-message {
      background-color: #f8d7da;
      color: #721c24;
      border: 1px solid #f5c6cb;
  }

  .form-columns {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 24px;
      margin-top: 8px;
  }

  .form-container {
      margin-top: 6px;
  }

  .column-left,
  .column-right {
      display: flex;
      flex-direction: column;
      gap: 0;
  }

  fieldset {
      border: 1px solid rgba(72, 140, 154, 0.18);
      border-radius: 14px;
      padding: 18px;
      margin-bottom: 16px;
      background: #fff;
      position: relative;
      height: fit-content;
  }

  legend {
      font-weight: 700;
      font-size: 0.95em;
      color: #293E4C;
      padding: 0 10px;
      margin-left: 8px;
  }

  label {
      display: block;
      margin-bottom: 6px;
      font-weight: 600;
      color: #334155;
      font-size: 0.9em;
  }

  .details-grid {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 2px 14px;
  }

  .details-grid .span-2 {
      grid-column: 1 / -1;
  }

  .location-row {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 12px;
      margin-bottom: 8px;
  }

  .location-card {
      border: 1px solid rgba(72, 140, 154, 0.2);
      border-radius: 12px;
      padding: 12px 12px 2px;
      background: #f8fbfd;
  }

  .location-card h4 {
      margin: 0 0 8px;
      color: #293E4C;
      font-size: 0.9em;
      font-weight: 700;
  }

  .accessorial-help {
      margin-top: -8px;
      margin-bottom: 14px;
      font-size: 0.82em;
      color: #6b7280;
      font-weight: 500;
  }

  input[type=text],
  input[type=number],
  input[type=date],
  select,
  input[type=file] {
      width: 100%;
      padding: 11px 12px;
      margin-bottom: 14px;
      border: 2px solid rgba(72, 140, 154, 0.14);
      border-radius: 10px;
      box-sizing: border-box;
      font-family: 'Poppins', sans-serif;
      font-size: 0.92em;
  }

  input:focus,
  select:focus {
      outline: none;
      border-color: #488C9A;
      box-shadow: 0 0 0 3px rgba(72, 140, 154, 0.14);
  }

  input[readonly] {
      background-color: #f8fafc;
      color: #475569;
      font-weight: 500;
  }

  .readonly-input {
      border-style: dashed;
  }

  .form-help {
      margin-top: -8px;
      margin-bottom: 14px;
      font-size: 0.82em;
      color: #6b7280;
      font-weight: 500;
  }

  .readonly-note {
      margin-top: -8px;
      margin-bottom: 12px;
      font-size: 0.8em;
      color: #6b7280;
      font-weight: 500;
  }

  .table-responsive {
      width: 100%;
      overflow-x: auto;
  }

  #associatedPalletsTable {
      width: 100%;
      border-collapse: collapse;
  }

  #associatedPalletsTable th,
  #associatedPalletsTable td {
      padding: 12px;
      border-bottom: 1px solid rgba(72, 140, 154, 0.12);
  }

  #associatedPalletsTable thead th {
      background: rgba(72, 140, 154, 0.07);
      font-weight: 600;
  }

  .manage-pallets-button {
      background: linear-gradient(135deg, #488C9A 0%, #3A6E7F 100%);
      color: white;
      padding: 8px 12px;
      font-size: 0.85em;
      border: none;
      border-radius: 8px;
      cursor: pointer;
      text-decoration: none;
      display: inline-flex;
      align-items: center;
      gap: 6px;
  }

  .manage-pallets-button:hover {
      background: linear-gradient(135deg, #3A6E7F 0%, #293E4C 100%);
  }

  .damaged-card {
      border: 2px solid #e74c3c;
      background: #fdf2f2;
  }

  .damaged-table {
      width: 100%;
      border-collapse: collapse;
  }

  .damaged-table th,
  .damaged-table td {
      padding: 8px;
      border: 1px solid #f5c6cb;
      text-align: left;
  }

  .damaged-table thead tr {
      background: #f8d7da;
  }

  .damaged-status-pill {
      background: #e74c3c;
      color: #fff;
      padding: 3px 8px;
      border-radius: 4px;
      font-size: 0.88em;
      font-weight: 600;
  }

  .quantity-note {
      font-size: 0.83em;
      color: #64748b;
      font-style: italic;
      margin-top: -8px;
      margin-bottom: 14px;
  }

  .pod-current-link {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      color: #0f766e;
      text-decoration: none;
      font-weight: 600;
      margin-bottom: 10px;
  }

  .pod-current-link:hover {
      text-decoration: underline;
  }

  .checkbox-inline {
      display: flex;
      align-items: center;
      gap: 10px;
      margin-bottom: 14px;
      font-weight: 600;
      color: #334155;
  }

  .checkbox-inline input[type="checkbox"] {
      width: auto;
      margin: 0;
  }

  .section-title {
      margin: 0 0 12px;
      color: #293E4C;
      font-size: 1.08em;
      font-weight: 700;
  }

  button.form-submit-button {
      background: linear-gradient(135deg, #488C9A 0%, #3A6E7F 100%);
      color: #fff;
      padding: 12px 18px;
      border: none;
      border-radius: 10px;
      cursor: pointer;
      font-size: 0.95em;
      font-weight: 600;
      display: block;
      width: fit-content;
      margin: 20px auto 0;
      grid-column: 1 / -1;
  }

  button.form-submit-button:hover {
      background: linear-gradient(135deg, #3A6E7F 0%, #293E4C 100%);
  }

  @media (max-width: 960px) {
      .form-columns {
          grid-template-columns: 1fr;
          gap: 16px;
      }

      .details-grid {
          grid-template-columns: 1fr;
      }

      .details-grid .span-2 {
          grid-column: auto;
      }

      .location-row {
          grid-template-columns: 1fr;
      }

      .edit-header {
          padding: 22px 18px;
      }

      .edit-header h1 {
          font-size: 1.7em;
      }
  }
</style>
</head>
<body>

<?php include 'header.php'; ?>
<main class="page-main">

    <?php
        require_once 'components/breadcrumbs.php';
        echo slp_render_breadcrumbs([
            'current_label' => 'Edit Delivery',
            'extra' => [ ['label' => 'Delivery Tracker', 'url' => $tracker_url] ]
        ]);
    ?>

<section class="edit-header">
  <div class="edit-header-top">
    <div>
      <h1>Edit Delivery</h1>
      <p class="edit-subtitle">
        <?php
          if ($project_name_for_title) {
              echo "Project: " . htmlspecialchars($project_name_for_title);
          } elseif ($is_unassigned_delivery) {
              echo "Unassigned delivery";
          } elseif (!empty($delivery['project_id'])) {
              echo "Project ID: " . htmlspecialchars((string)$delivery['project_id']);
          } else {
              echo "Delivery details";
          }
        ?>
      </p>
    </div>
    <div class="header-meta">
      <span class="meta-pill">Delivery #<?php echo (int)$delivery_id; ?></span>
      <span class="meta-pill">BOL: <?php echo htmlspecialchars((string)($delivery['bol_number'] ?: 'N/A')); ?></span>
      <span class="meta-pill">Status: <?php echo htmlspecialchars((string)($delivery['status_of_delivery'] ?: 'N/A')); ?></span>
    </div>
  </div>
</section>

<!-- Display messages -->
<div class="message-stack">
  <?php if ($success_message): ?>
      <p class="message success-message"><?php echo htmlspecialchars($success_message); ?></p>
  <?php endif; ?>
  <?php if ($error_message): ?>
      <p class="message error-message"><?php echo htmlspecialchars($error_message); ?></p>
  <?php endif; ?>
</div>

<!-- Associated Pallets as a table -->
<section class="content-card">
  <fieldset>
    <legend>Associated Pallets</legend>
    <div class="table-responsive">
      <table id="associatedPalletsTable">
        <thead>
          <tr>
            <th>Number of Pallets</th>
            <th>Wattage</th>
            <th>Qty Per Pallet</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
        <?php if (!empty($associated_pallets)): ?>
          <tr>
            <td><?php echo count($associated_pallets); ?></td>
            <td>
              <?php
                $pallet_wattages = [];
                foreach ($associated_pallets as $pallet_row) {
                    if (isset($pallet_row['wattage']) && $pallet_row['wattage'] !== '') {
                        $pallet_wattages[] = (string)$pallet_row['wattage'];
                    }
                }
                $pallet_wattages = array_values(array_unique($pallet_wattages));
                if (count($pallet_wattages) === 1) {
                    echo htmlspecialchars($pallet_wattages[0]) . "W";
                } elseif (count($pallet_wattages) > 1) {
                    echo "Mixed";
                } else {
                    echo "N/A";
                }
              ?>
            </td>
            <td>
              <?php
                $pallet_quantities = [];
                foreach ($associated_pallets as $pallet_row) {
                    if (isset($pallet_row['quantity']) && $pallet_row['quantity'] !== '') {
                        $pallet_quantities[] = (string)$pallet_row['quantity'];
                    }
                }
                $pallet_quantities = array_values(array_unique($pallet_quantities));
                if (count($pallet_quantities) === 1) {
                    echo htmlspecialchars($pallet_quantities[0]);
                } elseif (count($pallet_quantities) > 1) {
                    echo "Mixed";
                } else {
                    echo "N/A";
                }
              ?>
            </td>
            <td>
              <a
                href="manage_delivery_pallets.php?delivery_id=<?php echo $delivery_id; ?>&wattage=<?php echo urlencode((string)$delivery['wattage']); ?>"
                class="manage-pallets-button"
              >
                Add / Edit Associated Pallets
              </a>
            </td>
          </tr>
        <?php else: ?>
          <tr>
            <td colspan="4" style="text-align:center;">
              No pallets are currently associated with this delivery.
              <a href="manage_delivery_pallets.php?delivery_id=<?php echo $delivery_id; ?>&wattage=<?php echo urlencode((string)$delivery['wattage']); ?>"
                 class="manage-pallets-button"
                 style="margin-left: 15px;">
                Add Pallets to Delivery
              </a>
            </td>
          </tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </fieldset>
</section>

<!-- Damaged Pallets Alert (if any exist) -->
<?php if (!empty($damaged_pallets)): ?>
<section class="content-card damaged-card">
  <fieldset>
    <legend style="color: #e74c3c;">Damaged Pallets</legend>
    <p style="color: #721c24; font-weight: 500; margin-bottom: 12px;">
      These pallets stay in <strong>Damaged</strong> status and are not changed automatically from this screen.
    </p>
    <div class="table-responsive">
      <table class="damaged-table">
        <thead>
          <tr>
            <th>Pallet ID</th>
            <th>Wattage</th>
            <th>Quantity</th>
            <th>Current Status</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($damaged_pallets as $damaged_pallet): ?>
          <tr>
            <td><?php echo htmlspecialchars($damaged_pallet['pallet_identifier'] ?? ('ID: ' . $damaged_pallet['id'])); ?></td>
            <td><?php echo htmlspecialchars((string)($damaged_pallet['wattage'] ?? 'N/A')); ?>W</td>
            <td><?php echo htmlspecialchars((string)($damaged_pallet['quantity'] ?? 'N/A')); ?></td>
            <td><span class="damaged-status-pill"><?php echo htmlspecialchars((string)$damaged_pallet['status']); ?></span></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <p style="margin: 10px 0 0; color: #7f1d1d; font-size: 0.9em;">
      Use pallet management if you need to change damaged pallet status.
    </p>
  </fieldset>
</section>
<?php endif; ?>

<!-- Main form for editing Delivery details -->
<div class="form-container">
<form 
  action="edit_delivery.php?delivery_id=<?php echo $delivery_id; ?><?php if ($project_id_from_url) { echo '&project_id=' . $project_id_from_url; } ?>" 
  method="post" 
  enctype="multipart/form-data"
>
  <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token'];?>">

  <div class="form-columns">
    <div class="column-left">
      <fieldset>
        <legend>Delivery Details</legend>
        <div class="details-grid">
          <div>
            <label>Manufacturer:
              <input type="text" class="readonly-input" value="<?php echo htmlspecialchars((string)$delivery['supplier']);?>" readonly>
            </label>
          </div>
          <div>
            <label>Wattage:
              <input
                type="text"
                class="readonly-input"
                value="<?php echo htmlspecialchars((string)$delivery['wattage']);?>"
                readonly
              >
            </label>
          </div>

          <div class="span-2 location-row">
            <div class="location-card">
              <h4>Origin</h4>
              <label>Location:
                <input type="text" class="readonly-input" value="<?php echo htmlspecialchars($origin_display); ?>" readonly>
              </label>
              <label>Address:
                <input type="text" class="readonly-input" value="<?php echo htmlspecialchars($origin_address_display); ?>" readonly>
              </label>
            </div>
            <div class="location-card">
              <h4>Destination</h4>
              <label>Location:
                <input type="text" class="readonly-input" value="<?php echo htmlspecialchars($destination_display); ?>" readonly>
              </label>
              <label>Address:
                <input type="text" class="readonly-input" value="<?php echo htmlspecialchars($destination_address_display); ?>" readonly>
              </label>
            </div>
          </div>

          <div>
            <label>Status:
              <input type="text" class="readonly-input" value="<?php echo htmlspecialchars((string)$delivery['status_of_delivery']); ?>" readonly>
            </label>
          </div>
          <div>
            <label>Quantity:
              <input type="number" class="readonly-input" value="<?php echo $calculated_quantity;?>" readonly title="Quantity is automatically calculated from associated pallets">
            </label>
          </div>

          <div class="span-2">
            <label>BOL Number:
              <input type="text" name="bol_number" value="<?php echo htmlspecialchars((string)$delivery['bol_number']);?>">
            </label>
          </div>
          <div>
            <label>Carrier:
              <select name="carrier_id" style="width:100%; padding:8px; border:1px solid #ddd; border-radius:4px;">
                <option value="">-- No Carrier --</option>
                <?php foreach ($all_carriers as $cr): ?>
                  <option value="<?php echo $cr['id']; ?>" <?php echo ((int)($delivery['carrier_id'] ?? 0) === (int)$cr['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($cr['name']); ?><?php echo $cr['is_solterra_managed'] ? ' (Solterra)' : ''; ?></option>
                <?php endforeach; ?>
              </select>
            </label>
          </div>
          <div>
            <label>Carrier Ref #:
              <input type="text" name="carrier_reference_number" value="<?php echo htmlspecialchars((string)($delivery['carrier_reference_number'] ?? '')); ?>" placeholder="PRO/Reference number">
            </label>
          </div>
        </div>

        <div class="form-help">Manufacturer and status are managed by upstream workflow and milestone actions.</div>
        <div class="quantity-note">Quantity is calculated from associated pallets (<?php echo count($associated_pallets);?> pallets)</div>
      </fieldset>
    </div>

    <div class="column-right">
      <fieldset>
        <legend>Dates</legend>
        <label>Anticipated Delivery Date:
          <input type="date" name="anticipated_delivery_date" value="<?php echo htmlspecialchars((string)$delivery['anticipated_delivery_date']);?>">
        </label>
        <label>Warehouse Arrival Date:
          <input type="date" name="warehouse_arrival_date" value="<?php echo htmlspecialchars((string)$delivery['warehouse_arrival_date']);?>">
        </label>
        <label>Actual Delivery Date:
          <input type="date" name="actual_delivery_date" value="<?php echo htmlspecialchars((string)$delivery['actual_delivery_date']);?>">
        </label>
        <label>Left Warehouse Date:
          <input type="date" name="left_warehouse_date" value="<?php echo htmlspecialchars((string)$delivery['left_warehouse_date']);?>">
        </label>
      </fieldset>

      <fieldset>
        <legend>Costs</legend>
        <?php
          $paidVal = number_format((float)($delivery['accessorial_costs_paid'] ?? 0), 2, '.', '');
        ?>
        <label>Freight Cost:
          <input 
            type="number" 
            step="0.01" 
            name="freight_cost" 
            value="<?php echo number_format((float)($delivery['freight_cost'] ?? 0), 2, '.', '');?>"
          >
        </label>

        <?php if (!$is_customer_admin): ?>
        <?php
          $chargedVal = number_format((float)($delivery['accessorial_costs']       ?? 0), 2, '.', '');
          $checked    = ((float)$delivery['accessorial_costs'] > 0) ? 'checked' : '';
        ?>
        <label>Accessorial Cost (Paid to Carrier):
          <input 
            type="number" 
            step="0.01" 
            id="accessorial_costs_paid" 
            name="accessorial_costs_paid" 
            value="<?php echo $paidVal;?>"
          >
        </label>
        <div class="accessorial-help">Use this for TONU, driver detention, layover, redelivery, lumper, and similar extra carrier charges.</div>

        <label class="checkbox-inline">
          <input 
            type="checkbox" 
            id="charge_customer_ckb" 
            <?php echo $checked;?>
          >
          Charge Customer This Amount?
        </label>

        <input 
          type="hidden" 
          id="accessorial_costs" 
          name="accessorial_costs" 
          value="<?php echo $chargedVal;?>"
        >

        <label>Customer Cost:
          <input 
            type="number" 
            step="0.01" 
            id="customer_cost"
            name="customer_cost" 
            value="<?php echo number_format((float)($delivery['customer_cost'] ?? 0), 2, '.', '');?>"
          >
        </label>

        <label>Miles:
          <input 
            type="number" 
            step="0.01" 
            name="miles" 
            value="<?php echo number_format((float)($delivery['miles'] ?? 0), 2, '.', '');?>"
          >
        </label>
        <?php else: ?>
        <label>Accessorial Cost (Paid to Carrier):
          <input
            type="number"
            step="0.01"
            id="accessorial_costs_paid"
            name="accessorial_costs_paid"
            value="<?php echo $paidVal;?>"
          >
        </label>
        <div class="accessorial-help">Use this for TONU, driver detention, layover, redelivery, lumper, and similar extra carrier charges.</div>
        <?php endif; ?>
      </fieldset>

      <fieldset>
        <legend>Proof of Delivery (POD)</legend>
        <?php if (!empty($delivery['proof_of_delivery'])): ?>
          <a class="pod-current-link" href="view_pod?delivery_id=<?php echo (int)$delivery['id'];?>" target="_blank" rel="noopener">
            View current POD
          </a>
          <label class="checkbox-inline">
            <input 
              type="checkbox" 
              name="remove_pod" 
              value="1"
            >
            Remove current POD
          </label>
        <?php endif;?>
        <label>Upload new POD:
          <input type="file" name="pod_file" accept=".pdf,.jpg,.jpeg,.png">
        </label>
      </fieldset>
    </div>

    <button type="submit" name="update_delivery" class="form-submit-button">
      Update Delivery Details
    </button>
  </div>
</form>
</div>

</main>

<?php if (!$is_customer_admin): ?>
<script>
/**
 * Keep the hidden customer charge field in sync 
 * with the 'Charge Customer' checkbox and the paid amount,
 * and calculate customer cost automatically only if blank or user hasn't manually set it
 */
const ckb       = document.getElementById('charge_customer_ckb');
const paidInput = document.getElementById('accessorial_costs_paid');
const hiddenCst = document.getElementById('accessorial_costs');
const freightCostInput = document.querySelector('input[name="freight_cost"]');
const customerCostInput = document.getElementById('customer_cost');

// Track whether user has manually entered customer cost
let userHasSetCustomerCost = false;
const originalCustomerCost = customerCostInput ? (parseFloat(customerCostInput.value) || 0) : 0;

function syncAccessorialCharge() {
  if (!ckb || !paidInput || !hiddenCst) {
    return;
  }
  const paidValue = parseFloat(paidInput.value) || 0;
  hiddenCst.value = ckb.checked ? paidValue.toFixed(2) : '0.00';
  calculateCustomerCost();
}

function calculateCustomerCost() {
  if (!freightCostInput || !customerCostInput || !hiddenCst) {
    return;
  }
  
  // Only auto-calculate if user hasn't manually set the customer cost
  // or if the customer cost field is empty/zero
  const currentCustomerCost = parseFloat(customerCostInput.value) || 0;
  
  if (!userHasSetCustomerCost || currentCustomerCost === 0) {
    const freightCost = parseFloat(freightCostInput.value) || 0;
    const accessorialCharged = parseFloat(hiddenCst.value) || 0;
    const totalCustomerCost = freightCost + accessorialCharged;
    customerCostInput.value = totalCustomerCost.toFixed(2);
  }
}

// Track when user manually enters customer cost
if (customerCostInput) {
  customerCostInput.addEventListener('input', () => {
    userHasSetCustomerCost = true;
  });
  
  // Also track focus/blur to detect manual changes
  customerCostInput.addEventListener('focus', () => {
    customerCostInput.dataset.previousValue = customerCostInput.value;
  });
  
  customerCostInput.addEventListener('blur', () => {
    if (customerCostInput.value !== customerCostInput.dataset.previousValue) {
      userHasSetCustomerCost = true;
    }
  });
}

if (ckb) {
  ckb.addEventListener('change', syncAccessorialCharge);
}
if (paidInput) {
  paidInput.addEventListener('input', () => {
    if (ckb && ckb.checked) {
      syncAccessorialCharge();
    }
  });
}

// Add event listener for freight cost changes
if (freightCostInput) {
  freightCostInput.addEventListener('input', calculateCustomerCost);
}

// Initial sync on page load - only if customer cost is currently 0 or matches calculated value
document.addEventListener('DOMContentLoaded', () => {
  if (!freightCostInput || !hiddenCst) {
    return;
  }

  // Check if the current customer cost matches what would be auto-calculated
  if (originalCustomerCost > 0) {
    const freightCost = parseFloat(freightCostInput.value) || 0;
    const accessorialCharged = parseFloat(hiddenCst.value) || 0;
    const calculatedCost = freightCost + accessorialCharged;
    
    // If current value doesn't match calculated, assume user set it manually
    if (Math.abs(originalCustomerCost - calculatedCost) > 0.01) {
      userHasSetCustomerCost = true;
    }
  }
  
  syncAccessorialCharge();
  // Only calculate on initial load if customer cost is 0 or user hasn't set it
  if (!userHasSetCustomerCost) {
    calculateCustomerCost();
  }
});
</script>
<?php endif; ?>

</body>
</html>
<?php $conn->close(); ?>
