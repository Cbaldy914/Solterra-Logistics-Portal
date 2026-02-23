<?php
session_name("logistics_session");
session_start();

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'global_admin', 'customer_admin', 'user'], true)) {
    header("Location: unauthorized.php");
    exit();
}

require_once '../config.php';
$conn = getDBConnection();
if (!$conn) {
    die("Database connection failed.");
}
$google_maps_api_key = function_exists('getGoogleMapsApiKey') ? (string)getGoogleMapsApiKey() : '';

$role = $_SESSION['role'];
$user_id = (int)$_SESSION['user_id'];
$can_edit_eta = in_array($role, ['admin', 'global_admin', 'customer_admin'], true);
$map_picker_enabled = $can_edit_eta && $google_maps_api_key !== '';
$selected_project_id = isset($_GET['project_id'])
    ? (int)$_GET['project_id']
    : (isset($_POST['scope_project_id']) ? (int)$_POST['scope_project_id'] : 0);
$tracker_flash = $_SESSION['container_tracking_flash'] ?? null;
unset($_SESSION['container_tracking_flash']);

$account_id_for_admin = null;
$user_account_ids = [];
$available_projects = [];
$containers = [];
$errorMessage = '';
$positions_table_exists = false;
$waypoints_table_exists = false;
$positions_table_ready_message = '';
$project_waypoint_library = [];

function has_container_tracking_positions_table(mysqli $conn): bool {
    $result = $conn->query("SHOW TABLES LIKE 'container_tracking_positions'");
    if (!$result) {
        return false;
    }
    $exists = $result->num_rows > 0;
    $result->close();
    return $exists;
}

function ensure_container_tracking_positions_table(mysqli $conn): bool {
    $sql = "
        CREATE TABLE IF NOT EXISTS container_tracking_positions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            container_number VARCHAR(64) NOT NULL,
            project_id INT NOT NULL,
            latitude DECIMAL(10,7) DEFAULT NULL,
            longitude DECIMAL(10,7) DEFAULT NULL,
            updated_by INT DEFAULT NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_container_project (container_number, project_id),
            KEY idx_project_id (project_id),
            KEY idx_container_number (container_number)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ";
    return (bool)$conn->query($sql);
}

function has_container_tracking_waypoints_table(mysqli $conn): bool {
    $result = $conn->query("SHOW TABLES LIKE 'container_tracking_waypoints'");
    if (!$result) {
        return false;
    }
    $exists = $result->num_rows > 0;
    $result->close();
    return $exists;
}

function ensure_container_tracking_waypoints_table(mysqli $conn): bool {
    $sql = "
        CREATE TABLE IF NOT EXISTS container_tracking_waypoints (
            id INT AUTO_INCREMENT PRIMARY KEY,
            container_number VARCHAR(64) NOT NULL,
            project_id INT NOT NULL,
            latitude DECIMAL(10,7) NOT NULL,
            longitude DECIMAL(10,7) NOT NULL,
            recorded_by INT DEFAULT NULL,
            recorded_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_container_project_time (container_number, project_id, recorded_at),
            KEY idx_project_id (project_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ";
    return (bool)$conn->query($sql);
}

try {
    if (in_array($role, ['admin', 'customer_admin'], true)) {
        $stmtAdminAcc = $conn->prepare("SELECT account_id FROM customer_account_users WHERE user_id = ? AND role IN ('admin', 'customer_admin') LIMIT 1");
        if ($stmtAdminAcc) {
            $stmtAdminAcc->bind_param("i", $user_id);
            $stmtAdminAcc->execute();
            $stmtAdminAcc->bind_result($account_id_for_admin);
            $stmtAdminAcc->fetch();
            $stmtAdminAcc->close();
        }
        if (!$account_id_for_admin) {
            throw new Exception('Unable to determine account scope for this user.');
        }
    } elseif ($role === 'user') {
        $stmtUserAcc = $conn->prepare("SELECT account_id FROM customer_account_users WHERE user_id = ?");
        if ($stmtUserAcc) {
            $stmtUserAcc->bind_param("i", $user_id);
            $stmtUserAcc->execute();
            $resultUserAcc = $stmtUserAcc->get_result();
            while ($row = $resultUserAcc->fetch_assoc()) {
                $user_account_ids[] = (int)$row['account_id'];
            }
            $stmtUserAcc->close();
        }
        if (empty($user_account_ids)) {
            throw new Exception('No account access found for this user.');
        }
    }

    if ($role === 'global_admin') {
        $stmtProjects = $conn->prepare("
            SELECT p.id, p.project_name
            FROM projects p
            WHERE (p.status IS NULL OR p.status = 'active')
            ORDER BY p.project_name ASC
        ");
    } elseif (in_array($role, ['admin', 'customer_admin'], true)) {
        $stmtProjects = $conn->prepare("
            SELECT p.id, p.project_name
            FROM projects p
            WHERE p.account_id = ? AND (p.status IS NULL OR p.status = 'active')
            ORDER BY p.project_name ASC
        ");
        $stmtProjects->bind_param("i", $account_id_for_admin);
    } else {
        $placeholders = implode(',', array_fill(0, count($user_account_ids), '?'));
        $types = str_repeat('i', count($user_account_ids));
        $stmtProjects = $conn->prepare("
            SELECT p.id, p.project_name
            FROM projects p
            WHERE p.account_id IN ($placeholders) AND (p.status IS NULL OR p.status = 'active')
            ORDER BY p.project_name ASC
        ");
        $stmtProjects->bind_param($types, ...$user_account_ids);
    }

    if ($stmtProjects) {
        $stmtProjects->execute();
        $resultProjects = $stmtProjects->get_result();
        while ($project = $resultProjects->fetch_assoc()) {
            $available_projects[] = $project;
        }
        $stmtProjects->close();
    }

    if ($selected_project_id > 0) {
        $canAccessProject = false;
        foreach ($available_projects as $project) {
            if ((int)$project['id'] === $selected_project_id) {
                $canAccessProject = true;
                break;
            }
        }
        if (!$canAccessProject) {
            throw new Exception('Access denied for selected project.');
        }
    }

    $positions_table_exists = has_container_tracking_positions_table($conn);
    if (!$positions_table_exists && $can_edit_eta) {
        if (ensure_container_tracking_positions_table($conn)) {
            $positions_table_exists = true;
        } else {
            $positions_table_ready_message = 'Container coordinate table is unavailable. ETA edits still work.';
        }
    }
    $waypoints_table_exists = has_container_tracking_waypoints_table($conn);
    if (!$waypoints_table_exists && $can_edit_eta) {
        if (ensure_container_tracking_waypoints_table($conn)) {
            $waypoints_table_exists = true;
        } else {
            $positions_table_ready_message = trim($positions_table_ready_message . ' Waypoint history storage is unavailable.');
        }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $postError = null;
        $postSuccess = null;

        try {
            if (!$can_edit_eta) {
                throw new Exception('You do not have permission to update container data.');
            }

            $form_action = trim((string)($_POST['form_action'] ?? 'update_eta'));
            $container_number = trim((string)($_POST['container_number'] ?? ''));
            $container_project_id = (int)($_POST['project_id'] ?? 0);

            if ($container_number === '' || $container_project_id <= 0) {
                throw new Exception('Container identifier and project are required.');
            }

            $canAccessPostProject = false;
            foreach ($available_projects as $project) {
                if ((int)$project['id'] === $container_project_id) {
                    $canAccessPostProject = true;
                    break;
                }
            }
            if (!$canAccessPostProject) {
                throw new Exception('Access denied for the selected container project.');
            }

            $lookupSql = "
                SELECT d1.id
                FROM deliveries d1
                JOIN projects p1 ON d1.project_id = p1.id
                WHERE d1.container_number = ?
                  AND d1.project_id = ?
            ";
            $lookupParams = [$container_number, $container_project_id];
            $lookupTypes = 'si';

            if (in_array($role, ['admin', 'customer_admin'], true)) {
                $lookupSql .= " AND p1.account_id = ?";
                $lookupParams[] = $account_id_for_admin;
                $lookupTypes .= 'i';
            }

            $lookupSql .= " ORDER BY d1.id DESC LIMIT 1";
            $stmtLookup = $conn->prepare($lookupSql);
            if (!$stmtLookup) {
                throw new Exception('Failed to prepare ETA lookup query: ' . $conn->error);
            }
            $stmtLookup->bind_param($lookupTypes, ...$lookupParams);
            $stmtLookup->execute();
            $lookupResult = $stmtLookup->get_result();
            $latestRow = $lookupResult->fetch_assoc();
            $stmtLookup->close();

            if (!$latestRow || empty($latestRow['id'])) {
                throw new Exception('No editable delivery record found for that container.');
            }

            $latest_delivery_id = (int)$latestRow['id'];

            if ($form_action === 'update_position') {
                if (!$positions_table_exists) {
                    throw new Exception('Container position storage is not available yet.');
                }

                $lat_raw = trim((string)($_POST['vessel_latitude'] ?? ''));
                $lng_raw = trim((string)($_POST['vessel_longitude'] ?? ''));
                $latitude = null;
                $longitude = null;

                if ($lat_raw !== '' || $lng_raw !== '') {
                    if (!is_numeric($lat_raw) || !is_numeric($lng_raw)) {
                        throw new Exception('Latitude and longitude must both be numeric.');
                    }
                    $latitude = (float)$lat_raw;
                    $longitude = (float)$lng_raw;
                    if ($latitude < -90 || $latitude > 90) {
                        throw new Exception('Latitude must be between -90 and 90.');
                    }
                    if ($longitude < -180 || $longitude > 180) {
                        throw new Exception('Longitude must be between -180 and 180.');
                    }
                }

                $stmtUpsertPosition = $conn->prepare("
                    INSERT INTO container_tracking_positions
                        (container_number, project_id, latitude, longitude, updated_by)
                    VALUES (?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE
                        latitude = VALUES(latitude),
                        longitude = VALUES(longitude),
                        updated_by = VALUES(updated_by),
                        updated_at = CURRENT_TIMESTAMP
                ");
                if (!$stmtUpsertPosition) {
                    throw new Exception('Failed to prepare position update query: ' . $conn->error);
                }
                $stmtUpsertPosition->bind_param("siddi", $container_number, $container_project_id, $latitude, $longitude, $user_id);
                $stmtUpsertPosition->execute();
                $stmtUpsertPosition->close();

                if ($waypoints_table_exists && $latitude !== null && $longitude !== null) {
                    $lastLat = null;
                    $lastLng = null;
                    $stmtLastWaypoint = $conn->prepare("
                        SELECT latitude, longitude
                        FROM container_tracking_waypoints
                        WHERE container_number = ? AND project_id = ?
                        ORDER BY recorded_at DESC, id DESC
                        LIMIT 1
                    ");
                    if ($stmtLastWaypoint) {
                        $stmtLastWaypoint->bind_param("si", $container_number, $container_project_id);
                        $stmtLastWaypoint->execute();
                        $stmtLastWaypoint->bind_result($lastLatRaw, $lastLngRaw);
                        if ($stmtLastWaypoint->fetch()) {
                            $lastLat = (float)$lastLatRaw;
                            $lastLng = (float)$lastLngRaw;
                        }
                        $stmtLastWaypoint->close();
                    }

                    $isDuplicatePoint = $lastLat !== null && $lastLng !== null
                        && abs($lastLat - $latitude) < 0.000001
                        && abs($lastLng - $longitude) < 0.000001;

                    if (!$isDuplicatePoint) {
                        $stmtInsertWaypoint = $conn->prepare("
                            INSERT INTO container_tracking_waypoints
                                (container_number, project_id, latitude, longitude, recorded_by)
                            VALUES (?, ?, ?, ?, ?)
                        ");
                        if ($stmtInsertWaypoint) {
                            $stmtInsertWaypoint->bind_param("siddi", $container_number, $container_project_id, $latitude, $longitude, $user_id);
                            $stmtInsertWaypoint->execute();
                            $stmtInsertWaypoint->close();
                        }
                    }
                }

                $postSuccess = ($latitude === null || $longitude === null)
                    ? "Vessel coordinates cleared for container {$container_number}."
                    : "Vessel coordinates updated for container {$container_number}.";
            } else {
                $eta_input = trim((string)($_POST['eta_date'] ?? ''));
                if ($eta_input !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $eta_input)) {
                    throw new Exception('ETA must be a valid date.');
                }

                $stmtUpdateEta = $conn->prepare("
                    UPDATE deliveries
                    SET anticipated_delivery_date = CASE WHEN ? = '' THEN NULL ELSE ? END
                    WHERE id = ?
                ");
                if (!$stmtUpdateEta) {
                    throw new Exception('Failed to prepare ETA update query: ' . $conn->error);
                }
                $stmtUpdateEta->bind_param("ssi", $eta_input, $eta_input, $latest_delivery_id);
                $stmtUpdateEta->execute();
                $stmtUpdateEta->close();
                $postSuccess = "ETA updated for container {$container_number}.";
            }
        } catch (Exception $postException) {
            $postError = $postException->getMessage();
        }

        $_SESSION['container_tracking_flash'] = [
            'type' => $postError ? 'error' : 'success',
            'message' => $postError ?: $postSuccess
        ];
        $redirectSuffix = $selected_project_id > 0 ? '?project_id=' . (int)$selected_project_id : '';
        header('Location: container_tracking.php' . $redirectSuffix);
        exit();
    }

    $innerWhere = ["d1.container_number IS NOT NULL", "TRIM(d1.container_number) <> ''"];
    $innerParams = [];
    $innerTypes = '';

    if ($role === 'global_admin') {
        // No additional account filter.
    } elseif (in_array($role, ['admin', 'customer_admin'], true)) {
        $innerWhere[] = "p1.account_id = ?";
        $innerParams[] = $account_id_for_admin;
        $innerTypes .= 'i';
    } else {
        $placeholders = implode(',', array_fill(0, count($user_account_ids), '?'));
        $innerWhere[] = "p1.account_id IN ($placeholders)";
        foreach ($user_account_ids as $account_id) {
            $innerParams[] = $account_id;
            $innerTypes .= 'i';
        }
    }

    if ($selected_project_id > 0) {
        $innerWhere[] = "d1.project_id = ?";
        $innerParams[] = $selected_project_id;
        $innerTypes .= 'i';
    }

    $innerWhereSql = implode(' AND ', $innerWhere);
    $positionSelectSql = $positions_table_exists
        ? "ctp.latitude AS vessel_latitude, ctp.longitude AS vessel_longitude, ctp.updated_at AS vessel_position_updated_at,"
        : "NULL AS vessel_latitude, NULL AS vessel_longitude, NULL AS vessel_position_updated_at,";
    $positionJoinSql = $positions_table_exists
        ? "LEFT JOIN container_tracking_positions ctp
           ON ctp.container_number = d.container_number AND ctp.project_id = d.project_id"
        : "";
    $waypointSelectSql = $waypoints_table_exists
        ? "(
                SELECT COUNT(*)
                FROM container_tracking_waypoints ctw
                WHERE ctw.container_number = d.container_number
                  AND ctw.project_id = d.project_id
           ) AS waypoint_count,
           (
                SELECT MAX(ctw.recorded_at)
                FROM container_tracking_waypoints ctw
                WHERE ctw.container_number = d.container_number
                  AND ctw.project_id = d.project_id
           ) AS last_waypoint_at,"
        : "0 AS waypoint_count, NULL AS last_waypoint_at,";

    $containersSql = "
        SELECT
            d.container_number,
            d.status_of_delivery,
            d.anticipated_delivery_date AS eta_date,
            d.left_warehouse_date AS departed_date,
            d.created_at,
            d.project_id,
            p.project_name,
            COALESCE(w.name, 'Unknown Port') AS destination_port_name,
            $positionSelectSql
            $waypointSelectSql
            (
                SELECT COUNT(DISTINCT dp2.inventory_pallet_id)
                FROM deliveries d2
                LEFT JOIN delivery_pallets dp2 ON dp2.delivery_id = d2.id
                WHERE d2.container_number = d.container_number
                  AND d2.project_id = d.project_id
            ) AS pallet_count
        FROM deliveries d
        JOIN (
            SELECT d1.container_number, MAX(d1.id) AS latest_delivery_id
            FROM deliveries d1
            JOIN projects p1 ON d1.project_id = p1.id
            WHERE $innerWhereSql
            GROUP BY d1.container_number
        ) latest ON latest.latest_delivery_id = d.id
        JOIN projects p ON d.project_id = p.id
        LEFT JOIN warehouses w ON d.warehouse_id = w.id
        $positionJoinSql
        ORDER BY (d.anticipated_delivery_date IS NULL), d.anticipated_delivery_date ASC, d.container_number ASC
    ";

    $stmtContainers = $conn->prepare($containersSql);
    if ($stmtContainers) {
        if ($innerTypes !== '') {
            $stmtContainers->bind_param($innerTypes, ...$innerParams);
        }
        $stmtContainers->execute();
        $resultContainers = $stmtContainers->get_result();
        while ($row = $resultContainers->fetch_assoc()) {
            $days_to_eta = null;
            $eta_raw = trim((string)($row['eta_date'] ?? ''));
            if ($eta_raw !== '' && $eta_raw !== '0000-00-00') {
                try {
                    $today = new DateTime('today');
                    $etaObj = new DateTime($eta_raw);
                    $days_to_eta = (int)$today->diff($etaObj)->format('%r%a');
                } catch (Exception $ignored) {
                    $days_to_eta = null;
                }
            }
            $row['days_to_eta'] = $days_to_eta;
            $row['vessel_latitude'] = ($row['vessel_latitude'] !== null && $row['vessel_latitude'] !== '') ? (float)$row['vessel_latitude'] : null;
            $row['vessel_longitude'] = ($row['vessel_longitude'] !== null && $row['vessel_longitude'] !== '') ? (float)$row['vessel_longitude'] : null;
            $row['waypoint_count'] = (int)($row['waypoint_count'] ?? 0);
            $containers[] = $row;
        }
        $stmtContainers->close();
    } else {
        throw new Exception('Failed to prepare container tracking query: ' . $conn->error);
    }

    if ($waypoints_table_exists && $selected_project_id > 0) {
        $stmtProjectWaypoints = $conn->prepare("
            SELECT container_number, latitude, longitude, recorded_at
            FROM container_tracking_waypoints
            WHERE project_id = ?
            ORDER BY container_number ASC, recorded_at ASC, id ASC
        ");
        if ($stmtProjectWaypoints) {
            $stmtProjectWaypoints->bind_param("i", $selected_project_id);
            $stmtProjectWaypoints->execute();
            $resultProjectWaypoints = $stmtProjectWaypoints->get_result();
            while ($waypointRow = $resultProjectWaypoints->fetch_assoc()) {
                $containerNumberKey = trim((string)($waypointRow['container_number'] ?? ''));
                if ($containerNumberKey === '') {
                    continue;
                }
                if (!isset($project_waypoint_library[$containerNumberKey])) {
                    $project_waypoint_library[$containerNumberKey] = [];
                }
                $project_waypoint_library[$containerNumberKey][] = [
                    'latitude' => (float)$waypointRow['latitude'],
                    'longitude' => (float)$waypointRow['longitude'],
                    'recorded_at' => $waypointRow['recorded_at']
                ];
            }
            $stmtProjectWaypoints->close();
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
    <title>Container ETA Tracker</title>
    <link rel="stylesheet" href="portal.css">
    <link rel="icon" href="pictures/favicon.png" type="image/x-icon">
    <link href="https://fonts.googleapis.com/css?family=Poppins:300,400,500,600,700&display=swap" rel="stylesheet">
    <style>
        body { font-family: "Poppins", sans-serif; background: #f4f8fb; }
        main { padding: 16px 14px 38px; }
        .tracker-hero {
            background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%);
            border: 1px solid rgba(72, 140, 154, 0.08);
            border-radius: 24px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.06);
            padding: 28px 30px;
            margin-bottom: 20px;
            position: relative;
            overflow: hidden;
        }
        .tracker-hero::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, #488C9A 0%, #293E4C 100%);
        }
        .tracker-hero-head {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 14px;
            flex-wrap: wrap;
        }
        .tracker-hero h1 {
            margin: 0 0 8px 0;
            color: #17364d;
            font-size: 2rem;
            line-height: 1.1;
            background: linear-gradient(135deg, #293E4C 0%, #488C9A 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        .tracker-hero p { margin: 0; color: #59758a; max-width: 720px; }
        .hero-action-btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 18px;
            border-radius: 999px;
            font-weight: 600;
            font-size: 0.9rem;
            text-decoration: none;
            border: 1px solid #488C9A;
            color: #488C9A;
            background: #fff;
            transition: all 0.2s ease;
            white-space: nowrap;
        }
        .hero-action-btn:hover {
            background: #488C9A;
            color: #fff;
            transform: translateY(-1px);
        }
        .tracker-card {
            background: #fff;
            border: 1px solid rgba(148, 163, 184, 0.25);
            border-radius: 16px;
            box-shadow: 0 8px 20px rgba(15, 23, 42, 0.06);
            padding: 18px 20px;
            margin-bottom: 20px;
        }
        .tracker-card h2 { margin: 0 0 14px 0; color: #17364d; }
        .tracker-filters {
            display: flex;
            gap: 12px;
            align-items: end;
            flex-wrap: wrap;
        }
        .tracker-filters label {
            display: block;
            margin-bottom: 6px;
            font-size: 0.92em;
            color: #334155;
            font-weight: 600;
        }
        .tracker-filters select {
            padding: 10px 12px;
            min-width: 260px;
            border: 2px solid rgba(72, 140, 154, 0.15);
            border-radius: 10px;
            font-size: 0.95em;
        }
        .tracker-filter-btn {
            border: none;
            border-radius: 10px;
            padding: 10px 16px;
            background: linear-gradient(135deg, #488C9A 0%, #3A6E7F 100%);
            color: #fff;
            font-weight: 700;
            cursor: pointer;
        }
        .tracker-filter-btn:hover { filter: brightness(0.96); }
        .eta-positive { color: #488C9A; font-weight: 700; }
        .eta-late { color: #dc2626; font-weight: 700; }
        .eta-today { color: #3A6E7F; font-weight: 700; }
        .status-pill {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 999px;
            background: #dbeafe;
            color: #1e40af;
            font-size: 0.82em;
            font-weight: 600;
        }
        .flash-banner {
            border-radius: 12px;
            padding: 12px 14px;
            margin-bottom: 18px;
            border: 1px solid transparent;
            font-weight: 600;
        }
        .flash-banner.success {
            background: #ecfdf3;
            border-color: #86efac;
            color: #166534;
        }
        .flash-banner.error {
            background: #fef2f2;
            border-color: #fca5a5;
            color: #991b1b;
        }
        .eta-edit-form {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }
        .eta-edit-form input[type="date"] {
            padding: 6px 8px;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            font-size: 0.9em;
        }
        .eta-save-btn {
            border: none;
            border-radius: 8px;
            padding: 6px 10px;
            background: linear-gradient(135deg, #488C9A 0%, #3A6E7F 100%);
            color: #fff;
            font-size: 0.8em;
            font-weight: 700;
            cursor: pointer;
        }
        .eta-save-btn:hover {
            filter: brightness(0.96);
        }
        .use-map-btn {
            background: #0ea5e9;
            grid-column: 1 / -1;
            width: 100%;
            justify-self: stretch;
        }
        .use-map-btn:hover {
            background: #0284c7;
        }
        .use-map-btn[disabled] {
            background: #94a3b8;
            cursor: not-allowed;
        }
        .position-edit-form {
            display: grid;
            grid-template-columns: repeat(2, minmax(100px, 1fr)) auto;
            gap: 8px;
            align-items: center;
        }
        .position-edit-form input[type="number"] {
            width: 100%;
            padding: 6px 8px;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            font-size: 0.86em;
        }
        .position-display {
            font-size: 0.9em;
            color: #1f2937;
            font-weight: 600;
            white-space: nowrap;
        }
        .position-updated {
            margin-top: 4px;
            font-size: 0.75em;
            color: #64748b;
        }
        .waypoint-meta {
            margin-top: 4px;
            font-size: 0.75em;
            color: #475569;
            line-height: 1.35;
        }
        .tracker-table-wrap {
            overflow-x: auto;
            border: 1px solid #dbe5ee;
            border-radius: 12px;
        }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 11px 12px; border-bottom: 1px solid #e2e8f0; text-align: center; vertical-align: middle; }
        th {
            background: linear-gradient(135deg, #488C9A 0%, #3A6E7F 100%);
            color: #fff;
            font-size: 0.78em;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            font-weight: 700;
        }
        tbody tr:nth-child(even) { background: #f8fbfe; }
        tbody tr:hover { background: #f0f8fc; }
        .tracker-link {
            color: #488C9A;
            text-decoration: none;
            font-weight: 600;
        }
        .tracker-link:hover { text-decoration: underline; }
        .eta-edit-form {
            justify-content: center;
        }
        .position-edit-form {
            justify-content: center;
        }
        .position-picker-modal {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.55);
            z-index: 4000;
            align-items: center;
            justify-content: center;
            padding: 18px;
        }
        .position-picker-content {
            width: min(920px, 100%);
            max-height: min(92vh, 820px);
            background: #fff;
            border-radius: 16px;
            border: 1px solid rgba(148, 163, 184, 0.35);
            box-shadow: 0 26px 60px rgba(2, 8, 23, 0.35);
            overflow: hidden;
            display: flex;
            flex-direction: column;
        }
        .position-picker-header {
            padding: 16px 18px;
            background: linear-gradient(135deg, #0ea5e9 0%, #0284c7 100%);
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .position-picker-header h3 {
            margin: 0;
            font-size: 1.1rem;
        }
        .position-picker-close {
            border: none;
            background: rgba(255,255,255,0.2);
            color: #fff;
            font-size: 20px;
            width: 30px;
            height: 30px;
            border-radius: 8px;
            cursor: pointer;
        }
        .position-picker-map {
            height: min(62vh, 520px);
            width: 100%;
        }
        .position-picker-footer {
            padding: 12px 16px;
            border-top: 1px solid #e2e8f0;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
            flex-wrap: wrap;
        }
        .position-picker-coords {
            font-size: 0.9rem;
            color: #334155;
            font-weight: 600;
        }
        .position-picker-route-info {
            margin-top: 4px;
            font-size: 0.8rem;
            color: #0c4a6e;
            line-height: 1.35;
        }
        .position-picker-route-info.dim {
            color: #64748b;
        }
        .position-picker-route-lock {
            margin-top: 6px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 0.78rem;
            color: #334155;
            font-weight: 600;
        }
        .position-picker-route-lock input[type="checkbox"] {
            width: 14px;
            height: 14px;
            cursor: pointer;
        }
        .position-picker-route-lock.disabled {
            color: #94a3b8;
        }
        .position-picker-actions {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        @media (max-width: 900px) {
            .position-edit-form {
                grid-template-columns: 1fr;
            }
            .tracker-filters select {
                min-width: 220px;
            }
        }
    </style>
</head>
<body>
<?php include 'header.php'; ?>
<main>
    <?php
        require_once 'components/breadcrumbs.php';
        echo slp_render_breadcrumbs(['current_label' => 'Container ETA Tracker']);
    ?>

    <div class="tracker-hero">
        <div class="tracker-hero-head">
            <div>
                <h1>Container ETA Tracker</h1>
                <p>
                    <?php echo $can_edit_eta
                        ? 'Container status is system-managed; ETA and vessel waypoints can be updated by your role.'
                        : 'Read-only view of container numbers, ETA timing, and current automated shipment status.'; ?>
                </p>
            </div>
            <?php if ($selected_project_id > 0): ?>
                <a class="hero-action-btn" href="module_movements.php?project_id=<?php echo (int)$selected_project_id; ?>">
                    <span>&#128506;</span> View Movement Map
                </a>
            <?php endif; ?>
        </div>
    </div>

    <?php if (is_array($tracker_flash) && !empty($tracker_flash['message'])): ?>
        <div class="flash-banner <?php echo ($tracker_flash['type'] ?? '') === 'error' ? 'error' : 'success'; ?>">
            <?php echo htmlspecialchars((string)$tracker_flash['message']); ?>
        </div>
    <?php endif; ?>

    <?php if ($positions_table_ready_message !== ''): ?>
        <div class="flash-banner error"><?php echo htmlspecialchars($positions_table_ready_message); ?></div>
    <?php endif; ?>

    <div class="tracker-card">
        <form method="GET" class="tracker-filters">
            <div>
                <label for="project_id">Project</label>
                <select name="project_id" id="project_id">
                    <option value="0">All Accessible Projects</option>
                    <?php foreach ($available_projects as $project): ?>
                        <option value="<?php echo (int)$project['id']; ?>" <?php echo ((int)$project['id'] === $selected_project_id) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($project['project_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" class="tracker-filter-btn">Apply</button>
        </form>
    </div>

    <?php if ($errorMessage): ?>
        <div class="error-message"><strong>Error:</strong> <?php echo htmlspecialchars($errorMessage); ?></div>
    <?php else: ?>
        <div class="tracker-card">
            <h2 style="margin-top:0;">Containers</h2>
            <?php if (empty($containers)): ?>
                <p style="margin:0; color:#64748b;">No container records found for the selected scope.</p>
            <?php else: ?>
                <div class="tracker-table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Container</th>
                                <th>Project</th>
                                <th>Status</th>
                                <th>ETA</th>
                                <th>Vessel Position</th>
                                <th>Waypoints</th>
                                <th>Days To ETA</th>
                                <th>Destination Port</th>
                                <th>Pallets</th>
                                <th>Details</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($containers as $container): ?>
                                <?php
                                    $days = $container['days_to_eta'];
                                    $daysClass = '';
                                    $daysText = 'N/A';
                                    if ($days !== null) {
                                        if ($days > 0) {
                                            $daysClass = 'eta-positive';
                                            $daysText = $days . ' day' . ($days === 1 ? '' : 's');
                                        } elseif ($days === 0) {
                                            $daysClass = 'eta-today';
                                            $daysText = 'Today';
                                        } else {
                                            $daysClass = 'eta-late';
                                            $daysText = abs($days) . ' day' . (abs($days) === 1 ? '' : 's') . ' late';
                                        }
                                    }
                                ?>
                                <tr>
                                    <?php
                                        $containerProjectId = (int)($container['project_id'] ?? 0);
                                        $detailsHref = $containerProjectId > 0
                                            ? ('view_project.php?project_id=' . $containerProjectId . '&search=' . urlencode((string)$container['container_number']))
                                            : ('manage_deliveries.php?search=' . urlencode((string)$container['container_number']));
                                    ?>
                                    <td><strong><?php echo htmlspecialchars($container['container_number']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($container['project_name'] ?? 'N/A'); ?></td>
                                    <td><span class="status-pill"><?php echo htmlspecialchars($container['status_of_delivery'] ?? 'N/A'); ?></span></td>
                                    <td>
                                        <?php
                                            $etaValue = trim((string)($container['eta_date'] ?? ''));
                                            $etaFieldValue = ($etaValue !== '' && $etaValue !== '0000-00-00') ? $etaValue : '';
                                        ?>
                                        <?php if ($can_edit_eta): ?>
                                            <form method="POST" class="eta-edit-form">
                                                <input type="hidden" name="form_action" value="update_eta">
                                                <input type="hidden" name="container_number" value="<?php echo htmlspecialchars((string)$container['container_number']); ?>">
                                                <input type="hidden" name="project_id" value="<?php echo (int)($container['project_id'] ?? 0); ?>">
                                                <input type="hidden" name="scope_project_id" value="<?php echo (int)$selected_project_id; ?>">
                                                <input type="date" name="eta_date" value="<?php echo htmlspecialchars($etaFieldValue); ?>" required>
                                                <button type="submit" class="eta-save-btn">Save</button>
                                            </form>
                                        <?php else: ?>
                                            <?php echo htmlspecialchars($etaFieldValue !== '' ? $etaFieldValue : 'N/A'); ?>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php
                                            $vesselLat = $container['vessel_latitude'];
                                            $vesselLng = $container['vessel_longitude'];
                                            $hasPosition = ($vesselLat !== null && $vesselLng !== null);
                                            $positionUpdatedAt = trim((string)($container['vessel_position_updated_at'] ?? ''));
                                            $waypointCount = (int)($container['waypoint_count'] ?? 0);
                                            $lastWaypointAt = trim((string)($container['last_waypoint_at'] ?? ''));
                                        ?>
                                        <?php if ($can_edit_eta && $positions_table_exists): ?>
                                            <form method="POST" class="position-edit-form">
                                                <input type="hidden" name="form_action" value="update_position">
                                                <input type="hidden" name="container_number" value="<?php echo htmlspecialchars((string)$container['container_number']); ?>">
                                                <input type="hidden" name="project_id" value="<?php echo (int)($container['project_id'] ?? 0); ?>">
                                                <input type="hidden" name="scope_project_id" value="<?php echo (int)$selected_project_id; ?>">
                                                <input type="number" step="0.000001" min="-90" max="90" name="vessel_latitude" placeholder="Lat" value="<?php echo $hasPosition ? htmlspecialchars(number_format((float)$vesselLat, 6, '.', '')) : ''; ?>">
                                                <input type="number" step="0.000001" min="-180" max="180" name="vessel_longitude" placeholder="Lng" value="<?php echo $hasPosition ? htmlspecialchars(number_format((float)$vesselLng, 6, '.', '')) : ''; ?>">
                                                <button type="submit" class="eta-save-btn">Save</button>
                                                <button type="button" class="eta-save-btn use-map-btn" <?php echo $map_picker_enabled ? '' : 'disabled title="Map picker unavailable"'; ?>>Use Map</button>
                                            </form>
                                            <?php if ($hasPosition): ?>
                                                <div class="position-updated">
                                                    Last updated: <?php echo htmlspecialchars($positionUpdatedAt !== '' ? $positionUpdatedAt : 'N/A'); ?>
                                                </div>
                                            <?php endif; ?>
                                            <div class="waypoint-meta">Each position save appends waypoint history for map transit lines.</div>
                                        <?php else: ?>
                                            <div class="position-display">
                                                <?php echo $hasPosition
                                                    ? htmlspecialchars(number_format((float)$vesselLat, 4) . ', ' . number_format((float)$vesselLng, 4))
                                                    : 'Projected'; ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="position-display"><?php echo number_format($waypointCount); ?> point<?php echo $waypointCount === 1 ? '' : 's'; ?></div>
                                        <div class="waypoint-meta">Last: <?php echo htmlspecialchars($lastWaypointAt !== '' ? $lastWaypointAt : 'N/A'); ?></div>
                                    </td>
                                    <td class="<?php echo $daysClass; ?>"><?php echo htmlspecialchars($daysText); ?></td>
                                    <td><?php echo htmlspecialchars($container['destination_port_name'] ?? 'N/A'); ?></td>
                                    <td><?php echo number_format((int)($container['pallet_count'] ?? 0)); ?></td>
                                    <td>
                                        <a href="<?php echo htmlspecialchars($detailsHref); ?>" class="tracker-link">View</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if ($can_edit_eta && $positions_table_exists): ?>
        <div id="positionPickerModal" class="position-picker-modal" aria-hidden="true">
            <div class="position-picker-content">
                <div class="position-picker-header">
                    <h3>Pick Vessel Position</h3>
                    <button type="button" class="position-picker-close" id="closePositionPickerBtn" aria-label="Close">&times;</button>
                </div>
                <div id="positionPickerMap" class="position-picker-map"></div>
                <div class="position-picker-footer">
                    <div>
                        <div class="position-picker-coords" id="positionPickerCoords">Click the map to select latitude/longitude.</div>
                        <div class="position-picker-route-info dim" id="positionPickerRouteInfo">Route assist: unavailable until project waypoint history exists.</div>
                        <label class="position-picker-route-lock disabled" id="positionPickerRouteLockWrap">
                            <input type="checkbox" id="positionPickerRouteLockToggle" checked disabled>
                            Route Lock (snap to route)
                        </label>
                    </div>
                    <div class="position-picker-actions">
                        <button type="button" class="eta-save-btn" id="cancelPositionPickerBtn">Cancel</button>
                        <button type="button" class="eta-save-btn" id="applyPositionPickerBtn" disabled>Use This Point</button>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>
</main>
<?php if ($map_picker_enabled): ?>
<script src="https://maps.googleapis.com/maps/api/js?key=<?php echo htmlspecialchars($google_maps_api_key); ?>&libraries=places"></script>
<?php endif; ?>
<?php if ($can_edit_eta && $positions_table_exists): ?>
<script>
const projectWaypointLibrary = <?php echo json_encode($project_waypoint_library ?? []); ?>;
let positionPickerMap = null;
let positionPickerMarker = null;
let positionPickerSelected = null;
let activeLatInput = null;
let activeLngInput = null;
let routeReferencePolyline = null;
let routeReferenceMarkers = [];
let routeWaypointMarkers = [];
let routeVesselMarker = null;
let routeReferencePath = [];
let routeLockEnabled = false;
let routeLockAvailable = false;

function buildPickerBoatIcon(iconSize = 34) {
    return {
        url: 'data:image/svg+xml;charset=UTF-8,' + encodeURIComponent(
            '<svg width="' + iconSize + '" height="' + iconSize + '" viewBox="0 0 44 44" xmlns="http://www.w3.org/2000/svg">' +
            '<defs><filter id="shadow" x="-50%" y="-50%" width="200%" height="200%"><feDropShadow dx="0" dy="2" stdDeviation="3" flood-opacity="0.3"/></filter></defs>' +
            '<circle cx="22" cy="22" r="19" fill="url(#grad)" stroke="#FFFFFF" stroke-width="3" filter="url(#shadow)"/>' +
            '<defs><linearGradient id="grad" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#1d4ed8"/><stop offset="100%" stop-color="#0ea5e9"/></linearGradient></defs>' +
            '<text x="22" y="28" text-anchor="middle" fill="#FFFFFF" font-size="16" font-family="Arial">&#x26F4;</text>' +
            '</svg>'
        ),
        scaledSize: new google.maps.Size(iconSize, iconSize),
        anchor: new google.maps.Point(iconSize / 2, iconSize / 2)
    };
}

function normalizeContainerNumber(value) {
    return String(value || '').trim();
}

function getWaypointsForContainer(containerNumber) {
    const key = normalizeContainerNumber(containerNumber);
    if (!key || !projectWaypointLibrary || typeof projectWaypointLibrary !== 'object') {
        return [];
    }
    const points = Array.isArray(projectWaypointLibrary[key]) ? projectWaypointLibrary[key] : [];
    return points
        .map((p) => {
            const lat = Number(p.latitude);
            const lng = Number(p.longitude);
            if (!Number.isFinite(lat) || !Number.isFinite(lng)) return null;
            if (lat < -90 || lat > 90 || lng < -180 || lng > 180) return null;
            return {
                lat,
                lng,
                recordedAt: String(p.recorded_at || '')
            };
        })
        .filter(Boolean);
}

function getFallbackProjectRoutePoints() {
    if (!projectWaypointLibrary || typeof projectWaypointLibrary !== 'object') {
        return [];
    }
    let best = [];
    Object.keys(projectWaypointLibrary).forEach((containerKey) => {
        const points = getWaypointsForContainer(containerKey);
        if (points.length > best.length) {
            best = points;
        }
    });
    return best;
}

function clearRouteReferenceVisuals() {
    if (routeReferencePolyline) {
        routeReferencePolyline.setMap(null);
        routeReferencePolyline = null;
    }
    routeReferenceMarkers.forEach((marker) => marker.setMap(null));
    routeReferenceMarkers = [];
    routeWaypointMarkers.forEach((marker) => marker.setMap(null));
    routeWaypointMarkers = [];
    if (routeVesselMarker) {
        routeVesselMarker.setMap(null);
        routeVesselMarker = null;
    }
    routeReferencePath = [];
    routeLockEnabled = false;
    routeLockAvailable = false;
    setRouteLockUI(false, false);
}

function setRouteInfo(text, isDim = false) {
    const infoEl = document.getElementById('positionPickerRouteInfo');
    if (!infoEl) return;
    infoEl.textContent = text;
    infoEl.classList.toggle('dim', Boolean(isDim));
}

function setRouteLockUI(available, enabled) {
    const toggle = document.getElementById('positionPickerRouteLockToggle');
    const wrap = document.getElementById('positionPickerRouteLockWrap');
    if (!toggle || !wrap) return;
    toggle.disabled = !available;
    toggle.checked = Boolean(available && enabled);
    wrap.classList.toggle('disabled', !available);
}

function latLngToObject(latLng) {
    return { lat: Number(latLng.lat()), lng: Number(latLng.lng()) };
}

function snapLatLngToRoute(latLng) {
    if (!latLng || routeReferencePath.length < 2) {
        return latLng;
    }
    const point = latLngToObject(latLng);
    let bestPoint = null;
    let minDistanceSq = Infinity;

    for (let i = 0; i < routeReferencePath.length - 1; i++) {
        const a = routeReferencePath[i];
        const b = routeReferencePath[i + 1];
        const dx = b.lng - a.lng;
        const dy = b.lat - a.lat;
        const denominator = (dx * dx) + (dy * dy);

        let t = 0;
        if (denominator > 0) {
            t = ((point.lng - a.lng) * dx + (point.lat - a.lat) * dy) / denominator;
        }
        t = Math.max(0, Math.min(1, t));

        const projected = {
            lat: a.lat + (dy * t),
            lng: a.lng + (dx * t)
        };
        const distanceSq = Math.pow(projected.lat - point.lat, 2) + Math.pow(projected.lng - point.lng, 2);
        if (distanceSq < minDistanceSq) {
            minDistanceSq = distanceSq;
            bestPoint = projected;
        }
    }

    if (!bestPoint) {
        return latLng;
    }
    return new google.maps.LatLng(bestPoint.lat, bestPoint.lng);
}

function setRouteReferenceForContainer(containerNumber, existingLatLng = null) {
    clearRouteReferenceVisuals();

    const ownRoute = getWaypointsForContainer(containerNumber);
    const fallbackRoute = ownRoute.length >= 2 ? ownRoute : getFallbackProjectRoutePoints();
    if (fallbackRoute.length < 2) {
        setRouteInfo('Route assist: no existing multi-point route yet. Add at least 2 waypoints first.', true);
        return;
    }

    routeReferencePath = fallbackRoute.map((p) => ({ lat: p.lat, lng: p.lng }));
    routeLockAvailable = true;
    routeLockEnabled = true;
    setRouteLockUI(true, true);

    routeReferencePolyline = new google.maps.Polyline({
        path: routeReferencePath,
        geodesic: true,
        strokeColor: '#0369a1',
        strokeOpacity: 0.88,
        strokeWeight: 4,
        map: positionPickerMap,
        zIndex: 12
    });

    const startMarker = new google.maps.Marker({
        position: routeReferencePath[0],
        map: positionPickerMap,
        title: 'Route Start',
        icon: {
            path: google.maps.SymbolPath.CIRCLE,
            scale: 6,
            fillColor: '#10b981',
            fillOpacity: 1,
            strokeColor: '#ffffff',
            strokeOpacity: 0.95,
            strokeWeight: 2
        },
        zIndex: 13
    });
    const endMarker = new google.maps.Marker({
        position: routeReferencePath[routeReferencePath.length - 1],
        map: positionPickerMap,
        title: 'Route End',
        icon: {
            path: google.maps.SymbolPath.CIRCLE,
            scale: 6,
            fillColor: '#ef4444',
            fillOpacity: 1,
            strokeColor: '#ffffff',
            strokeOpacity: 0.95,
            strokeWeight: 2
        },
        zIndex: 13
    });
    routeReferenceMarkers.push(startMarker, endMarker);

    const bounds = new google.maps.LatLngBounds();
    routeReferencePath.forEach((point) => bounds.extend(point));

    fallbackRoute.forEach((point, index) => {
        const dot = new google.maps.Marker({
            position: { lat: point.lat, lng: point.lng },
            map: positionPickerMap,
            title: `Route waypoint ${index + 1}`,
            icon: {
                path: google.maps.SymbolPath.CIRCLE,
                scale: 4,
                fillColor: '#0284c7',
                fillOpacity: 0.95,
                strokeColor: '#ffffff',
                strokeOpacity: 0.95,
                strokeWeight: 1.5
            },
            zIndex: 11
        });
        routeWaypointMarkers.push(dot);
    });

    const containerLastWaypoint = ownRoute.length > 0 ? ownRoute[ownRoute.length - 1] : null;
    const vesselPosition = containerLastWaypoint
        ? new google.maps.LatLng(containerLastWaypoint.lat, containerLastWaypoint.lng)
        : (existingLatLng || null);
    if (vesselPosition) {
        routeVesselMarker = new google.maps.Marker({
            position: vesselPosition,
            map: positionPickerMap,
            title: 'Container latest update',
            icon: buildPickerBoatIcon(30),
            zIndex: 14
        });
        bounds.extend(vesselPosition);
    }

    if (existingLatLng) {
        bounds.extend(existingLatLng);
    }
    if (!bounds.isEmpty()) {
        positionPickerMap.fitBounds(bounds);
        google.maps.event.addListenerOnce(positionPickerMap, 'bounds_changed', () => {
            if (positionPickerMap.getZoom() > 6) {
                positionPickerMap.setZoom(6);
            }
        });
    }

    const usingOwnRoute = ownRoute.length >= 2;
    setRouteInfo(usingOwnRoute
        ? 'Route assist: using this container\'s existing route. Map clicks are locked to that line.'
        : 'Route assist: using a project reference route. Map clicks are locked to that line.');
}

function setPickerSelected(latLng) {
    if (!latLng) return;
    const effectiveLatLng = routeLockEnabled ? snapLatLngToRoute(latLng) : latLng;
    positionPickerSelected = effectiveLatLng;
    if (!positionPickerMarker) {
        positionPickerMarker = new google.maps.Marker({
            position: effectiveLatLng,
            map: positionPickerMap,
            draggable: true,
            title: 'Selected vessel position',
            icon: buildPickerBoatIcon(34)
        });
        positionPickerMarker.addListener('dragend', (event) => {
            setPickerSelected(event.latLng);
        });
    } else {
        positionPickerMarker.setPosition(effectiveLatLng);
    }
    const coordsEl = document.getElementById('positionPickerCoords');
    if (coordsEl) {
        coordsEl.textContent = `Selected: ${effectiveLatLng.lat().toFixed(6)}, ${effectiveLatLng.lng().toFixed(6)}`;
    }
    const applyBtn = document.getElementById('applyPositionPickerBtn');
    if (applyBtn) applyBtn.disabled = false;
}

function openPositionPicker(formEl) {
    if (!window.google || !window.google.maps) {
        alert('Map picker is not available right now.');
        return;
    }

    activeLatInput = formEl.querySelector('input[name="vessel_latitude"]');
    activeLngInput = formEl.querySelector('input[name="vessel_longitude"]');
    if (!activeLatInput || !activeLngInput) {
        return;
    }

    const modal = document.getElementById('positionPickerModal');
    if (!modal) return;
    modal.style.display = 'flex';
    modal.setAttribute('aria-hidden', 'false');

    const latVal = parseFloat(activeLatInput.value);
    const lngVal = parseFloat(activeLngInput.value);
    const hasExisting = Number.isFinite(latVal) && Number.isFinite(lngVal);
    const initialCenter = hasExisting ? { lat: latVal, lng: lngVal } : { lat: 20, lng: 0 };
    const initialZoom = hasExisting ? 6 : 2;
    const existingLatLng = hasExisting ? new google.maps.LatLng(latVal, lngVal) : null;
    const containerNumberInput = formEl.querySelector('input[name="container_number"]');
    const containerNumber = normalizeContainerNumber(containerNumberInput ? containerNumberInput.value : '');

    if (!positionPickerMap) {
        positionPickerMap = new google.maps.Map(document.getElementById('positionPickerMap'), {
            center: initialCenter,
            zoom: initialZoom,
            mapTypeId: 'roadmap',
            streetViewControl: false,
            fullscreenControl: true
        });
        positionPickerMap.addListener('click', (event) => {
            setPickerSelected(event.latLng);
        });
    } else {
        positionPickerMap.setCenter(initialCenter);
        positionPickerMap.setZoom(initialZoom);
    }

    setRouteReferenceForContainer(containerNumber, existingLatLng);

    const applyBtn = document.getElementById('applyPositionPickerBtn');
    if (applyBtn) applyBtn.disabled = true;
    positionPickerSelected = null;

    if (hasExisting) {
        setPickerSelected(existingLatLng);
    } else if (positionPickerMarker) {
        positionPickerMarker.setMap(null);
        positionPickerMarker = null;
        const coordsEl = document.getElementById('positionPickerCoords');
        if (coordsEl) coordsEl.textContent = 'Click the map to select latitude/longitude.';
    }
}

function closePositionPicker() {
    const modal = document.getElementById('positionPickerModal');
    if (!modal) return;
    modal.style.display = 'none';
    modal.setAttribute('aria-hidden', 'true');
    clearRouteReferenceVisuals();
}

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('.use-map-btn:not([disabled])').forEach((btn) => {
        btn.addEventListener('click', () => {
            const formEl = btn.closest('form');
            if (formEl) openPositionPicker(formEl);
        });
    });

    document.getElementById('closePositionPickerBtn')?.addEventListener('click', closePositionPicker);
    document.getElementById('cancelPositionPickerBtn')?.addEventListener('click', closePositionPicker);
    document.getElementById('positionPickerModal')?.addEventListener('click', (event) => {
        if (event.target.id === 'positionPickerModal') {
            closePositionPicker();
        }
    });
    document.getElementById('positionPickerRouteLockToggle')?.addEventListener('change', (event) => {
        if (!routeLockAvailable) {
            routeLockEnabled = false;
            return;
        }
        routeLockEnabled = Boolean(event.target.checked);
    });
    document.getElementById('applyPositionPickerBtn')?.addEventListener('click', () => {
        if (!positionPickerSelected || !activeLatInput || !activeLngInput) {
            return;
        }
        activeLatInput.value = positionPickerSelected.lat().toFixed(6);
        activeLngInput.value = positionPickerSelected.lng().toFixed(6);
        closePositionPicker();
    });
});
</script>
<?php endif; ?>
</body>
</html>
