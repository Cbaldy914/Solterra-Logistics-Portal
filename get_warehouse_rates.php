<?php
session_name("logistics_session");
session_start();

// Check if the user is logged in
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'User not logged in']);
    exit();
}

$user_role = $_SESSION['role'];

// Only admin and global_admin can access
if (!in_array($user_role, ['admin', 'global_admin'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Insufficient permissions']);
    exit();
}

// Database connection
require_once '../config.php';
$conn = getDBConnection();
if (!$conn) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit();
}

$warehouse_id = isset($_GET['warehouse_id']) ? intval($_GET['warehouse_id']) : 0;

if ($warehouse_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid warehouse ID']);
    exit();
}

$account_id = null;
if ($user_role !== 'global_admin') {
    $stmtAccount = $conn->prepare("SELECT account_id FROM customer_account_users WHERE user_id = ? AND role = 'admin' LIMIT 1");
    if ($stmtAccount) {
        $stmtAccount->bind_param("i", $_SESSION['user_id']);
        $stmtAccount->execute();
        $stmtAccount->bind_result($account_id);
        $stmtAccount->fetch();
        $stmtAccount->close();
    }
    if ($account_id) {
        $stmtAccess = $conn->prepare("SELECT id FROM warehouses WHERE id = ? AND account_id = ?");
        if ($stmtAccess) {
            $stmtAccess->bind_param("ii", $warehouse_id, $account_id);
            $stmtAccess->execute();
            $accessResult = $stmtAccess->get_result();
            $stmtAccess->close();
            if ($accessResult->num_rows === 0) {
                echo json_encode(['success' => false, 'message' => 'Warehouse access denied']);
                exit();
            }
        }
    }
}

try {
    // Fetch the most recent warehouse quote that has rates for this warehouse
    // Look through all warehouse_quotes and find the latest one with rates for this warehouse_id
    $stmt = $conn->prepare("
        SELECT estimate_data 
        FROM warehouse_quotes 
        WHERE estimate_data LIKE ? 
        ORDER BY created_at DESC 
        LIMIT 1
    ");
    $search_pattern = '%"warehouse_id":' . $warehouse_id . '%';
    $stmt->bind_param("s", $search_pattern);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $estimate_data = json_decode($row['estimate_data'], true);
        
        // Find the quote for this warehouse
        $warehouse_quote = null;
        if (!empty($estimate_data['quotes'])) {
            foreach ($estimate_data['quotes'] as $quote) {
                if (isset($quote['warehouse_id']) && $quote['warehouse_id'] == $warehouse_id) {
                    $warehouse_quote = $quote;
                    break;
                }
            }
        }
        
        if ($warehouse_quote) {
            echo json_encode([
                'success' => true,
                'rates' => [
                    'warehouse_location' => $warehouse_quote['warehouse_location'] ?? '',
                    'in_fee' => $warehouse_quote['in_fee_per_pallet'] ?? 0,
                    'out_fee' => $warehouse_quote['out_fee_per_pallet'] ?? 0,
                    'monthly_storage_fee' => $warehouse_quote['monthly_storage_cost_per_pallet'] ?? 0
                ]
            ]);
        } else {
            echo json_encode(['success' => false, 'message' => 'No rates found for this warehouse']);
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'No rates found for this warehouse']);
    }
    
    $stmt->close();
} catch (Exception $e) {
    error_log("Error fetching warehouse rates: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error fetching rates']);
}

$conn->close();
?>

