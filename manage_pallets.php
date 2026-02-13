<?php
session_name("logistics_session");
session_start();

// Legacy route: manage_pallets now forwards to the unified create_shipment tracker.
if (!isset($_SESSION['user_id'])) {
    header("Location: login");
    exit();
}

$role = $_SESSION['role'] ?? 'user';
if (!in_array($role, ['admin', 'global_admin', 'customer_admin', 'user'], true)) {
    header("Location: unauthorized.php");
    exit();
}
if (!empty($_SESSION['manage_pallets_message']) && empty($_SESSION['create_shipment_message'])) {
    $_SESSION['create_shipment_message'] = $_SESSION['manage_pallets_message'];
}
unset($_SESSION['manage_pallets_message'], $_SESSION['manage_pallets_warning']);


$legacy_project_id = isset($_GET['project_id']) ? (int)$_GET['project_id'] : 0;
$legacy_status = $_GET['status'] ?? ($_GET['status_filter'] ?? '');
$legacy_search = $_GET['search'] ?? '';
$legacy_from = $_GET['from'] ?? '';
$legacy_pallet_ids = $_GET['pallet_ids'] ?? '';

$target_params = [];
if ($legacy_project_id > 0) {
    $target_params['project_id'] = $legacy_project_id;
}
if ($legacy_status !== '') {
    $target_params['status_filter'] = $legacy_status;
}
if ($legacy_search !== '') {
    $target_params['search'] = $legacy_search;
}
if ($legacy_from !== '') {
    $target_params['from'] = $legacy_from;
}
if ($legacy_pallet_ids !== '') {
    $target_params['pallet_ids'] = $legacy_pallet_ids;
}

// Preserve any other query params not explicitly remapped above.
foreach ($_GET as $key => $value) {
    if (isset($target_params[$key])) {
        continue;
    }
    if (in_array($key, ['project_id', 'status', 'status_filter', 'search', 'from', 'pallet_ids'], true)) {
        continue;
    }
    $target_params[$key] = $value;
}

if (!empty($target_params)) {
    header('Location: create_shipment.php?' . http_build_query($target_params));
    exit();
}

header('Location: create_shipment.php');
exit();
