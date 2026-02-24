<?php
session_name("logistics_session");
session_start();

// Ensure user has role admin, global_admin, or customer_admin
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin','global_admin','customer_admin'])) {
    header("Location: unauthorized.php");
    exit();
}

require_once '../config.php';
require_once 'document_helpers.php';
$conn = getDBConnection();
$google_maps_api_key = getGoogleMapsApiKey();

$role = $_SESSION['role'] ?? '';
$user_id = $_SESSION['user_id'] ?? 0;
$account_id = null;

if ($role !== 'global_admin') {
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

// Get and validate warehouse ID
$warehouse_id = isset($_GET['warehouse_id']) ? intval($_GET['warehouse_id']) : 0;
if ($warehouse_id <= 0) {
    die("Invalid Warehouse ID provided.");
}

if ($role !== 'global_admin') {
    $stmtAccess = $conn->prepare("SELECT id FROM warehouses WHERE id = ? AND account_id = ?");
    if ($stmtAccess) {
        $stmtAccess->bind_param("ii", $warehouse_id, $account_id);
        $stmtAccess->execute();
        $accessResult = $stmtAccess->get_result();
        $stmtAccess->close();
        if ($accessResult->num_rows === 0) {
            header("Location: unauthorized.php");
            exit();
        }
    }
}

$errorMessage = '';
$successMessage = $_SESSION['move_pallet_message'] ?? '';
if (isset($_SESSION['move_pallet_message'])) unset($_SESSION['move_pallet_message']);
$customs_action_result = $_SESSION['customs_action_result'] ?? null;
if (isset($_SESSION['customs_action_result'])) unset($_SESSION['customs_action_result']);

// ===========================================================================================
// DATA FUNCTIONS
// ===========================================================================================

/**
 * Fetch all containers at port with pallets in Cleared Customs or Customs Hold status.
 * Returns nested structure: containers[ { ...container_data, pallets: [...] } ]
 */
function fetchPortContainers($conn, $warehouse_id) {
    $containers = [];

    // Get container-level aggregates
    $stmt = $conn->prepare("
        SELECT
            d.id AS delivery_id,
            COALESCE(NULLIF(TRIM(d.bol_number), ''), 'N/A') AS container_number,
            MIN(ip.arrival_date) AS arrival_date,
            COALESCE(m.vendor_name, 'N/A') AS origin_vendor,
            GROUP_CONCAT(DISTINCT p.project_name SEPARATOR ', ') AS projects,
            COUNT(ip.id) AS total_pallets,
            SUM(ip.quantity) AS total_modules,
            SUM(CASE WHEN ip.status = 'Cleared Customs' THEN 1 ELSE 0 END) AS cleared_count,
            SUM(CASE WHEN ip.status = 'Customs Hold' THEN 1 ELSE 0 END) AS held_count,
            SUM(CASE WHEN ip.status = 'Customs Hold' THEN ip.quantity ELSE 0 END) AS held_modules,
            SUM(CASE WHEN ip.status = 'Customs Hold' THEN COALESCE(ip.customs_hold_cost, 0) ELSE 0 END) AS held_cost
        FROM inventory_pallets ip
        JOIN delivery_pallets dp ON ip.id = dp.inventory_pallet_id
        JOIN deliveries d ON dp.delivery_id = d.id AND d.warehouse_id = ?
        LEFT JOIN unassigned_module_items umi ON ip.unassigned_module_item_id = umi.id
        LEFT JOIN modules m ON umi.unassigned_module_id = m.id
        LEFT JOIN projects p ON ip.assigned_project_id = p.id
        WHERE ip.current_warehouse_id = ?
          AND ip.status IN ('Cleared Customs', 'Customs Hold')
          AND d.status_of_delivery != 'Departed Port'
        GROUP BY d.id, d.bol_number, m.vendor_name
        ORDER BY MIN(ip.arrival_date) DESC
    ");

    if (!$stmt) return $containers;
    $stmt->bind_param("ii", $warehouse_id, $warehouse_id);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $row['projects'] = $row['projects'] ?? 'N/A';
        $row['pallets'] = [];
        $containers[$row['delivery_id']] = $row;
    }
    $stmt->close();

    if (empty($containers)) return [];

    // Fetch individual pallets for all containers
    $delivery_ids = array_keys($containers);
    $placeholders = implode(',', array_fill(0, count($delivery_ids), '?'));
    $types = str_repeat('i', count($delivery_ids));

    $stmtP = $conn->prepare("
        SELECT
            ip.id AS pallet_id,
            ip.pallet_identifier,
            ip.wattage,
            ip.quantity,
            ip.status,
            ip.arrival_date,
            COALESCE(ip.customs_hold_cost, 0) AS customs_hold_cost,
            ip.customs_hold_cost_notes,
            dp.delivery_id
        FROM inventory_pallets ip
        JOIN delivery_pallets dp ON ip.id = dp.inventory_pallet_id
        WHERE dp.delivery_id IN ($placeholders)
          AND ip.status IN ('Cleared Customs', 'Customs Hold')
        ORDER BY ip.status DESC, ip.pallet_identifier ASC
    ");

    if ($stmtP) {
        $stmtP->bind_param($types, ...$delivery_ids);
        $stmtP->execute();
        $resultP = $stmtP->get_result();
        while ($pallet = $resultP->fetch_assoc()) {
            $did = $pallet['delivery_id'];
            if (isset($containers[$did])) {
                $containers[$did]['pallets'][] = $pallet;
            }
        }
        $stmtP->close();
    }

    return array_values($containers);
}

/**
 * Fetch inbound truckloads/containers in transit to this port.
 */
function fetchInboundTruckloads($conn, $warehouse_id) {
    $transit_truckloads = [];

    $stmt = $conn->prepare("
        SELECT
            d.id AS delivery_id,
            d.bol_number,
            d.supplier AS origin_vendor,
            d.anticipated_delivery_date AS est_arrival_date,
            COUNT(ip.id) AS total_pallets,
            SUM(ip.quantity) AS total_modules,
            GROUP_CONCAT(DISTINCT ip.wattage ORDER BY ip.wattage SEPARATOR ', ') AS wattages,
            GROUP_CONCAT(DISTINCT p.project_name SEPARATOR ', ') AS projects
        FROM deliveries d
        JOIN delivery_pallets dp ON d.id = dp.delivery_id
        JOIN inventory_pallets ip ON dp.inventory_pallet_id = ip.id
        LEFT JOIN projects p ON d.project_id = p.id
        WHERE d.warehouse_id = ?
          AND ip.status IN ('In Transit to Warehouse', 'On Water')
          AND d.status_of_delivery != 'Departed Port'
        GROUP BY d.id, d.bol_number, d.supplier, d.anticipated_delivery_date
        ORDER BY d.anticipated_delivery_date ASC
    ");

    if (!$stmt) return $transit_truckloads;
    $stmt->bind_param("i", $warehouse_id);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($truckload = $result->fetch_assoc()) {
        $truckload['projects'] = $truckload['projects'] ?? 'N/A';
        $transit_truckloads[] = $truckload;
    }
    $stmt->close();

    return $transit_truckloads;
}

/**
 * Fetch inbound history records for this port.
 */
function fetchPortInboundHistory($conn, $warehouse_id) {
    $history = [];

    $stmt = $conn->prepare("
        SELECT
            d.bol_number,
            d.supplier,
            d.warehouse_arrival_date,
            COUNT(DISTINCT d.id) AS delivery_count,
            COUNT(DISTINCT dp.inventory_pallet_id) AS total_pallets,
            (SELECT SUM(d_inner.quantity) FROM deliveries d_inner WHERE d_inner.bol_number = d.bol_number AND d_inner.warehouse_id = d.warehouse_id AND d_inner.warehouse_arrival_date = d.warehouse_arrival_date) AS total_modules,
            GROUP_CONCAT(DISTINCT d.wattage ORDER BY d.wattage SEPARATOR ', ') AS wattages,
            GROUP_CONCAT(DISTINCT p.project_name SEPARATOR ', ') AS projects,
            GROUP_CONCAT(DISTINCT d.id ORDER BY d.id SEPARATOR ',') AS delivery_ids
        FROM deliveries d
        LEFT JOIN delivery_pallets dp ON d.id = dp.delivery_id
        LEFT JOIN projects p ON d.project_id = p.id
        WHERE d.warehouse_id = ?
          AND d.status_of_delivery IN ('Delivered to Warehouse', 'Cleared Customs', 'Departed Port')
          AND d.warehouse_arrival_date IS NOT NULL
        GROUP BY d.bol_number, d.supplier, d.warehouse_arrival_date
        ORDER BY d.warehouse_arrival_date DESC
    ");

    if (!$stmt) return $history;
    $stmt->bind_param("i", $warehouse_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $row['projects'] = $row['projects'] ?? 'N/A';
        $history[] = $row;
    }
    $stmt->close();

    return $history;
}

/**
 * Fetch outbound history records for this port.
 */
function fetchPortOutboundHistory($conn, $warehouse_id) {
    $history = [];

    $stmt = $conn->prepare("
        SELECT
            d.bol_number,
            d.supplier,
            d.left_warehouse_date AS departure_date,
            d.anticipated_delivery_date,
            d.status_of_delivery,
            COUNT(DISTINCT d.id) AS delivery_count,
            COUNT(DISTINCT dp.inventory_pallet_id) AS total_pallets,
            (SELECT SUM(d_inner.quantity) FROM deliveries d_inner WHERE d_inner.bol_number = d.bol_number AND d_inner.supplier = d.supplier AND d_inner.left_warehouse_date = d.left_warehouse_date) AS total_modules,
            GROUP_CONCAT(DISTINCT d.wattage ORDER BY d.wattage SEPARATOR ', ') AS wattages,
            GROUP_CONCAT(DISTINCT p.project_name SEPARATOR ', ') AS projects,
            GROUP_CONCAT(DISTINCT d.id ORDER BY d.id SEPARATOR ',') AS delivery_ids,
            GROUP_CONCAT(DISTINCT
                CASE
                    WHEN d.project_id IS NOT NULL THEN CONCAT('Project: ', p.project_name)
                    WHEN d.warehouse_id IS NOT NULL AND d.warehouse_id != ? THEN CONCAT('Warehouse: ', w.name)
                    ELSE 'Unknown Destination'
                END SEPARATOR ', '
            ) AS destinations
        FROM deliveries d
        LEFT JOIN projects p ON d.project_id = p.id
        LEFT JOIN warehouses w ON d.warehouse_id = w.id
        JOIN delivery_pallets dp ON d.id = dp.delivery_id
        JOIN inventory_pallets ip ON dp.inventory_pallet_id = ip.id
        WHERE (d.warehouse_id = ? OR (d.origin_type = 'warehouse' AND d.origin_id = ?))
          AND d.left_warehouse_date IS NOT NULL
        GROUP BY d.bol_number, d.supplier, d.left_warehouse_date, d.anticipated_delivery_date, d.status_of_delivery
        ORDER BY d.left_warehouse_date DESC
    ");

    if (!$stmt) return $history;
    $stmt->bind_param("iii", $warehouse_id, $warehouse_id, $warehouse_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $row['projects'] = $row['projects'] ?? 'N/A';
        $history[] = $row;
    }
    $stmt->close();

    return $history;
}

// ===========================================================================================
// POST HANDLER: CUSTOMS HOLD / RELEASE
// ===========================================================================================

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && in_array($_POST['action'], ['place_customs_hold', 'release_customs_hold'], true)) {
    $redirect_url = "manage_port_inventory.php?warehouse_id={$warehouse_id}&tab=atPort";
    if (!empty($_GET['project_id'])) {
        $redirect_url .= '&project_id=' . urlencode((string)$_GET['project_id']);
    }

    try {
        $action = $_POST['action'];
        $selected = isset($_POST['customs_pallet_ids']) && is_array($_POST['customs_pallet_ids']) ? $_POST['customs_pallet_ids'] : [];
        $pallet_ids = array_values(array_filter(array_map('intval', $selected), static function ($v) {
            return $v > 0;
        }));

        if (empty($pallet_ids)) {
            throw new Exception('Select at least one pallet.');
        }

        $stmtPort = $conn->prepare("SELECT is_port FROM warehouses WHERE id = ? LIMIT 1");
        if (!$stmtPort) throw new Exception('Unable to validate facility type.');
        $stmtPort->bind_param("i", $warehouse_id);
        $stmtPort->execute();
        $stmtPort->bind_result($port_flag);
        $is_port_action = false;
        if ($stmtPort->fetch()) {
            $is_port_action = ((int)$port_flag === 1);
        }
        $stmtPort->close();

        if (!$is_port_action) {
            throw new Exception('Customs hold actions are only available for ports.');
        }

        $cost_input = trim((string)($_POST['customs_cost_per_pallet'] ?? '0'));
        $cost_per_pallet = is_numeric($cost_input) ? (float)$cost_input : 0.0;
        if ($cost_per_pallet < 0) throw new Exception('Cost per pallet cannot be negative.');

        $hold_reason = trim((string)($_POST['customs_hold_reason'] ?? ''));
        if ($action === 'place_customs_hold' && $hold_reason === '') {
            throw new Exception('Hold reason is required.');
        }
        if (strlen($hold_reason) > 120) $hold_reason = substr($hold_reason, 0, 120);

        $cost_notes = trim((string)($_POST['customs_cost_notes'] ?? ''));
        if (strlen($cost_notes) > 255) $cost_notes = substr($cost_notes, 0, 255);

        $update_notes = $cost_notes;
        if ($action === 'place_customs_hold' && $hold_reason !== '') {
            $update_notes = $cost_notes !== ''
                ? 'Reason: ' . $hold_reason . ' | Notes: ' . $cost_notes
                : 'Reason: ' . $hold_reason;
            if (strlen($update_notes) > 255) $update_notes = substr($update_notes, 0, 255);
        }

        $new_status = ($action === 'place_customs_hold') ? 'Customs Hold' : 'Cleared Customs';
        $expected_status = ($action === 'place_customs_hold') ? 'Cleared Customs' : 'Customs Hold';
        $id_list = implode(',', $pallet_ids);

        $sql = "
            UPDATE inventory_pallets
            SET status = ?,
                customs_hold_cost = COALESCE(customs_hold_cost, 0) + ?,
                customs_hold_cost_notes = CASE WHEN ? <> '' THEN ? ELSE customs_hold_cost_notes END,
                customs_hold_cost_updated_at = NOW()
            WHERE id IN ($id_list)
              AND current_warehouse_id = ?
              AND status = ?
        ";

        $stmt = $conn->prepare($sql);
        if (!$stmt) throw new Exception('Failed to prepare customs hold update.');

        $stmt->bind_param("sdssis", $new_status, $cost_per_pallet, $update_notes, $update_notes, $warehouse_id, $expected_status);
        $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();

        if ($affected <= 0) {
            throw new Exception('No pallets were updated. They may already be in the target status.');
        }

        $successMessage = $action === 'place_customs_hold'
            ? "Placed {$affected} pallet(s) on Customs Hold."
            : "Released {$affected} pallet(s) to Cleared Customs.";
        if ($action === 'place_customs_hold' && $hold_reason !== '') {
            $successMessage .= ' Reason: ' . $hold_reason . '.';
        }
        if ($cost_per_pallet > 0) {
            $successMessage .= ' Added $' . number_format($cost_per_pallet, 2) . ' per pallet.';
        }
        $_SESSION['move_pallet_message'] = $successMessage;

        // Verification
        $verify_rows = [];
        $verify_sql = "
            SELECT ip.id, ip.pallet_identifier, ip.status, ip.current_warehouse_id,
                   COALESCE(MAX(NULLIF(TRIM(d.bol_number), '')), 'N/A') AS container_number
            FROM inventory_pallets ip
            LEFT JOIN delivery_pallets dp ON ip.id = dp.inventory_pallet_id
            LEFT JOIN deliveries d ON dp.delivery_id = d.id
            WHERE ip.id IN ($id_list)
            GROUP BY ip.id, ip.pallet_identifier, ip.status, ip.current_warehouse_id
            ORDER BY ip.pallet_identifier ASC
        ";
        $stmtVerify = $conn->prepare($verify_sql);
        if ($stmtVerify) {
            $stmtVerify->execute();
            $verify_result = $stmtVerify->get_result();
            while ($verify_row = $verify_result->fetch_assoc()) {
                $verify_rows[] = $verify_row;
            }
            $stmtVerify->close();
        }
        $_SESSION['customs_action_result'] = [
            'action' => $action,
            'expected_status' => $new_status,
            'warehouse_id' => (int)$warehouse_id,
            'rows' => $verify_rows
        ];
    } catch (Exception $e) {
        $_SESSION['move_pallet_message'] = 'Error: ' . $e->getMessage();
        unset($_SESSION['customs_action_result']);
    }

    header("Location: {$redirect_url}");
    exit();
}

// ===========================================================================================
// MAIN EXECUTION: FETCH PORT DATA
// ===========================================================================================

$warehouse = null;
$containers_at_port = [];
$inbound_truckloads = [];
$inbound_history = [];
$outbound_history = [];
$all_projects = [];
$other_warehouses = [];
$warehouse_fees = ['all_items' => []];

try {
    // Fetch port details
    if ($role === 'global_admin') {
        $stmtW = $conn->prepare("SELECT * FROM warehouses WHERE id = ?");
    } else {
        $stmtW = $conn->prepare("SELECT * FROM warehouses WHERE id = ? AND account_id = ?");
    }
    if (!$stmtW) throw new Exception("Failed to prepare warehouse query: " . $conn->error);
    if ($role === 'global_admin') {
        $stmtW->bind_param("i", $warehouse_id);
    } else {
        $stmtW->bind_param("ii", $warehouse_id, $account_id);
    }
    $stmtW->execute();
    $resultW = $stmtW->get_result();
    if ($resultW->num_rows === 0) throw new Exception("Port not found.");
    $warehouse = $resultW->fetch_assoc();
    $stmtW->close();

    // Verify this is actually a port; if not, redirect to warehouse page
    if (empty($warehouse['is_port']) || $warehouse['is_port'] != 1) {
        header("Location: manage_warehouse_inventory.php?warehouse_id=" . $warehouse_id);
        exit();
    }

    // Fetch warehouse fees
    $stmtCosts = $conn->prepare("
        SELECT id, label, amount, trigger_event, unit_type, pallets_per_truck, sqft_per_pallet, display_order
        FROM warehouse_cost_items
        WHERE warehouse_id = ? AND is_active = 1
        ORDER BY display_order ASC, trigger_event ASC
    ");
    if ($stmtCosts) {
        $stmtCosts->bind_param("i", $warehouse_id);
        $stmtCosts->execute();
        $resultCosts = $stmtCosts->get_result();
        while ($cost = $resultCosts->fetch_assoc()) {
            $warehouse_fees['all_items'][] = $cost;
        }
        $stmtCosts->close();
    }

    // Fetch data
    $containers_at_port = fetchPortContainers($conn, $warehouse_id);
    $inbound_truckloads = fetchInboundTruckloads($conn, $warehouse_id);
    $inbound_history = fetchPortInboundHistory($conn, $warehouse_id);
    $outbound_history = fetchPortOutboundHistory($conn, $warehouse_id);

    // Fetch projects for drayage destination dropdown
    $project_clause = $role === 'global_admin' ? '' : ' AND account_id = ?';
    $stmtAllP = $conn->prepare("SELECT id, project_name, street_address, city, state, zip_code FROM projects WHERE (status IS NULL OR status = 'active')$project_clause ORDER BY project_name ASC");
    if ($stmtAllP) {
        if ($role !== 'global_admin') {
            $stmtAllP->bind_param("i", $account_id);
        }
        $stmtAllP->execute();
        $resultAllP = $stmtAllP->get_result();
        while ($proj = $resultAllP->fetch_assoc()) {
            $address_parts = array_filter([$proj['street_address'], $proj['city'], $proj['state'], $proj['zip_code']]);
            $proj['full_address'] = implode(', ', $address_parts);
            $all_projects[] = $proj;
        }
        $stmtAllP->close();
    }

    // Fetch other warehouses (exclude ports and current)
    $account_clause = $role === 'global_admin' ? '' : ' AND account_id = ?';
    $stmtOtherW = $conn->prepare("SELECT id, name, street_address, city, state, zip_code FROM warehouses WHERE (is_port = 0 OR is_port IS NULL) AND id != ?$account_clause ORDER BY name ASC");
    if ($stmtOtherW) {
        if ($role === 'global_admin') {
            $stmtOtherW->bind_param("i", $warehouse_id);
        } else {
            $stmtOtherW->bind_param("ii", $warehouse_id, $account_id);
        }
        $stmtOtherW->execute();
        $resultOtherW = $stmtOtherW->get_result();
        while ($wh = $resultOtherW->fetch_assoc()) {
            $address_parts = array_filter([$wh['street_address'], $wh['city'], $wh['state'], $wh['zip_code']]);
            $wh['full_address'] = implode(', ', $address_parts);
            $other_warehouses[] = $wh;
        }
        $stmtOtherW->close();
    }

} catch (Exception $e) {
    $errorMessage = $e->getMessage();
}

// Compute stat card totals
$stat_containers = count($containers_at_port);
$stat_pallets_cleared = 0;
$stat_pallets_held = 0;
$stat_total_modules = 0;
$stat_held_cost = 0;
foreach ($containers_at_port as $c) {
    $stat_pallets_cleared += (int)$c['cleared_count'];
    $stat_pallets_held += (int)$c['held_count'];
    $stat_total_modules += (int)$c['total_modules'];
    $stat_held_cost += (float)$c['held_cost'];
}
$stat_inbound = count($inbound_truckloads);

$from_project_id = isset($_GET['project_id']) ? (int)$_GET['project_id'] : 0;

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Port Operations - <?php echo htmlspecialchars($warehouse['name'] ?? 'Unknown'); ?></title>
    <link rel="stylesheet" href="portal.css">
    <link rel="icon" href="pictures/favicon.png" type="image/x-icon">
    <link href="https://fonts.googleapis.com/css?family=Poppins:300,400,500,600,700&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <script async defer src="https://maps.googleapis.com/maps/api/js?key=<?php echo htmlspecialchars($google_maps_api_key); ?>&libraries=places,geometry"></script>

    <style>
        /* ========== PORT PAGE LAYOUT ========== */
        .port-page {
            padding: 0 20px 40px;
        }

        /* ========== FACILITY HERO HEADER (matches project_overview pattern) ========== */
        .facility-header-hero {
            background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%);
            border-radius: 24px;
            padding: 32px;
            margin: 0 20px 24px 20px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.06);
            border: 1px solid rgba(72, 140, 154, 0.08);
            position: relative;
            overflow: visible;
        }
        .facility-header-hero::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0;
            height: 4px;
            background: linear-gradient(90deg, #488C9A 0%, #293E4C 100%);
            border-radius: 24px 24px 0 0;
        }
        .facility-header-content {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 20px;
        }
        .facility-header-left {
            display: flex;
            align-items: center;
            gap: 24px;
            min-width: 0;
            flex: 1;
        }
        .facility-header-icon {
            position: relative;
            width: 150px;
            height: 120px;
            border-radius: 20px;
            overflow: hidden;
            flex-shrink: 0;
            box-shadow: 0 12px 24px rgba(72, 140, 154, 0.3);
        }
        .facility-header-icon img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        .facility-header-icon-placeholder {
            width: 100%;
            height: 100%;
            background: linear-gradient(135deg, #488C9A 0%, #3A6E7F 100%);
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .facility-header-icon-placeholder i {
            font-size: 3rem;
            color: rgba(255,255,255,0.85);
        }
        .facility-header-info {
            min-width: 0;
            flex: 1;
        }
        .facility-title-row {
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
            margin-bottom: 8px;
        }
        .facility-header-info h1 {
            font-size: 2.2em;
            font-weight: 700;
            background: linear-gradient(135deg, #293E4C 0%, #488C9A 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            margin: 0;
            line-height: 1.2;
        }
        .facility-header-subtitle {
            font-size: 1.05em;
            color: #6c757d;
            margin: 0 0 8px;
            font-weight: 500;
        }
        .facility-header-meta {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            align-items: center;
        }
        .facility-meta-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 5px 12px;
            border-radius: 8px;
            font-size: 0.82rem;
            font-weight: 600;
            text-decoration: none;
            transition: all 0.2s;
        }
        .facility-meta-badge.fees-badge {
            background: rgba(72, 140, 154, 0.08);
            color: #3A6E7F;
            border: 1px solid rgba(72, 140, 154, 0.15);
            cursor: pointer;
        }
        .facility-meta-badge.fees-badge:hover {
            background: rgba(72, 140, 154, 0.15);
        }
        .facility-meta-badge.edit-badge {
            background: #fff;
            color: #495057;
            border: 1px solid #dee2e6;
        }
        .facility-meta-badge.edit-badge:hover {
            background: #488C9A;
            border-color: #488C9A;
            color: #fff;
        }
        /* Header stat cards (right side) */
        .facility-header-stats {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            align-items: stretch;
        }
        .facility-stat {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            background: rgba(72, 140, 154, 0.07);
            padding: 12px 20px;
            border-radius: 12px;
            min-width: 100px;
            transition: all 0.2s ease;
            border: 1px solid transparent;
        }
        .facility-stat:hover {
            background: rgba(72, 140, 154, 0.12);
            border-color: rgba(72, 140, 154, 0.15);
        }
        .facility-stat-value {
            font-size: 1.5rem;
            font-weight: 700;
            color: #293E4C;
            line-height: 1;
        }
        .facility-stat-label {
            font-size: 0.72rem;
            color: #6c757d;
            text-transform: uppercase;
            margin-top: 4px;
            text-align: center;
            letter-spacing: 0.02em;
        }
        .facility-stat.accent-red {
            background: rgba(220, 38, 38, 0.06);
            border-color: rgba(220, 38, 38, 0.12);
        }
        .facility-stat.accent-red .facility-stat-value {
            color: #dc2626;
        }
        .facility-stat.accent-red:hover {
            background: rgba(220, 38, 38, 0.1);
        }
        .facility-stat.accent-green {
            background: rgba(5, 150, 105, 0.06);
            border-color: rgba(5, 150, 105, 0.12);
        }
        .facility-stat.accent-green .facility-stat-value {
            color: #059669;
        }

        /* ========== UX HINT ========== */
        .ux-hint {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 10px 16px;
            background: linear-gradient(135deg, #f0f7fa 0%, #e8f4f8 100%);
            border: 1px solid rgba(72, 140, 154, 0.15);
            border-radius: 10px;
            margin-bottom: 16px;
            font-size: 0.88em;
            color: #3A6E7F;
        }
        .ux-hint i {
            font-size: 1.1em;
            flex-shrink: 0;
        }
        .ux-hint-dismiss {
            margin-left: auto;
            background: none;
            border: none;
            color: #6c757d;
            cursor: pointer;
            font-size: 1.1em;
            padding: 0 2px;
        }
        .ux-hint-dismiss:hover {
            color: #293E4C;
        }

        /* ========== TABS ========== */
        .port-tabs {
            display: flex;
            gap: 8px;
            margin-bottom: 20px;
        }
        .port-tab {
            padding: 10px 22px;
            border: none;
            border-radius: 999px;
            background: #f1f5f9;
            color: #475569;
            font-size: 0.92em;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        .port-tab:hover {
            background: #e2e8f0;
        }
        .port-tab.active {
            background: #488C9A;
            color: #fff;
        }
        .port-tab-content {
            display: none;
        }
        .port-tab-content.active {
            display: block;
        }

        /* ========== TOOLBAR ========== */
        .port-toolbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            margin-bottom: 16px;
            flex-wrap: wrap;
        }
        .port-search {
            padding: 9px 14px;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            font-size: 0.9em;
            width: 280px;
            outline: none;
            transition: border-color 0.2s;
        }
        .port-search:focus {
            border-color: #488C9A;
            box-shadow: 0 0 0 3px rgba(72, 140, 154, 0.12);
        }
        .port-toolbar-actions {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .selection-counter {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 5px 10px;
            border-radius: 999px;
            background: #eef7fb;
            border: 1px solid #d3e6ef;
            color: #345768;
            font-size: 0.82rem;
            font-weight: 600;
        }

        /* ========== TABLE ========== */
        .port-table-container {
            border-radius: 12px;
            overflow: hidden;
            border: 1px solid #e2e8f0;
            box-shadow: 0 2px 8px rgba(0,0,0,0.04);
        }
        .port-table {
            width: 100%;
            border-collapse: collapse;
        }
        .port-table thead th {
            background: linear-gradient(135deg, #488C9A 0%, #3A6E7F 100%);
            color: #fff;
            padding: 12px 14px;
            text-align: left;
            font-weight: 600;
            font-size: 0.85em;
            white-space: nowrap;
        }
        .port-table thead th:first-child {
            padding-left: 16px;
        }
        .port-table tbody tr {
            border-bottom: 1px solid #f0f0f0;
            transition: background 0.15s;
        }
        .port-table tbody tr:hover {
            background: #f8fbfc;
        }
        .port-table td {
            padding: 11px 14px;
            font-size: 0.9em;
            vertical-align: middle;
        }
        .port-table td:first-child {
            padding-left: 16px;
        }

        /* Container row */
        .container-row {
            cursor: pointer;
            border-left: 4px solid #488C9A;
        }
        .container-row:hover {
            background: #f0f7fa !important;
        }
        .expand-arrow {
            display: inline-block;
            transition: transform 0.25s ease;
            font-size: 0.85em;
            color: #64748b;
        }
        .expand-arrow.open {
            transform: rotate(90deg);
        }

        /* Hold badge */
        .hold-badge {
            display: inline-block;
            background: #fef2f2;
            color: #dc2626;
            border-radius: 12px;
            padding: 2px 10px;
            font-size: 0.82em;
            font-weight: 600;
        }
        .hold-badge.none {
            background: #ecfdf5;
            color: #059669;
        }

        /* ========== PALLET PANEL (expanded) ========== */
        .pallet-detail-row td {
            padding: 0 !important;
            border-left: 4px solid #488C9A;
        }
        .pallet-panel {
            background: #f8fbfc;
            padding: 16px 20px;
            border-top: 1px solid #e2e8f0;
        }
        .pallet-sub-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 0;
        }
        .pallet-sub-table thead th {
            background: #eef3f6;
            color: #374151;
            padding: 9px 12px;
            text-align: left;
            font-weight: 600;
            font-size: 0.82em;
            border-bottom: 1px solid #d1d5db;
        }
        .pallet-sub-table tbody tr {
            border-bottom: 1px solid #e5e7eb;
        }
        .pallet-sub-table tbody tr:last-child {
            border-bottom: none;
        }
        .pallet-sub-table td {
            padding: 8px 12px;
            font-size: 0.87em;
            background: #fff;
        }

        /* Status badges */
        .status-badge {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 999px;
            font-size: 0.8em;
            font-weight: 600;
        }
        .status-cleared {
            background: #ecfdf5;
            color: #059669;
        }
        .status-held {
            background: #fef2f2;
            color: #dc2626;
        }

        /* ========== PALLET ACTION BAR ========== */
        .pallet-action-bar {
            border-bottom: 2px solid #488C9A;
            background: #fff;
            padding: 14px 16px;
            margin-bottom: 12px;
            border-radius: 8px 8px 0 0;
            animation: slideDown 0.2s ease-out;
        }
        @keyframes slideDown {
            from { opacity: 0; transform: translateY(-8px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .pallet-action-bar h4 {
            margin: 0 0 10px;
            font-size: 0.92em;
            color: #293E4C;
        }
        .pallet-action-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 12px;
            align-items: end;
        }
        .pallet-action-grid label {
            display: block;
            font-weight: 600;
            margin-bottom: 4px;
            font-size: 0.85em;
            color: #374151;
        }
        .pallet-action-grid input[type="text"],
        .pallet-action-grid input[type="number"] {
            width: 100%;
            padding: 8px 10px;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            font-size: 0.9em;
            box-sizing: border-box;
        }
        .pallet-action-mixed {
            padding: 10px 14px;
            background: #fef3cd;
            border: 1px solid #ffc107;
            border-radius: 8px;
            font-size: 0.88em;
            color: #856404;
        }

        /* ========== HISTORY SUB-TABS ========== */
        .history-toggle {
            display: flex;
            gap: 4px;
            margin-bottom: 16px;
            background: #f1f5f9;
            border-radius: 8px;
            padding: 3px;
            display: inline-flex;
        }
        .history-toggle-btn {
            padding: 7px 18px;
            border: none;
            border-radius: 6px;
            background: transparent;
            color: #475569;
            font-size: 0.88em;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
        }
        .history-toggle-btn.active {
            background: #fff;
            color: #293E4C;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        .history-view {
            display: none;
        }
        .history-view.active {
            display: block;
        }

        /* ========== FLASH MESSAGES ========== */
        .flash-success {
            background: #ecfdf5;
            border: 1px solid #86efac;
            border-radius: 10px;
            padding: 12px 16px;
            margin-bottom: 20px;
            color: #166534;
            font-weight: 500;
        }
        .flash-error {
            background: #fef2f2;
            border: 1px solid #fecaca;
            border-radius: 10px;
            padding: 12px 16px;
            margin-bottom: 20px;
            color: #dc2626;
            font-weight: 500;
        }

        /* ========== MODALS ========== */
        .port-modal-overlay {
            display: none;
            position: fixed;
            z-index: 2000;
            left: 0; top: 0;
            width: 100%; height: 100%;
            background: rgba(0,0,0,0.45);
            animation: fadeIn 0.2s ease;
        }
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        .port-modal {
            background: #fff;
            margin: 6% auto;
            max-width: 620px;
            border-radius: 12px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.2);
            animation: slideInModal 0.3s ease;
            overflow: hidden;
        }
        @keyframes slideInModal {
            from { opacity: 0; transform: translateY(-30px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .port-modal-header {
            background: linear-gradient(135deg, #488C9A 0%, #3A6E7F 100%);
            color: #fff;
            padding: 16px 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .port-modal-header h3 {
            margin: 0;
            font-size: 1.1em;
            font-weight: 600;
        }
        .port-modal-close {
            background: none;
            border: none;
            color: #fff;
            font-size: 1.5em;
            cursor: pointer;
            opacity: 0.8;
            transition: opacity 0.2s;
            line-height: 1;
        }
        .port-modal-close:hover {
            opacity: 1;
        }
        .port-modal-body {
            padding: 20px;
        }
        .modal-form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 14px;
            margin-bottom: 14px;
        }
        .modal-form-row label {
            display: block;
            font-weight: 600;
            margin-bottom: 4px;
            font-size: 0.9em;
            color: #374151;
        }
        .modal-form-row input,
        .modal-form-row select {
            width: 100%;
            padding: 9px 10px;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            font-size: 0.9em;
            box-sizing: border-box;
        }

        /* Drayage modal hold banner */
        .drayage-hold-banner {
            display: flex;
            align-items: flex-start;
            gap: 8px;
            background: #fef3cd;
            border: 1px solid #ffc107;
            border-radius: 8px;
            padding: 10px 14px;
            margin-bottom: 16px;
            font-size: 0.88em;
            color: #856404;
        }
        .drayage-hold-banner-icon {
            font-size: 1.2em;
            flex-shrink: 0;
        }

        /* Drayage hold warning overlay */
        .drayage-hold-warning-overlay {
            position: fixed;
            z-index: 3000;
            left: 0; top: 0;
            width: 100%; height: 100%;
            background: rgba(0,0,0,0.55);
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .drayage-hold-warning-box {
            background: #fff;
            border-radius: 14px;
            padding: 28px 30px;
            max-width: 460px;
            width: 90%;
            text-align: center;
            box-shadow: 0 12px 50px rgba(0,0,0,0.25);
        }
        .drayage-hold-warning-box h3 {
            margin: 10px 0 8px;
            color: #293E4C;
        }
        .drayage-hold-warning-box p {
            color: #64748b;
            font-size: 0.92em;
            line-height: 1.5;
        }
        .drayage-hold-warning-icon {
            font-size: 2.5em;
            color: #f59e0b;
        }
        .drayage-hold-warning-details {
            background: #fef3cd;
            border-radius: 8px;
            padding: 10px;
            margin: 12px 0;
            font-size: 0.88em;
            color: #92400e;
            text-align: left;
        }
        .drayage-hold-warning-actions {
            display: flex;
            gap: 10px;
            justify-content: center;
            margin-top: 18px;
        }

        /* ========== BUTTON STYLES ========== */
        .port-btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 9px 18px;
            border: none;
            border-radius: 8px;
            font-size: 0.88em;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        .port-btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }
        .port-btn-primary {
            background: #488C9A;
            color: #fff;
        }
        .port-btn-primary:hover:not(:disabled) {
            background: #3A6E7F;
        }
        .port-btn-danger {
            background: #dc2626;
            color: #fff;
        }
        .port-btn-danger:hover:not(:disabled) {
            background: #b91c1c;
        }
        .port-btn-secondary {
            background: #f1f5f9;
            color: #475569;
            border: 1px solid #d1d5db;
        }
        .port-btn-secondary:hover:not(:disabled) {
            background: #e2e8f0;
        }
        .port-btn-success {
            background: #059669;
            color: #fff;
        }
        .port-btn-success:hover:not(:disabled) {
            background: #047857;
        }

        /* Action button (reuse from portal.css pattern) */
        .action-button {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 10px 20px;
            background: #488C9A;
            color: #fff;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            font-size: 0.9em;
            cursor: pointer;
            text-decoration: none;
            transition: all 0.2s;
        }
        .action-button:hover {
            background: #3A6E7F;
        }
        .action-button:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }
        .action-button-secondary {
            background: #f1f5f9;
            color: #475569;
            border: 1px solid #d1d5db;
        }
        .action-button-secondary:hover {
            background: #e2e8f0;
        }
        .action-button-danger {
            background: #dc2626;
        }
        .action-button-danger:hover {
            background: #b91c1c;
        }

        /* ========== EMPTY STATE ========== */
        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: #94a3b8;
        }
        .empty-state i {
            font-size: 2.5em;
            margin-bottom: 10px;
            display: block;
        }
        .empty-state p {
            font-size: 0.95em;
            margin: 0;
        }

        /* ========== VERIFICATION PANEL ========== */
        .verification-panel {
            border: 1px solid #fca5a5;
            background: #fef2f2;
            border-radius: 10px;
            padding: 12px;
            margin-bottom: 16px;
        }
        .verification-panel h4 {
            margin: 0 0 8px;
            color: #1f3442;
            font-size: 0.96rem;
        }
        .verification-panel p {
            margin: 0 0 10px;
            color: #526577;
            font-size: 0.88rem;
        }
        .verification-table-wrap {
            overflow-x: auto;
            border: 1px solid #d7e4ec;
            border-radius: 8px;
            background: #fff;
        }
        .verification-table {
            width: 100%;
            border-collapse: collapse;
        }
        .verification-table th,
        .verification-table td {
            border: 1px solid #e5edf3;
            padding: 8px 9px;
            text-align: left;
            font-size: 0.82rem;
        }
        .verification-table th {
            background: #eff6fa;
            color: #2c4758;
            font-weight: 700;
        }
        .verification-ok { color: #166534; font-weight: 700; }
        .verification-mismatch { color: #b91c1c; font-weight: 700; }

        /* ========== RESPONSIVE ========== */
        @media (max-width: 992px) {
            .facility-header-content {
                flex-direction: column;
                align-items: flex-start;
                gap: 16px;
            }
            .facility-header-stats {
                width: 100%;
            }
            .facility-header-hero {
                margin: 0 10px 20px 10px;
                padding: 20px;
            }
        }
        @media (max-width: 768px) {
            .facility-header-icon {
                width: 100px;
                height: 80px;
                border-radius: 14px;
            }
            .facility-header-info h1 {
                font-size: 1.5em;
            }
            .facility-header-stats {
                flex-direction: column;
                gap: 8px;
            }
            .facility-stat {
                flex-direction: row;
                gap: 10px;
                padding: 10px 16px;
            }
        }
        @media (max-width: 600px) {
            .port-toolbar {
                flex-direction: column;
                align-items: stretch;
            }
            .port-search {
                width: 100%;
            }
            .modal-form-row {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
<?php include 'header.php'; ?>
<?php require_once 'components/breadcrumbs.php'; ?>
<main>
    <?php
    $extra = [];
    if ($from_project_id <= 0) { $extra[] = ['label'=>'Manage Warehouses','url'=>'manage_warehouses.php']; }
    echo slp_render_breadcrumbs(['current_label' => 'Port Operations', 'extra' => $extra]);
    ?>

    <?php if (!empty($successMessage)): ?>
        <div class="flash-success"><?php echo htmlspecialchars($successMessage); ?></div>
    <?php endif; ?>

    <?php if (!empty($customs_action_result) && !empty($customs_action_result['rows']) && is_array($customs_action_result['rows'])):
        $verification_expected_status = (string)($customs_action_result['expected_status'] ?? '');
        $verification_mismatch_count = 0;
        foreach ($customs_action_result['rows'] as $vr) {
            if ((string)($vr['status'] ?? '') !== $verification_expected_status) $verification_mismatch_count++;
        }
        if ($verification_mismatch_count > 0): ?>
        <div class="verification-panel">
            <h4>Customs Action Verification</h4>
            <p>Expected status: <strong><?php echo htmlspecialchars($verification_expected_status); ?></strong>. <?php echo $verification_mismatch_count; ?> pallet(s) did not reach expected status.</p>
            <div class="verification-table-wrap">
                <table class="verification-table">
                    <thead><tr><th>Pallet</th><th>Container</th><th>Current Status</th><th>Check</th></tr></thead>
                    <tbody>
                    <?php foreach ($customs_action_result['rows'] as $vr): ?>
                        <?php $ok = ((string)($vr['status'] ?? '') === $verification_expected_status); ?>
                        <tr>
                            <td><?php echo htmlspecialchars($vr['pallet_identifier'] ?? ('ID ' . (int)($vr['id'] ?? 0))); ?></td>
                            <td><?php echo htmlspecialchars($vr['container_number'] ?? 'N/A'); ?></td>
                            <td><?php echo htmlspecialchars($vr['status'] ?? 'N/A'); ?></td>
                            <td class="<?php echo $ok ? 'verification-ok' : 'verification-mismatch'; ?>"><?php echo $ok ? 'OK' : 'Mismatch'; ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; endif; ?>

    <?php if (!empty($errorMessage)): ?>
        <div class="flash-error"><strong>Error:</strong> <?php echo htmlspecialchars($errorMessage); ?></div>
    <?php elseif (!$warehouse): ?>
        <div class="flash-error">Port not found or could not be loaded.</div>
    <?php else: ?>

    <!-- FACILITY HERO HEADER -->
    <?php
        // Resolve port image
        $port_image_path = '';
        $has_port_image = false;
        if (!empty($warehouse['image_url'])) {
            if (filter_var($warehouse['image_url'], FILTER_VALIDATE_URL)) {
                $port_image_path = $warehouse['image_url'];
                $has_port_image = true;
            } else {
                $port_image_path = htmlspecialchars($warehouse['image_url']);
                if (strpos($port_image_path, 'uploads/') !== 0 && strpos($port_image_path, 'pictures/') !== 0) {
                    $port_image_path = 'uploads/warehouse_images/' . ltrim($port_image_path, '/');
                }
                $has_port_image = file_exists(__DIR__ . '/' . $port_image_path);
            }
        }
        $address_parts = array_filter([
            $warehouse['street_address'] ?? '',
            $warehouse['city'] ?? '',
            $warehouse['state'] ?? '',
            $warehouse['zip_code'] ?? ''
        ]);
    ?>
    <div class="facility-header-hero">
        <div class="facility-header-content">
            <div class="facility-header-left">
                <div class="facility-header-icon">
                    <?php if ($has_port_image): ?>
                        <img src="<?php echo $port_image_path; ?>" alt="<?php echo htmlspecialchars($warehouse['name']); ?>">
                    <?php else: ?>
                        <div class="facility-header-icon-placeholder">
                            <i class="fas fa-anchor"></i>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="facility-header-info">
                    <div class="facility-title-row">
                        <h1><?php echo htmlspecialchars($warehouse['name']); ?></h1>
                    </div>
                    <p class="facility-header-subtitle"><?php echo htmlspecialchars(implode(', ', $address_parts)); ?></p>
                    <div class="facility-header-meta">
                        <?php if (!empty($warehouse_fees['all_items'])): ?>
                            <button type="button" class="facility-meta-badge fees-badge" onclick="openFeeModal()">
                                <i class="fas fa-file-invoice-dollar"></i>
                                Cost Structure (<?php echo count($warehouse_fees['all_items']); ?> fee<?php echo count($warehouse_fees['all_items']) !== 1 ? 's' : ''; ?>)
                            </button>
                        <?php endif; ?>
                        <a href="edit_warehouse.php?warehouse_id=<?php echo $warehouse_id; ?>" class="facility-meta-badge edit-badge">
                            <i class="fas fa-pencil-alt"></i> Edit Port
                        </a>
                    </div>
                </div>
            </div>
            <div class="facility-header-stats">
                <div class="facility-stat">
                    <span class="facility-stat-value"><?php echo $stat_containers; ?></span>
                    <span class="facility-stat-label">Containers</span>
                </div>
                <div class="facility-stat accent-green">
                    <span class="facility-stat-value"><?php echo $stat_pallets_cleared; ?></span>
                    <span class="facility-stat-label">Cleared</span>
                </div>
                <div class="facility-stat accent-red">
                    <span class="facility-stat-value"><?php echo $stat_pallets_held; ?></span>
                    <span class="facility-stat-label">On Hold</span>
                </div>
                <div class="facility-stat">
                    <span class="facility-stat-value"><?php echo $stat_inbound; ?></span>
                    <span class="facility-stat-label">Inbound</span>
                </div>
            </div>
        </div>
    </div>

    <div class="port-page">
        <!-- TABS -->
        <div class="port-tabs">
            <button class="port-tab active" data-tab="atPort" onclick="showPortTab('atPort')">At Port (<?php echo $stat_containers; ?>)</button>
            <button class="port-tab" data-tab="inbound" onclick="showPortTab('inbound')">Inbound (<?php echo $stat_inbound; ?>)</button>
            <button class="port-tab" data-tab="history" onclick="showPortTab('history')">History (<?php echo count($inbound_history) + count($outbound_history); ?>)</button>
        </div>

        <!-- ==================== TAB: AT PORT ==================== -->
        <div class="port-tab-content active" id="tab-atPort">
            <?php if (!empty($containers_at_port)): ?>
            <div class="ux-hint" id="hintAtPort">
                <i class="fas fa-info-circle"></i>
                <span>Click any container row to expand it and see individual pallets. Select pallets to place on <strong>Customs Hold</strong> or <strong>Release</strong>. Use checkboxes + "Move (Drayage)" to ship containers out.</span>
                <button class="ux-hint-dismiss" onclick="this.closest('.ux-hint').remove()" title="Dismiss">&times;</button>
            </div>
            <?php endif; ?>

            <div class="port-toolbar">
                <input type="text" class="port-search" id="containerSearch" placeholder="Search containers..." oninput="filterContainerTable()">
                <div class="port-toolbar-actions">
                    <span class="selection-counter" id="containerSelectionCounter">0 selected</span>
                    <button class="port-btn port-btn-primary" id="moveContainerBtn" disabled onclick="openDrayageModal()">
                        <i class="fas fa-truck"></i> Move (Drayage)
                    </button>
                </div>
            </div>

            <?php if (empty($containers_at_port)): ?>
                <div class="empty-state">
                    <i class="fas fa-box-open"></i>
                    <p>No containers at port. Switch to the <strong>Inbound</strong> tab to receive incoming shipments.</p>
                </div>
            <?php else: ?>
                <div class="port-table-container">
                    <table class="port-table" id="containerTable">
                        <thead>
                            <tr>
                                <th style="width:36px;"><input type="checkbox" id="selectAllContainers" onchange="toggleAllContainers()"></th>
                                <th>Container</th>
                                <th>Project</th>
                                <th>Vendor</th>
                                <th>Pallets</th>
                                <th>Customs Hold</th>
                                <th>Modules</th>
                                <th>Arrived</th>
                                <th style="width:36px;"></th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($containers_at_port as $container): ?>
                            <!-- Container summary row -->
                            <tr class="container-row" data-delivery-id="<?php echo (int)$container['delivery_id']; ?>" onclick="toggleContainer(<?php echo (int)$container['delivery_id']; ?>, event)">
                                <td onclick="event.stopPropagation();">
                                    <input type="checkbox"
                                        class="container-checkbox"
                                        value="<?php echo (int)$container['delivery_id']; ?>"
                                        data-container-number="<?php echo htmlspecialchars($container['container_number']); ?>"
                                        data-hold-pallets="<?php echo (int)$container['held_count']; ?>"
                                        data-hold-modules="<?php echo (int)$container['held_modules']; ?>"
                                        onchange="updateContainerSelection()">
                                </td>
                                <td><strong><?php echo htmlspecialchars($container['container_number']); ?></strong></td>
                                <td><?php echo htmlspecialchars($container['projects']); ?></td>
                                <td><?php echo htmlspecialchars($container['origin_vendor']); ?></td>
                                <td><?php echo (int)$container['total_pallets']; ?></td>
                                <td>
                                    <?php if ((int)$container['held_count'] > 0): ?>
                                        <span class="hold-badge"><?php echo (int)$container['held_count']; ?> on hold</span>
                                    <?php else: ?>
                                        <span class="hold-badge none">All clear</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo number_format((int)$container['total_modules']); ?></td>
                                <td><?php echo $container['arrival_date'] ? date('m-d-Y', strtotime($container['arrival_date'])) : 'N/A'; ?></td>
                                <td><span class="expand-arrow" id="arrow-<?php echo (int)$container['delivery_id']; ?>">&#9654;</span></td>
                            </tr>

                            <!-- Expandable pallet detail row -->
                            <tr class="pallet-detail-row" id="pallets-<?php echo (int)$container['delivery_id']; ?>" style="display:none;">
                                <td colspan="9">
                                    <div class="pallet-panel">
                                        <!-- Contextual action bar (hidden by default, appears above table) -->
                                        <div class="pallet-action-bar" id="action-bar-<?php echo (int)$container['delivery_id']; ?>" style="display:none;">
                                            <!-- Will be populated by JS based on selection -->
                                        </div>

                                        <table class="pallet-sub-table">
                                            <thead>
                                                <tr>
                                                    <th style="width:36px;"><input type="checkbox" onchange="toggleAllPallets(<?php echo (int)$container['delivery_id']; ?>, this)"></th>
                                                    <th>Pallet</th>
                                                    <th>Wattage</th>
                                                    <th>Modules</th>
                                                    <th>Status</th>
                                                    <th>Hold Cost</th>
                                                    <th>Notes</th>
                                                    <th>View</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                            <?php foreach ($container['pallets'] as $pallet): ?>
                                                <tr>
                                                    <td>
                                                        <input type="checkbox"
                                                            class="pallet-checkbox pallet-cb-<?php echo (int)$container['delivery_id']; ?>"
                                                            value="<?php echo (int)$pallet['pallet_id']; ?>"
                                                            data-status="<?php echo htmlspecialchars($pallet['status']); ?>"
                                                            onchange="updatePalletSelection(<?php echo (int)$container['delivery_id']; ?>)">
                                                    </td>
                                                    <td><?php echo htmlspecialchars($pallet['pallet_identifier'] ?? 'ID ' . $pallet['pallet_id']); ?></td>
                                                    <td><?php echo htmlspecialchars($pallet['wattage']); ?>W</td>
                                                    <td><?php echo number_format((int)$pallet['quantity']); ?></td>
                                                    <td>
                                                        <?php if ($pallet['status'] === 'Customs Hold'): ?>
                                                            <span class="status-badge status-held">On Hold</span>
                                                        <?php else: ?>
                                                            <span class="status-badge status-cleared">Cleared</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td><?php echo (float)$pallet['customs_hold_cost'] > 0 ? '$' . number_format((float)$pallet['customs_hold_cost'], 2) : '-'; ?></td>
                                                    <td title="<?php echo htmlspecialchars($pallet['customs_hold_cost_notes'] ?? ''); ?>"><?php echo htmlspecialchars(mb_strimwidth($pallet['customs_hold_cost_notes'] ?? '-', 0, 30, '...')); ?></td>
                                                    <td>
                                                        <a href="pallet_details.php?pallet_id=<?php echo (int)$pallet['pallet_id']; ?>&from=warehouse_info&warehouse_id=<?php echo $warehouse_id; ?>" style="color:#488C9A; text-decoration:none; font-weight:600;">View</a>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <!-- ==================== TAB: INBOUND ==================== -->
        <div class="port-tab-content" id="tab-inbound">
            <?php if (!empty($inbound_truckloads)): ?>
            <div class="ux-hint" id="hintInbound">
                <i class="fas fa-info-circle"></i>
                <span>Select one or more inbound containers, then click <strong>"Receive Selected"</strong> to mark them as arrived and cleared. They will then appear in the <strong>At Port</strong> tab.</span>
                <button class="ux-hint-dismiss" onclick="this.closest('.ux-hint').remove()" title="Dismiss">&times;</button>
            </div>
            <?php endif; ?>

            <div class="port-toolbar">
                <input type="text" class="port-search" id="inboundSearch" placeholder="Search inbound..." oninput="filterInboundTable()">
                <div class="port-toolbar-actions">
                    <button class="port-btn port-btn-primary" id="receiveBtn" disabled onclick="openReceiveModal()">
                        <i class="fas fa-download"></i> Receive Selected
                    </button>
                </div>
            </div>

            <?php if (empty($inbound_truckloads)): ?>
                <div class="empty-state">
                    <i class="fas fa-ship"></i>
                    <p>No inbound shipments at this time.</p>
                </div>
            <?php else: ?>
                <div class="port-table-container">
                    <table class="port-table" id="inboundTable">
                        <thead>
                            <tr>
                                <th style="width:36px;"><input type="checkbox" id="selectAllInbound" onchange="toggleAllInbound()"></th>
                                <th>Container / BOL</th>
                                <th>Project</th>
                                <th>Vendor</th>
                                <th>Pallets</th>
                                <th>Modules</th>
                                <th>Wattages</th>
                                <th>Est. Arrival</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($inbound_truckloads as $truckload): ?>
                            <tr>
                                <td><input type="checkbox" class="inbound-checkbox" value="<?php echo (int)$truckload['delivery_id']; ?>" onchange="updateInboundSelection()"></td>
                                <td><strong><?php echo htmlspecialchars($truckload['bol_number'] ?? 'N/A'); ?></strong></td>
                                <td><?php echo htmlspecialchars($truckload['projects']); ?></td>
                                <td><?php echo htmlspecialchars($truckload['origin_vendor'] ?? 'N/A'); ?></td>
                                <td><?php echo (int)$truckload['total_pallets']; ?></td>
                                <td><?php echo number_format((int)$truckload['total_modules']); ?></td>
                                <td><?php echo htmlspecialchars($truckload['wattages'] ?? 'N/A'); ?></td>
                                <td><?php echo $truckload['est_arrival_date'] ? date('m-d-Y', strtotime($truckload['est_arrival_date'])) : 'N/A'; ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <!-- ==================== TAB: HISTORY ==================== -->
        <div class="port-tab-content" id="tab-history">
            <div class="history-toggle">
                <button class="history-toggle-btn active" onclick="showHistoryView('inbound', this)">Inbound (<?php echo count($inbound_history); ?>)</button>
                <button class="history-toggle-btn" onclick="showHistoryView('outbound', this)">Outbound (<?php echo count($outbound_history); ?>)</button>
            </div>

            <!-- Inbound History -->
            <div class="history-view active" id="history-inbound">
                <?php if (empty($inbound_history)): ?>
                    <div class="empty-state"><i class="fas fa-history"></i><p>No inbound history records.</p></div>
                <?php else: ?>
                    <div class="port-table-container">
                        <table class="port-table">
                            <thead>
                                <tr>
                                    <th>Container / BOL</th>
                                    <th>Supplier</th>
                                    <th>Project</th>
                                    <th>Pallets</th>
                                    <th>Modules</th>
                                    <th>Wattages</th>
                                    <th>Arrived</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($inbound_history as $record): ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($record['bol_number'] ?? 'N/A'); ?></strong></td>
                                    <td><?php echo htmlspecialchars($record['supplier'] ?? 'N/A'); ?></td>
                                    <td><?php echo htmlspecialchars($record['projects']); ?></td>
                                    <td><?php echo (int)$record['total_pallets']; ?></td>
                                    <td><?php echo number_format((int)$record['total_modules']); ?></td>
                                    <td><?php echo htmlspecialchars($record['wattages'] ?? 'N/A'); ?></td>
                                    <td><?php echo $record['warehouse_arrival_date'] ? date('m-d-Y', strtotime($record['warehouse_arrival_date'])) : 'N/A'; ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Outbound History -->
            <div class="history-view" id="history-outbound">
                <?php if (empty($outbound_history)): ?>
                    <div class="empty-state"><i class="fas fa-history"></i><p>No outbound history records.</p></div>
                <?php else: ?>
                    <div class="port-table-container">
                        <table class="port-table">
                            <thead>
                                <tr>
                                    <th>BOL</th>
                                    <th>Destination</th>
                                    <th>Pallets</th>
                                    <th>Modules</th>
                                    <th>Wattages</th>
                                    <th>Departed</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($outbound_history as $record): ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($record['bol_number'] ?? 'N/A'); ?></strong></td>
                                    <td><?php echo htmlspecialchars($record['destinations'] ?? $record['projects']); ?></td>
                                    <td><?php echo (int)$record['total_pallets']; ?></td>
                                    <td><?php echo number_format((int)$record['total_modules']); ?></td>
                                    <td><?php echo htmlspecialchars($record['wattages'] ?? 'N/A'); ?></td>
                                    <td><?php echo $record['departure_date'] ? date('m-d-Y', strtotime($record['departure_date'])) : 'N/A'; ?></td>
                                    <td><?php echo htmlspecialchars($record['status_of_delivery'] ?? 'N/A'); ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

    </div><!-- /.port-page -->
    <?php endif; ?>
</main>

<!-- ==================== DRAYAGE MODAL ==================== -->
<div class="port-modal-overlay" id="drayageModalOverlay">
    <div class="port-modal" style="max-width:620px;">
        <div class="port-modal-header">
            <h3>Move Container (Drayage)</h3>
            <button class="port-modal-close" onclick="closeDrayageModal()">&times;</button>
        </div>
        <div class="port-modal-body">
            <div id="drayageHoldBanner" style="display:none;"></div>

            <form id="drayageForm" method="POST" action="create_shipment.php">
                <div class="modal-form-row">
                    <div>
                        <label for="dray_departure_date">Departure Date:</label>
                        <input type="date" id="dray_departure_date" name="departure_date" required>
                    </div>
                    <div>
                        <label for="dray_est_arrival">Est. Arrival Date:</label>
                        <input type="date" id="dray_est_arrival" name="est_arrival_date" required>
                    </div>
                </div>
                <div class="modal-form-row">
                    <div>
                        <label for="dray_freight_cost">Freight Cost ($):</label>
                        <input type="number" id="dray_freight_cost" name="freight_cost" step="0.01" min="0">
                    </div>
                    <div>
                        <label for="dray_customer_cost">Customer Cost ($):</label>
                        <input type="number" id="dray_customer_cost" name="customer_cost" step="0.01" min="0">
                    </div>
                </div>

                <div style="margin-bottom:14px;">
                    <div style="display:flex; align-items:flex-start; gap:20px;">
                        <div style="flex:1;">
                            <label style="font-weight:600; display:block; margin-bottom:6px;">Origin:</label>
                            <div style="padding:10px 14px; background:#f8f9fa; border:1px solid #ddd; border-radius:6px;">
                                <strong><?php echo htmlspecialchars($warehouse['name'] ?? ''); ?></strong>
                            </div>
                        </div>
                        <div style="display:flex; flex-direction:column; justify-content:center; align-items:center; margin-top:30px;">
                            <div style="font-size:1.6em; color:#488C9A;">&#8594;</div>
                            <div id="drayageDistanceDisplay" style="font-weight:bold; color:#488C9A; white-space:nowrap; font-size:0.85em;"></div>
                        </div>
                        <div style="flex:1;">
                            <label style="font-weight:600; display:block; margin-bottom:6px;">Destination:</label>
                            <div style="display:flex; gap:12px; margin-bottom:8px;">
                                <label style="font-weight:normal; display:flex; align-items:center; gap:4px;">
                                    <input type="radio" name="destination_type" value="project" checked onchange="updateDrayageDestinations()"> Project
                                </label>
                                <label style="font-weight:normal; display:flex; align-items:center; gap:4px;">
                                    <input type="radio" name="destination_type" value="warehouse" onchange="updateDrayageDestinations()"> Warehouse
                                </label>
                            </div>
                            <select id="dray_destination" name="destination_id" required onchange="calculateDrayageMiles()" style="width:100%; padding:9px; border:1px solid #d1d5db; border-radius:6px;">
                                <option value="">-- Select Destination --</option>
                            </select>
                        </div>
                    </div>
                    <input type="hidden" id="dray_miles" name="miles" value="">
                </div>

                <input type="hidden" name="action" value="ship_pallets">
                <input type="hidden" name="origin_type" value="warehouse">
                <input type="hidden" name="origin_id" value="<?php echo $warehouse_id; ?>">
                <input type="hidden" id="dray_container_ids" name="drayage_container_ids" value="">
                <input type="hidden" id="dray_pallet_ids" name="selected_pallets" value="">
                <input type="hidden" id="dray_bol" name="bol_number" value="">
                <input type="hidden" id="dray_container_number" name="container_number" value="">

                <div style="text-align:center; margin-top:16px;">
                    <button type="submit" class="action-button" id="confirmDrayageBtn">
                        <i class="fas fa-truck"></i> Create Drayage Shipment
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ==================== RECEIVE MODAL ==================== -->
<div class="port-modal-overlay" id="receiveModalOverlay">
    <div class="port-modal">
        <div class="port-modal-header">
            <h3>Receive Container(s) <span style="font-size:0.8em; font-weight:normal; opacity:0.8;">(Cleared Customs)</span></h3>
            <button class="port-modal-close" onclick="closeReceiveModal()">&times;</button>
        </div>
        <div class="port-modal-body">
            <div class="modal-form-row">
                <div>
                    <label for="receive_house_bol">House BOL:</label>
                    <input type="text" id="receive_house_bol" placeholder="House Bill of Lading">
                </div>
                <div>
                    <label for="receive_master_bol">Master BOL:</label>
                    <input type="text" id="receive_master_bol" placeholder="Master Bill of Lading">
                </div>
            </div>
            <div class="modal-form-row">
                <div>
                    <label for="receive_arrival_date">Container Cleared Date:</label>
                    <input type="date" id="receive_arrival_date" required>
                </div>
                <div></div>
            </div>
            <div style="text-align:center; margin-top:16px;">
                <button type="button" class="action-button" id="confirmReceiveBtn" onclick="submitReceive()">
                    <i class="fas fa-download"></i> Receive Container(s)
                </button>
            </div>
        </div>
    </div>
</div>

<!-- ==================== FEE MODAL ==================== -->
<?php if (!empty($warehouse_fees['all_items'])): ?>
<div class="port-modal-overlay" id="feeModalOverlay">
    <div class="port-modal" style="max-width:550px;">
        <div class="port-modal-header">
            <h3>Cost Structure - <?php echo htmlspecialchars($warehouse['name']); ?></h3>
            <button class="port-modal-close" onclick="closeFeeModal()">&times;</button>
        </div>
        <div class="port-modal-body" style="max-height:400px; overflow-y:auto;">
            <table style="width:100%; border-collapse:collapse;">
                <thead>
                    <tr>
                        <th style="text-align:left; padding:10px 12px; background:#f8f9fa; font-weight:600; font-size:0.85em; color:#495057; border-bottom:2px solid #dee2e6;">Fee Name</th>
                        <th style="text-align:left; padding:10px 12px; background:#f8f9fa; font-weight:600; font-size:0.85em; color:#495057; border-bottom:2px solid #dee2e6;">Amount</th>
                        <th style="text-align:left; padding:10px 12px; background:#f8f9fa; font-weight:600; font-size:0.85em; color:#495057; border-bottom:2px solid #dee2e6;">Unit</th>
                        <th style="text-align:left; padding:10px 12px; background:#f8f9fa; font-weight:600; font-size:0.85em; color:#495057; border-bottom:2px solid #dee2e6;">When</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($warehouse_fees['all_items'] as $fee):
                    $unit_display = 'Per Pallet';
                    switch ($fee['unit_type'] ?? 'per_pallet') {
                        case 'per_truck': $unit_display = 'Per Truck'; break;
                        case 'per_sqft': $unit_display = 'Per Sq Ft'; break;
                        case 'flat': $unit_display = 'Flat Rate'; break;
                    }
                    $trigger = $fee['trigger_event'] ?? 'other';
                    $trigger_display = ucwords(str_replace('_', ' ', $trigger));
                ?>
                    <tr>
                        <td style="padding:12px; border-bottom:1px solid #f0f0f0; font-weight:500; color:#293E4C;"><?php echo htmlspecialchars($fee['label']); ?></td>
                        <td style="padding:12px; border-bottom:1px solid #f0f0f0; font-weight:600; color:#488C9A;">$<?php echo number_format($fee['amount'], 2); ?></td>
                        <td style="padding:12px; border-bottom:1px solid #f0f0f0; color:#6c757d; font-size:0.85em;"><?php echo $unit_display; ?></td>
                        <td style="padding:12px; border-bottom:1px solid #f0f0f0;"><span style="display:inline-block; padding:3px 8px; border-radius:4px; font-size:0.8em; font-weight:500; background:#f1f5f9; color:#475569;"><?php echo $trigger_display; ?></span></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div style="padding:12px 20px; background:#f8f9fa; border-top:1px solid #dee2e6; text-align:right;">
            <a href="edit_warehouse.php?warehouse_id=<?php echo $warehouse_id; ?>" class="action-button" style="font-size:0.88em; padding:8px 16px;">
                <i class="fas fa-edit"></i> Edit Fees
            </a>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
// ========== DATA ==========
const projectsData = <?php echo json_encode($all_projects); ?>;
const warehousesData = <?php echo json_encode($other_warehouses); ?>;
const warehouseId = <?php echo (int)$warehouse_id; ?>;

// ========== TAB MANAGEMENT ==========
function showPortTab(tabName) {
    document.querySelectorAll('.port-tab-content').forEach(el => el.classList.remove('active'));
    document.querySelectorAll('.port-tab').forEach(btn => btn.classList.remove('active'));

    const tabEl = document.getElementById('tab-' + tabName);
    if (tabEl) tabEl.classList.add('active');

    const tabBtn = document.querySelector('.port-tab[data-tab="' + tabName + '"]');
    if (tabBtn) tabBtn.classList.add('active');

    // Update URL hash
    const url = new URL(window.location);
    url.searchParams.set('tab', tabName);
    history.replaceState(null, '', url);
}

// ========== HISTORY TOGGLE ==========
function showHistoryView(view, btn) {
    document.querySelectorAll('.history-view').forEach(el => el.classList.remove('active'));
    document.querySelectorAll('.history-toggle-btn').forEach(b => b.classList.remove('active'));
    document.getElementById('history-' + view)?.classList.add('active');
    if (btn) btn.classList.add('active');
}

// ========== CONTAINER EXPAND/COLLAPSE ==========
function toggleContainer(deliveryId, event) {
    // Don't toggle if clicking a checkbox or link
    if (event && (event.target.type === 'checkbox' || event.target.tagName === 'A')) return;

    const detailRow = document.getElementById('pallets-' + deliveryId);
    const arrow = document.getElementById('arrow-' + deliveryId);
    if (!detailRow) return;

    const isVisible = detailRow.style.display !== 'none';
    detailRow.style.display = isVisible ? 'none' : 'table-row';
    if (arrow) arrow.classList.toggle('open', !isVisible);
}

// ========== CONTAINER SELECTION (for drayage) ==========
function toggleAllContainers() {
    const selectAll = document.getElementById('selectAllContainers');
    document.querySelectorAll('.container-checkbox').forEach(cb => cb.checked = selectAll.checked);
    updateContainerSelection();
}

function updateContainerSelection() {
    const checked = document.querySelectorAll('.container-checkbox:checked');
    const total = document.querySelectorAll('.container-checkbox');
    const counter = document.getElementById('containerSelectionCounter');
    const moveBtn = document.getElementById('moveContainerBtn');
    const selectAll = document.getElementById('selectAllContainers');

    if (counter) counter.textContent = checked.length + ' selected';
    if (moveBtn) moveBtn.disabled = checked.length === 0;
    if (selectAll && total.length > 0) {
        selectAll.checked = checked.length === total.length;
        selectAll.indeterminate = checked.length > 0 && checked.length < total.length;
    }
}

// ========== PALLET SELECTION (for customs actions) ==========
function toggleAllPallets(deliveryId, selectAllCb) {
    const checkboxes = document.querySelectorAll('.pallet-cb-' + deliveryId);
    checkboxes.forEach(cb => cb.checked = selectAllCb.checked);
    updatePalletSelection(deliveryId);
}

function updatePalletSelection(deliveryId) {
    const checkboxes = document.querySelectorAll('.pallet-cb-' + deliveryId);
    const checked = document.querySelectorAll('.pallet-cb-' + deliveryId + ':checked');
    const actionBar = document.getElementById('action-bar-' + deliveryId);

    if (!actionBar) return;

    if (checked.length === 0) {
        actionBar.style.display = 'none';
        actionBar.innerHTML = '';
        return;
    }

    // Determine selection type
    let clearedCount = 0, heldCount = 0;
    checked.forEach(cb => {
        if (cb.dataset.status === 'Cleared Customs') clearedCount++;
        else if (cb.dataset.status === 'Customs Hold') heldCount++;
    });

    let html = '';

    if (clearedCount > 0 && heldCount > 0) {
        // Mixed selection
        html = `
            <div class="pallet-action-mixed">
                <strong>Mixed Selection:</strong> ${clearedCount} cleared + ${heldCount} on hold selected.
                Select only cleared pallets to place on hold, or only held pallets to release.
            </div>`;
    } else if (clearedCount > 0) {
        // Place on hold form
        html = `
            <form method="POST" action="manage_port_inventory.php?warehouse_id=${warehouseId}">
                <input type="hidden" name="action" value="place_customs_hold">
                <h4>Place ${clearedCount} Pallet(s) on Customs Hold</h4>
                <div class="pallet-action-grid">
                    <div>
                        <label>Hold Reason *</label>
                        <input type="text" name="customs_hold_reason" required maxlength="120" placeholder="e.g., Inspection required">
                    </div>
                    <div>
                        <label>Cost Per Pallet ($)</label>
                        <input type="number" name="customs_cost_per_pallet" step="0.01" min="0" value="0" placeholder="0.00">
                    </div>
                    <div>
                        <label>Cost Notes</label>
                        <input type="text" name="customs_cost_notes" maxlength="255" placeholder="Optional">
                    </div>
                    <div>
                        <button type="submit" class="port-btn port-btn-danger" style="margin-top:20px;">
                            <i class="fas fa-hand-paper"></i> Place on Hold
                        </button>
                    </div>
                </div>
                <div id="hold-pallet-inputs-${deliveryId}"></div>
            </form>`;
    } else {
        // Release form
        html = `
            <form method="POST" action="manage_port_inventory.php?warehouse_id=${warehouseId}">
                <input type="hidden" name="action" value="release_customs_hold">
                <h4>Release ${heldCount} Pallet(s) from Customs Hold</h4>
                <div class="pallet-action-grid">
                    <div>
                        <label>Additional Cost ($)</label>
                        <input type="number" name="customs_cost_per_pallet" step="0.01" min="0" value="0" placeholder="0.00">
                    </div>
                    <div>
                        <label>Notes</label>
                        <input type="text" name="customs_cost_notes" maxlength="255" placeholder="Optional release notes">
                    </div>
                    <div>
                        <button type="submit" class="port-btn port-btn-success" style="margin-top:20px;">
                            <i class="fas fa-check-circle"></i> Release from Hold
                        </button>
                    </div>
                </div>
                <div id="release-pallet-inputs-${deliveryId}"></div>
            </form>`;
    }

    actionBar.innerHTML = html;
    actionBar.style.display = 'block';

    // Populate hidden pallet ID inputs
    const formContainer = actionBar.querySelector('form');
    if (formContainer) {
        const inputContainer = formContainer.querySelector('[id^="hold-pallet-inputs-"], [id^="release-pallet-inputs-"]');
        if (inputContainer) {
            inputContainer.innerHTML = '';
            checked.forEach(cb => {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'customs_pallet_ids[]';
                input.value = cb.value;
                inputContainer.appendChild(input);
            });
        }
    }
}

// ========== INBOUND SELECTION ==========
function toggleAllInbound() {
    const selectAll = document.getElementById('selectAllInbound');
    document.querySelectorAll('.inbound-checkbox').forEach(cb => cb.checked = selectAll.checked);
    updateInboundSelection();
}

function updateInboundSelection() {
    const checked = document.querySelectorAll('.inbound-checkbox:checked');
    const total = document.querySelectorAll('.inbound-checkbox');
    const receiveBtn = document.getElementById('receiveBtn');
    const selectAll = document.getElementById('selectAllInbound');

    if (receiveBtn) {
        receiveBtn.disabled = checked.length === 0;
        receiveBtn.textContent = checked.length > 0
            ? `Receive Selected (${checked.length})`
            : 'Receive Selected';
    }
    if (selectAll && total.length > 0) {
        selectAll.checked = checked.length === total.length;
        selectAll.indeterminate = checked.length > 0 && checked.length < total.length;
    }
}

// ========== DRAYAGE MODAL ==========
function openDrayageModal() {
    const overlay = document.getElementById('drayageModalOverlay');
    if (!overlay) return;

    const checkedContainers = document.querySelectorAll('.container-checkbox:checked');
    if (checkedContainers.length === 0) return;

    const containerIds = Array.from(checkedContainers).map(cb => cb.value);
    overlay.dataset.containerIds = JSON.stringify(containerIds);

    // Set departure date from container arrival date
    let departureDate = new Date().toISOString().slice(0, 10);
    if (checkedContainers[0]) {
        const row = checkedContainers[0].closest('tr');
        if (row) {
            const arrivalCell = row.cells[row.cells.length - 2]; // Arrived column
            if (arrivalCell) {
                const txt = arrivalCell.textContent.trim();
                const parts = txt.split('-');
                if (parts.length === 3) {
                    departureDate = `${parts[2]}-${parts[0].padStart(2, '0')}-${parts[1].padStart(2, '0')}`;
                }
            }
        }
    }

    document.getElementById('dray_departure_date').value = departureDate;
    // Est arrival = departure + 1 day
    try {
        const d = new Date(departureDate);
        d.setDate(d.getDate() + 1);
        document.getElementById('dray_est_arrival').value = d.toISOString().slice(0, 10);
    } catch(e) {}

    // Reset form fields
    document.getElementById('dray_freight_cost').value = '';
    document.getElementById('dray_customer_cost').value = '';
    document.getElementById('dray_destination').innerHTML = '<option value="">-- Select Destination --</option>';
    document.getElementById('dray_miles').value = '';
    document.getElementById('drayageDistanceDisplay').innerHTML = '';
    const projectRadio = document.querySelector('#drayageModalOverlay input[name="destination_type"][value="project"]');
    if (projectRadio) projectRadio.checked = true;

    updateDrayageDestinations();

    // Hold warning banner
    const holdBanner = document.getElementById('drayageHoldBanner');
    if (holdBanner) {
        const holdItems = [];
        checkedContainers.forEach(cb => {
            const hp = parseInt(cb.dataset.holdPallets || '0', 10);
            const hm = parseInt(cb.dataset.holdModules || '0', 10);
            if (hp > 0) holdItems.push({ container: cb.dataset.containerNumber || 'Unknown', pallets: hp, modules: hm });
        });
        if (holdItems.length > 0) {
            const lines = holdItems.map(h =>
                `<strong>${h.container}</strong>: ${h.pallets} pallet${h.pallets !== 1 ? 's' : ''} (${h.modules.toLocaleString()} modules)`
            ).join(' &middot; ');
            holdBanner.innerHTML = `<div class="drayage-hold-banner"><span class="drayage-hold-banner-icon">&#9888;</span> <span>${lines} on <strong>Customs Hold</strong> &mdash; will not be shipped</span></div>`;
            holdBanner.style.display = '';
        } else {
            holdBanner.innerHTML = '';
            holdBanner.style.display = 'none';
        }
    }

    overlay.style.display = 'block';
}

function closeDrayageModal() {
    const overlay = document.getElementById('drayageModalOverlay');
    if (overlay) overlay.style.display = 'none';
}

function updateDrayageDestinations() {
    const selectedType = document.querySelector('#drayageModalOverlay input[name="destination_type"]:checked');
    const destSelect = document.getElementById('dray_destination');
    if (!selectedType || !destSelect) return;

    const type = selectedType.value;
    const data = type === 'project' ? projectsData : warehousesData;
    const nameField = type === 'project' ? 'project_name' : 'name';
    const label = type === 'project' ? 'Project' : 'Warehouse';

    destSelect.innerHTML = `<option value="">-- Select ${label} --</option>`;
    if (data && data.length > 0) {
        data.forEach(item => {
            const opt = document.createElement('option');
            opt.value = item.id;
            opt.textContent = item[nameField];
            opt.setAttribute('data-address', item.full_address || '');
            destSelect.appendChild(opt);
        });
    }

    document.getElementById('drayageDistanceDisplay').innerHTML = '';
    document.getElementById('dray_miles').value = '';
}

// ========== GOOGLE MAPS DISTANCE ==========
let directionsService;
let mapsInitialized = false;

function initGoogleMaps() {
    if (mapsInitialized || !window.google) return;
    directionsService = new google.maps.DirectionsService();
    mapsInitialized = true;
}

function calculateDrayageMiles() {
    const destSelect = document.getElementById('dray_destination');
    const display = document.getElementById('drayageDistanceDisplay');
    const milesField = document.getElementById('dray_miles');

    if (!destSelect || !display || !milesField) return;

    const selectedOpt = destSelect.options[destSelect.selectedIndex];
    const destAddress = selectedOpt ? selectedOpt.getAttribute('data-address') : '';
    const originAddress = <?php echo json_encode(
        ($warehouse['street_address'] ?? '') . ', ' .
        ($warehouse['city'] ?? '') . ', ' .
        ($warehouse['state'] ?? '') . ' ' .
        ($warehouse['zip_code'] ?? '')
    ); ?>;

    if (!originAddress || !destAddress) {
        display.innerHTML = '';
        milesField.value = '';
        return;
    }

    if (!mapsInitialized) initGoogleMaps();
    if (!directionsService) return;

    display.innerHTML = '<span style="color:#666;">Calculating...</span>';

    directionsService.route({
        origin: originAddress,
        destination: destAddress,
        travelMode: 'DRIVING'
    }, function(result, status) {
        if (status === 'OK') {
            const miles = (result.routes[0].legs[0].distance.value / 1609.34).toFixed(2);
            display.innerHTML = `<span style="color:#488C9A; font-weight:bold;">${miles} miles</span>`;
            milesField.value = miles;
        } else {
            display.innerHTML = '<span style="color:#d32f2f;">Could not calculate</span>';
            milesField.value = '';
        }
    });
}

// ========== DRAYAGE FORM SUBMISSION ==========
let drayageHoldConfirmed = false;

document.addEventListener('DOMContentLoaded', function() {
    // Init Google Maps
    if (window.google) initGoogleMaps();
    else window.addEventListener('load', initGoogleMaps);

    const drayageForm = document.getElementById('drayageForm');
    if (drayageForm) {
        drayageForm.addEventListener('submit', function(e) {
            e.preventDefault();

            const overlay = document.getElementById('drayageModalOverlay');
            const containerIds = JSON.parse(overlay?.dataset.containerIds || '[]');
            if (containerIds.length === 0) {
                alert('No containers selected.');
                return;
            }

            // Get container number from first checked container
            let containerNumber = '';
            const checkedContainers = document.querySelectorAll('.container-checkbox:checked');
            if (checkedContainers.length > 0) {
                const row = checkedContainers[0].closest('tr');
                if (row && row.cells[1]) containerNumber = row.cells[1].textContent.trim();
            }
            if (!containerNumber) {
                alert('Could not determine container number.');
                return;
            }

            // Customs hold warning check
            if (!drayageHoldConfirmed) {
                const warnings = [];
                checkedContainers.forEach(cb => {
                    const hp = parseInt(cb.dataset.holdPallets || '0', 10);
                    const hm = parseInt(cb.dataset.holdModules || '0', 10);
                    if (hp > 0) warnings.push({ container: cb.dataset.containerNumber || 'Unknown', pallets: hp, modules: hm });
                });
                if (warnings.length > 0) {
                    showHoldWarning(warnings, function() {
                        drayageHoldConfirmed = true;
                        drayageForm.dispatchEvent(new Event('submit', { cancelable: true }));
                    });
                    return;
                }
            }
            drayageHoldConfirmed = false;

            // Populate hidden fields
            const drayageBol = containerNumber + '-DRAY';
            document.getElementById('dray_container_ids').value = JSON.stringify(containerIds);
            document.getElementById('dray_bol').value = drayageBol;
            document.getElementById('dray_container_number').value = containerNumber;

            // Fetch pallet IDs then submit
            fetch('get_container_pallets.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ delivery_ids: containerIds })
            })
            .then(r => r.json())
            .then(data => {
                if (!data.success) throw new Error(data.message || 'Failed to get pallet IDs');

                // Add pallet IDs as hidden inputs
                data.pallet_ids.forEach((pid, idx) => {
                    const input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = `selected_pallets[${idx}]`;
                    input.value = pid;
                    drayageForm.appendChild(input);
                });

                drayageForm.submit();
            })
            .catch(err => {
                console.error('Error:', err);
                alert('Error: ' + err.message);
            });
        });
    }

    function showHoldWarning(warnings, onConfirm) {
        document.getElementById('holdWarningOverlay')?.remove();

        const lines = warnings.map(w =>
            `<strong>${w.container}</strong>: ${w.pallets} pallet${w.pallets !== 1 ? 's' : ''} (${w.modules.toLocaleString()} modules) on Customs Hold`
        ).join('<br>');

        const el = document.createElement('div');
        el.id = 'holdWarningOverlay';
        el.className = 'drayage-hold-warning-overlay';
        el.innerHTML = `
            <div class="drayage-hold-warning-box">
                <div class="drayage-hold-warning-icon">&#9888;</div>
                <h3>Customs Hold Warning</h3>
                <p>The following container(s) have pallets on Customs Hold that <strong>will not be included</strong> in this shipment:</p>
                <div class="drayage-hold-warning-details">${lines}</div>
                <p style="margin-top:12px; color:#6b7280; font-size:0.9em;">These pallets will remain at the port.</p>
                <div class="drayage-hold-warning-actions">
                    <button type="button" class="action-button action-button-secondary" id="holdWarnCancel">Cancel</button>
                    <button type="button" class="action-button action-button-danger" id="holdWarnProceed">Proceed Without Held Pallets</button>
                </div>
            </div>`;
        document.body.appendChild(el);

        document.getElementById('holdWarnCancel').addEventListener('click', () => el.remove());
        document.getElementById('holdWarnProceed').addEventListener('click', () => { el.remove(); onConfirm(); });
        el.addEventListener('click', e => { if (e.target === el) el.remove(); });
    }
});

// ========== RECEIVE MODAL ==========
function openReceiveModal() {
    const checked = document.querySelectorAll('.inbound-checkbox:checked');
    if (checked.length === 0) return;
    document.getElementById('receiveModalOverlay').style.display = 'block';

    // Default date to today
    const today = new Date().toISOString().slice(0, 10);
    document.getElementById('receive_arrival_date').value = today;
}

function closeReceiveModal() {
    document.getElementById('receiveModalOverlay').style.display = 'none';
}

function submitReceive() {
    const checked = document.querySelectorAll('.inbound-checkbox:checked');
    if (checked.length === 0) {
        alert('Please select at least one container to receive.');
        return;
    }

    const form = document.createElement('form');
    form.method = 'POST';
    form.action = 'handle_pallet_arrival.php';

    // Warehouse ID
    const whInput = document.createElement('input');
    whInput.type = 'hidden'; whInput.name = 'warehouse_id'; whInput.value = warehouseId;
    form.appendChild(whInput);

    // Delivery IDs
    checked.forEach((cb, idx) => {
        const input = document.createElement('input');
        input.type = 'hidden'; input.name = `delivery_ids[${idx}]`; input.value = cb.value;
        form.appendChild(input);
    });

    // BOL override (house BOL)
    const bolInput = document.createElement('input');
    bolInput.type = 'hidden'; bolInput.name = 'bol_number_override';
    bolInput.value = document.getElementById('receive_house_bol')?.value || '';
    form.appendChild(bolInput);

    // Master BOL
    const masterBol = document.createElement('input');
    masterBol.type = 'hidden'; masterBol.name = 'master_bol';
    masterBol.value = document.getElementById('receive_master_bol')?.value || '';
    form.appendChild(masterBol);

    // Arrival date
    const dateInput = document.createElement('input');
    dateInput.type = 'hidden'; dateInput.name = 'actual_arrival_date';
    dateInput.value = document.getElementById('receive_arrival_date')?.value || '';
    form.appendChild(dateInput);

    // Action
    const actionInput = document.createElement('input');
    actionInput.type = 'hidden'; actionInput.name = 'action'; actionInput.value = 'receive_multiple_truckloads';
    form.appendChild(actionInput);

    document.body.appendChild(form);
    form.submit();
}

// ========== FEE MODAL ==========
function openFeeModal() {
    const overlay = document.getElementById('feeModalOverlay');
    if (overlay) overlay.style.display = 'block';
}
function closeFeeModal() {
    const overlay = document.getElementById('feeModalOverlay');
    if (overlay) overlay.style.display = 'none';
}

// ========== SEARCH / FILTER ==========
function filterContainerTable() {
    const query = (document.getElementById('containerSearch')?.value || '').toLowerCase();
    const rows = document.querySelectorAll('#containerTable tbody tr.container-row');
    rows.forEach(row => {
        const text = row.textContent.toLowerCase();
        const matches = !query || text.includes(query);
        row.style.display = matches ? '' : 'none';
        // Also hide the detail row if parent is hidden
        const did = row.dataset.deliveryId;
        const detailRow = document.getElementById('pallets-' + did);
        if (detailRow && !matches) detailRow.style.display = 'none';
    });
}

function filterInboundTable() {
    const query = (document.getElementById('inboundSearch')?.value || '').toLowerCase();
    const rows = document.querySelectorAll('#inboundTable tbody tr');
    rows.forEach(row => {
        row.style.display = (!query || row.textContent.toLowerCase().includes(query)) ? '' : 'none';
    });
}

// ========== MODAL CLOSE ON BACKDROP ==========
document.addEventListener('click', function(e) {
    if (e.target.id === 'drayageModalOverlay') closeDrayageModal();
    if (e.target.id === 'receiveModalOverlay') closeReceiveModal();
    if (e.target.id === 'feeModalOverlay') closeFeeModal();
});

// ========== URL PARAM HANDLING ==========
document.addEventListener('DOMContentLoaded', function() {
    const params = new URLSearchParams(window.location.search);
    const tab = params.get('tab');

    // Map old warehouse tabs to new port tabs
    const tabMap = {
        'storedInventory': 'atPort',
        'customsHold': 'atPort',
        'inboundTransit': 'inbound',
        'truckloadHistory': 'history',
        'atPort': 'atPort',
        'inbound': 'inbound',
        'history': 'history'
    };

    if (tab && tabMap[tab]) {
        showPortTab(tabMap[tab]);
    }

    // Pre-fill search if provided
    const search = (params.get('search') || '').trim();
    if (search) {
        const containerSearch = document.getElementById('containerSearch');
        if (containerSearch) {
            containerSearch.value = search;
            filterContainerTable();
        }
    }

    // Pre-fill from ?container= param
    const container = (params.get('container') || '').trim();
    if (container) {
        const inboundSearch = document.getElementById('inboundSearch');
        if (inboundSearch) {
            showPortTab('inbound');
            inboundSearch.value = container;
            filterInboundTable();
        }
    }

    // Session storage success message from drayage
    const moveMsg = sessionStorage.getItem('move_container_success');
    if (moveMsg) {
        const div = document.createElement('div');
        div.className = 'flash-success';
        div.innerHTML = '<strong>' + moveMsg + '</strong>';
        const main = document.querySelector('main');
        const existing = main?.querySelector('.flash-success, .flash-error');
        if (existing) existing.parentNode.insertBefore(div, existing.nextSibling);
        else if (main?.firstElementChild) main.insertBefore(div, main.firstElementChild.nextSibling);
        sessionStorage.removeItem('move_container_success');
    }
});

// ========== ESCAPE KEY ==========
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeDrayageModal();
        closeReceiveModal();
        closeFeeModal();
    }
});
</script>

</body>
</html>
