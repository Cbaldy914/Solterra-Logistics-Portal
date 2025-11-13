<?php
// Start session
session_name("logistics_session");
session_start();

// Check if user is logged in and is global admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'global_admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

require_once '../config.php';

$conn = getDBConnection();
if (!$conn) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit();
}

$action = $_GET['action'] ?? 'list';

// Handle different actions
switch ($action) {
    case 'stats':
        getStatistics($conn);
        break;
    case 'list':
        getAttemptsList($conn);
        break;
    case 'export':
        exportToCSV($conn);
        break;
    default:
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid action']);
        break;
}

$conn->close();

function getStatistics($conn) {
    $today_start = date('Y-m-d 00:00:00');
    
    // Total attempts today
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM login_attempts WHERE attempt_time >= ?");
    $stmt->bind_param("s", $today_start);
    $stmt->execute();
    $result = $stmt->get_result();
    $total_today = $result->fetch_assoc()['count'];
    $stmt->close();
    
    // Successful logins today
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM login_attempts WHERE attempt_time >= ? AND success = 1");
    $stmt->bind_param("s", $today_start);
    $stmt->execute();
    $result = $stmt->get_result();
    $success_today = $result->fetch_assoc()['count'];
    $stmt->close();
    
    // Failed attempts today
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM login_attempts WHERE attempt_time >= ? AND success = 0");
    $stmt->bind_param("s", $today_start);
    $stmt->execute();
    $result = $stmt->get_result();
    $failed_today = $result->fetch_assoc()['count'];
    $stmt->close();
    
    // Unique users who attempted today
    $stmt = $conn->prepare("SELECT COUNT(DISTINCT username) as count FROM login_attempts WHERE attempt_time >= ?");
    $stmt->bind_param("s", $today_start);
    $stmt->execute();
    $result = $stmt->get_result();
    $unique_users = $result->fetch_assoc()['count'];
    $stmt->close();
    
    echo json_encode([
        'success' => true,
        'stats' => [
            'total_today' => $total_today,
            'success_today' => $success_today,
            'failed_today' => $failed_today,
            'unique_users' => $unique_users
        ]
    ]);
}

function getAttemptsList($conn) {
    $page = intval($_GET['page'] ?? 1);
    $limit = intval($_GET['limit'] ?? 50);
    $offset = ($page - 1) * $limit;
    
    // Build WHERE clause based on filters
    $where_clauses = [];
    $params = [];
    $param_types = '';
    
    if (!empty($_GET['username'])) {
        $where_clauses[] = "username LIKE ?";
        $params[] = '%' . $_GET['username'] . '%';
        $param_types .= 's';
    }
    
    if (!empty($_GET['status'])) {
        if ($_GET['status'] === 'success') {
            $where_clauses[] = "success = 1";
        } elseif ($_GET['status'] === 'failed') {
            $where_clauses[] = "success = 0";
        }
    }
    
    if (!empty($_GET['date_from'])) {
        $where_clauses[] = "attempt_time >= ?";
        $params[] = $_GET['date_from'] . ' 00:00:00';
        $param_types .= 's';
    }
    
    if (!empty($_GET['date_to'])) {
        $where_clauses[] = "attempt_time <= ?";
        $params[] = $_GET['date_to'] . ' 23:59:59';
        $param_types .= 's';
    }
    
    if (!empty($_GET['ip'])) {
        $where_clauses[] = "ip_address LIKE ?";
        $params[] = '%' . $_GET['ip'] . '%';
        $param_types .= 's';
    }
    
    $where_sql = '';
    if (count($where_clauses) > 0) {
        $where_sql = 'WHERE ' . implode(' AND ', $where_clauses);
    }
    
    // Get total count
    $count_sql = "SELECT COUNT(*) as total FROM login_attempts " . $where_sql;
    if ($param_types) {
        $count_stmt = $conn->prepare($count_sql);
        if (count($params) > 0) {
            $count_stmt->bind_param($param_types, ...$params);
        }
        $count_stmt->execute();
        $count_result = $count_stmt->get_result();
        $total_records = $count_result->fetch_assoc()['total'];
        $count_stmt->close();
    } else {
        $count_result = $conn->query($count_sql);
        $total_records = $count_result->fetch_assoc()['total'];
    }
    
    // Get paginated results
    $sql = "SELECT id, username, ip_address, user_agent, attempt_time, success, failure_reason 
            FROM login_attempts " . $where_sql . " 
            ORDER BY attempt_time DESC 
            LIMIT ? OFFSET ?";
    
    $params[] = $limit;
    $params[] = $offset;
    $param_types .= 'ii';
    
    $stmt = $conn->prepare($sql);
    if (count($params) > 0) {
        $stmt->bind_param($param_types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    
    $attempts = [];
    while ($row = $result->fetch_assoc()) {
        $attempts[] = [
            'id' => $row['id'],
            'username' => $row['username'],
            'ip_address' => $row['ip_address'],
            'user_agent' => $row['user_agent'],
            'attempt_time' => $row['attempt_time'],
            'success' => (bool)$row['success'],
            'failure_reason' => $row['failure_reason']
        ];
    }
    
    $stmt->close();
    
    $total_pages = ceil($total_records / $limit);
    $showing_from = $total_records > 0 ? $offset + 1 : 0;
    $showing_to = min($offset + $limit, $total_records);
    
    echo json_encode([
        'success' => true,
        'attempts' => $attempts,
        'pagination' => [
            'current_page' => $page,
            'total_pages' => $total_pages,
            'total_records' => $total_records,
            'showing_from' => $showing_from,
            'showing_to' => $showing_to
        ]
    ]);
}

function exportToCSV($conn) {
    // Build WHERE clause based on filters (same as getAttemptsList)
    $where_clauses = [];
    $params = [];
    $param_types = '';
    
    if (!empty($_GET['username'])) {
        $where_clauses[] = "username LIKE ?";
        $params[] = '%' . $_GET['username'] . '%';
        $param_types .= 's';
    }
    
    if (!empty($_GET['status'])) {
        if ($_GET['status'] === 'success') {
            $where_clauses[] = "success = 1";
        } elseif ($_GET['status'] === 'failed') {
            $where_clauses[] = "success = 0";
        }
    }
    
    if (!empty($_GET['date_from'])) {
        $where_clauses[] = "attempt_time >= ?";
        $params[] = $_GET['date_from'] . ' 00:00:00';
        $param_types .= 's';
    }
    
    if (!empty($_GET['date_to'])) {
        $where_clauses[] = "attempt_time <= ?";
        $params[] = $_GET['date_to'] . ' 23:59:59';
        $param_types .= 's';
    }
    
    if (!empty($_GET['ip'])) {
        $where_clauses[] = "ip_address LIKE ?";
        $params[] = '%' . $_GET['ip'] . '%';
        $param_types .= 's';
    }
    
    $where_sql = '';
    if (count($where_clauses) > 0) {
        $where_sql = 'WHERE ' . implode(' AND ', $where_clauses);
    }
    
    $sql = "SELECT username, ip_address, attempt_time, success, failure_reason, user_agent 
            FROM login_attempts " . $where_sql . " 
            ORDER BY attempt_time DESC";
    
    if ($param_types) {
        $stmt = $conn->prepare($sql);
        if (count($params) > 0) {
            $stmt->bind_param($param_types, ...$params);
        }
        $stmt->execute();
        $result = $stmt->get_result();
    } else {
        $result = $conn->query($sql);
    }
    
    // Set headers for CSV download
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="login_attempts_' . date('Y-m-d_His') . '.csv"');
    
    // Open output stream
    $output = fopen('php://output', 'w');
    
    // Write CSV header
    fputcsv($output, ['Timestamp', 'Username', 'Status', 'IP Address', 'Failure Reason', 'User Agent']);
    
    // Write data rows
    while ($row = $result->fetch_assoc()) {
        fputcsv($output, [
            $row['attempt_time'],
            $row['username'],
            $row['success'] ? 'Success' : 'Failed',
            $row['ip_address'],
            $row['failure_reason'] ?? '',
            $row['user_agent'] ?? ''
        ]);
    }
    
    fclose($output);
    
    if (isset($stmt)) {
        $stmt->close();
    }
    
    exit();
}
?>

