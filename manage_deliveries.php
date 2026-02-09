<?php
session_name("logistics_session");
session_start();

// Legacy route: manage_deliveries now forwards to the unified view_project tracker.
if (!isset($_SESSION['user_id'])) {
    header("Location: login");
    exit();
}

$legacy_project_id = isset($_GET['project_id']) ? (int)$_GET['project_id'] : 0;
$legacy_filter_project_id = $_GET['filter_project_id'] ?? null;
$legacy_delivery_id = isset($_GET['delivery_id']) ? (int)$_GET['delivery_id'] : 0;
$legacy_origin_batch_id = isset($_GET['origin_batch_id']) ? (int)$_GET['origin_batch_id'] : 0;

$resolved_project_id = 0;
if ($legacy_project_id > 0) {
    $resolved_project_id = $legacy_project_id;
} elseif (is_string($legacy_filter_project_id) && ctype_digit($legacy_filter_project_id) && (int)$legacy_filter_project_id > 0) {
    $resolved_project_id = (int)$legacy_filter_project_id;
}

if ($resolved_project_id <= 0 && $legacy_delivery_id > 0) {
    require_once '../config.php';
    $conn = getDBConnection();
    if ($conn && !$conn->connect_errno) {
        $stmt = $conn->prepare("SELECT project_id FROM deliveries WHERE id = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param("i", $legacy_delivery_id);
            $stmt->execute();
            $stmt->bind_result($ctx_project_id);
            if ($stmt->fetch() && !empty($ctx_project_id)) {
                $resolved_project_id = (int)$ctx_project_id;
            }
            $stmt->close();
        }
        $conn->close();
    }
}

$target_params = [];
if ($resolved_project_id > 0) {
    $target_params['project_id'] = $resolved_project_id;
} elseif ($legacy_origin_batch_id > 0) {
    $target_params['origin_batch_id'] = $legacy_origin_batch_id;
}

if ($legacy_delivery_id > 0) {
    $target_params['delivery_id'] = $legacy_delivery_id;
}

$legacy_status = $_GET['status'] ?? ($_GET['status_filter'] ?? '');
$legacy_wattage = $_GET['wattage'] ?? ($_GET['wattage_filter'] ?? '');
$legacy_search = $_GET['search'] ?? '';
$legacy_start_date = $_GET['start_date'] ?? '';
$legacy_end_date = $_GET['end_date'] ?? '';

if ($legacy_status !== '') {
    if ($legacy_status === 'Delivered') {
        $legacy_status = 'Delivered to Project';
    }
    $target_params['status'] = $legacy_status;
}
if ($legacy_wattage !== '') {
    $target_params['wattage'] = $legacy_wattage;
}
if ($legacy_search !== '') {
    $target_params['search'] = $legacy_search;
}
if ($legacy_start_date !== '') {
    $target_params['start_date'] = $legacy_start_date;
}
if ($legacy_end_date !== '') {
    $target_params['end_date'] = $legacy_end_date;
}

if (!empty($target_params)) {
    header('Location: view_project.php?' . http_build_query($target_params));
    exit();
}

header('Location: dashboard.php');
exit();
