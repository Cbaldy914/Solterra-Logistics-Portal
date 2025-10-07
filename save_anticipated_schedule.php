<?php
session_name("logistics_session");
session_start();

header('Content-Type: application/json');

// Authentication
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit();
}

$user_id = $_SESSION['user_id'];
$role = $_SESSION['role'] ?? 'user';

// Only admins and global_admins can create schedules
if ($role !== 'admin' && $role !== 'global_admin') {
    echo json_encode(['success' => false, 'message' => 'Permission denied']);
    exit();
}

// Get JSON input
$input = file_get_contents('php://input');
$data = json_decode($input, true);

if (!$data) {
    echo json_encode(['success' => false, 'message' => 'Invalid JSON data']);
    exit();
}

// Validate required fields
$project_id = isset($data['project_id']) ? intval($data['project_id']) : 0;
$schedule_type = $data['schedule_type'] ?? '';
$delivery_start_date = $data['delivery_start_date'] ?? '';
$notes = $data['notes'] ?? '';

if (!$project_id || !$schedule_type || !$delivery_start_date) {
    echo json_encode(['success' => false, 'message' => 'Missing required fields']);
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

// Verify user has access to this project
if ($role === 'admin') {
    $stmt = $conn->prepare("
        SELECT p.id 
        FROM projects p
        JOIN customer_account_users cau ON p.account_id = cau.account_id
        WHERE p.id = ? AND cau.user_id = ? AND cau.role = 'admin'
        LIMIT 1
    ");
    $stmt->bind_param("ii", $project_id, $user_id);
} else {
    $stmt = $conn->prepare("SELECT id FROM projects WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $project_id);
}

$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    $stmt->close();
    $conn->close();
    echo json_encode(['success' => false, 'message' => 'Project not found or access denied']);
    exit();
}
$stmt->close();

// Begin transaction
$conn->begin_transaction();

try {
    // Delete existing active schedule (overwrite behavior)
    $stmt = $conn->prepare("DELETE FROM anticipated_delivery_schedule WHERE project_id = ? AND is_active = 1");
    $stmt->bind_param("i", $project_id);
    $stmt->execute();
    $stmt->close();

    if ($schedule_type === 'quick') {
        // Quick schedule
        $quick_rate_value = isset($data['quick_rate_value']) ? floatval($data['quick_rate_value']) : 0;
        $quick_rate_unit = $data['quick_rate_unit'] ?? 'mw';
        $quick_rate_frequency = $data['quick_rate_frequency'] ?? 'per_week';

        if ($quick_rate_value <= 0) {
            throw new Exception('Invalid delivery rate');
        }

        $stmt = $conn->prepare("
            INSERT INTO anticipated_delivery_schedule 
            (project_id, schedule_type, delivery_start_date, quick_rate_value, 
             quick_rate_unit, quick_rate_frequency, is_active, created_by, notes)
            VALUES (?, ?, ?, ?, ?, ?, 1, ?, ?)
        ");
        $stmt->bind_param("issdssss", 
            $project_id, 
            $schedule_type, 
            $delivery_start_date, 
            $quick_rate_value, 
            $quick_rate_unit, 
            $quick_rate_frequency, 
            $user_id, 
            $notes
        );
        $stmt->execute();
        $stmt->close();

    } else if ($schedule_type === 'detailed') {
        // Detailed schedule
        $weeks = $data['weeks'] ?? [];

        if (empty($weeks)) {
            throw new Exception('No weeks data provided for detailed schedule');
        }

        // Insert main schedule record
        $stmt = $conn->prepare("
            INSERT INTO anticipated_delivery_schedule 
            (project_id, schedule_type, delivery_start_date, is_active, created_by, notes)
            VALUES (?, ?, ?, 1, ?, ?)
        ");
        $stmt->bind_param("issis", $project_id, $schedule_type, $delivery_start_date, $user_id, $notes);
        $stmt->execute();
        $schedule_id = $conn->insert_id;
        $stmt->close();

        // Insert week details
        $stmt = $conn->prepare("
            INSERT INTO anticipated_delivery_schedule_details 
            (schedule_id, delivery_week_ending, delivery_amount, delivery_unit)
            VALUES (?, ?, ?, ?)
        ");

        foreach ($weeks as $week) {
            $week_date = $week['date'] ?? '';
            $week_amount = isset($week['amount']) ? floatval($week['amount']) : 0;
            $week_unit = $week['unit'] ?? 'mw';

            if (!$week_date || $week_amount <= 0) {
                continue; // Skip invalid entries
            }

            // Convert to week ending Sunday
            $week_ending = getWeekEndingSunday($week_date);

            $stmt->bind_param("isds", $schedule_id, $week_ending, $week_amount, $week_unit);
            $stmt->execute();
        }
        $stmt->close();

    } else {
        throw new Exception('Invalid schedule type');
    }

    // Commit transaction
    $conn->commit();
    $conn->close();

    echo json_encode([
        'success' => true, 
        'message' => 'Schedule saved successfully'
    ]);

} catch (Exception $e) {
    // Rollback on error
    $conn->rollback();
    $conn->close();
    
    echo json_encode([
        'success' => false, 
        'message' => 'Error saving schedule: ' . $e->getMessage()
    ]);
}


