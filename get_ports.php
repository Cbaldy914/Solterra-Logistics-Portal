<?php
session_name("logistics_session");
session_start();

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

header('Content-Type: application/json');

require_once '../config.php';
$conn = getDBConnection();
if (!$conn) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed']);
    exit();
}

try {
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
            http_response_code(403);
            echo json_encode(['error' => 'Account access denied']);
            exit();
        }
    }

    $account_clause = $role === 'global_admin' ? '' : ' AND account_id = ?';
    $stmt = $conn->prepare("
        SELECT id, name, city, state, address 
        FROM warehouses 
        WHERE is_port = 1$account_clause
        ORDER BY name ASC
    ");
    if ($role !== 'global_admin') {
        $stmt->bind_param("i", $account_id);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    
    $ports = [];
    while ($row = $result->fetch_assoc()) {
        $ports[] = [
            'id' => $row['id'],
            'name' => $row['name'],
            'city' => $row['city'] ?? '',
            'state' => $row['state'] ?? '',
            'address' => $row['address'] ?? ''
        ];
    }
    
    echo json_encode(['ports' => $ports]);
    
    $stmt->close();
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}

$conn->close();
?> 
