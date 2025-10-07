<?php
session_name("logistics_session");
session_start();

header('Content-Type: application/json');

// Authentication
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit();
}

// Get JSON input
$input = file_get_contents('php://input');
$data = json_decode($input, true);

if (!$data) {
    echo json_encode(['success' => false, 'message' => 'Invalid JSON data']);
    exit();
}

// Extract parameters
$project_id = isset($data['project_id']) ? intval($data['project_id']) : 0;
$rate_value = isset($data['rate_value']) ? floatval($data['rate_value']) : 0;
$rate_unit = $data['rate_unit'] ?? 'mw';
$frequency = $data['frequency'] ?? 'per_week';
$start_date = $data['start_date'] ?? '';

if (!$project_id || !$rate_value || !$rate_unit || !$frequency || !$start_date) {
    echo json_encode(['success' => false, 'message' => 'Missing required parameters']);
    exit();
}

// Database connection
require_once '../config.php';
require_once 'anticipated_schedule_helpers.php';

$conn = getDBConnection();
if (!$conn) {
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit();
}

// Get project summary
$project_summary = getProjectSizeSummary($conn, $project_id);

// Convert rate to MW per week
$mw_per_week = convertDeliveryUnits(
    $rate_value,
    $rate_unit,
    'mw',
    $project_summary['avg_wattage'],
    $project_summary['modules_per_pallet'],
    $project_summary['pallets_per_truck']
);

if ($frequency === 'per_month') {
    $mw_per_week = $mw_per_week / 4.33; // Convert monthly to weekly
}

// Calculate duration
$total_mw = $project_summary['mw'];
$duration_weeks = ceil($total_mw / $mw_per_week);

// Calculate total deliveries in trucks
$total_trucks = convertDeliveryUnits(
    $total_mw,
    'mw',
    'trucks',
    $project_summary['avg_wattage'],
    $project_summary['modules_per_pallet'],
    $project_summary['pallets_per_truck']
);

// Calculate completion date
$start_dt = new DateTime($start_date);
$completion_dt = clone $start_dt;
$completion_dt->modify('+' . ($duration_weeks * 7) . ' days');

// Generate chart data
$dates = [];
$cumulative_mw = [];
$cumulative = 0;
$current_date = clone $start_dt;

for ($i = 0; $i < $duration_weeks; $i++) {
    // Get week ending Sunday
    $week_ending = getWeekEndingSunday($current_date->format('Y-m-d'));
    $dates[] = $week_ending;
    
    $cumulative += $mw_per_week;
    if ($cumulative > $total_mw) {
        $cumulative = $total_mw;
    }
    
    $cumulative_mw[] = round($cumulative, 3);
    
    $current_date->modify('+7 days');
}

$conn->close();

echo json_encode([
    'success' => true,
    'duration_weeks' => $duration_weeks,
    'completion_date' => $completion_dt->format('F j, Y'),
    'total_deliveries' => round($total_trucks),
    'mw_per_week' => round($mw_per_week, 3),
    'chart_data' => [
        'dates' => $dates,
        'cumulative_mw' => $cumulative_mw
    ]
]);


