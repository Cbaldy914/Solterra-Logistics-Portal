<?php
/**
 * API: Load Delivery Projection
 * Returns projection data with all related entities
 */

session_name("logistics_session");
session_start();

header('Content-Type: application/json');

// Check authentication
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit();
}

$user_id = $_SESSION['user_id'];
$role = $_SESSION['role'] ?? 'user';

require_once '../../config.php';
require_once '../projection_helpers.php';

$conn = getDBConnection();
if (!$conn) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database connection failed']);
    exit();
}

$projection_id = isset($_GET['projection_id']) ? intval($_GET['projection_id']) : 0;
$project_id = isset($_GET['project_id']) ? intval($_GET['project_id']) : 0;

try {
    if ($projection_id) {
        // Load specific projection
        $projection = get_projection($conn, $projection_id);

        if (!$projection) {
            throw new Exception('Projection not found');
        }

        // Verify access
        $access_check = $conn->prepare("
            SELECT p.id FROM projects p
            JOIN customer_account_users cau ON p.account_id = cau.account_id
            WHERE p.id = ? AND cau.user_id = ?
        ");
        $access_check->bind_param("ii", $projection['project_id'], $user_id);
        $access_check->execute();
        if ($access_check->get_result()->num_rows === 0 && $role !== 'global_admin') {
            throw new Exception('Access denied');
        }
        $access_check->close();

        // Get available module batches for this project
        $available_batches = get_available_module_batches($conn, $projection['project_id']);

        echo json_encode([
            'success' => true,
            'projection' => $projection,
            'available_batches' => $available_batches
        ]);

    } elseif ($project_id) {
        // Load all projections for a project
        $projections = get_project_projections($conn, $project_id);

        // Verify access
        $access_check = $conn->prepare("
            SELECT p.id FROM projects p
            JOIN customer_account_users cau ON p.account_id = cau.account_id
            WHERE p.id = ? AND cau.user_id = ?
        ");
        $access_check->bind_param("ii", $project_id, $user_id);
        $access_check->execute();
        if ($access_check->get_result()->num_rows === 0 && $role !== 'global_admin') {
            throw new Exception('Access denied');
        }
        $access_check->close();

        // Get primary projection if exists
        $primary = get_primary_projection($conn, $project_id);

        // Get available module batches
        $available_batches = get_available_module_batches($conn, $project_id);

        // Get project info
        $project_stmt = $conn->prepare("
            SELECT p.*, ca.name AS account_name
            FROM projects p
            JOIN customer_accounts ca ON ca.id = p.account_id
            WHERE p.id = ?
        ");
        $project_stmt->bind_param("i", $project_id);
        $project_stmt->execute();
        $project = $project_stmt->get_result()->fetch_assoc();
        $project_stmt->close();

        echo json_encode([
            'success' => true,
            'project' => $project,
            'projections' => $projections,
            'primary_projection' => $primary,
            'available_batches' => $available_batches,
            'has_projections' => !empty($projections)
        ]);

    } else {
        throw new Exception('Either projection_id or project_id is required');
    }

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

$conn->close();
