<?php
// Secure Project Summary Tool for ChatKit
// Method: POST
// Auth: Authorization: Bearer <AGENTKIT_SERVICE_TOKEN>
// Body JSON: { "project_query": "Solar Farm 2" } OR { "project_id": 24 }

header('Content-Type: application/json');

// Allow session-auth fallback for in-portal calls
if (session_status() === PHP_SESSION_NONE) {
    session_name("logistics_session");
    @session_start();
}

// Load central config and env (lives one level above the portal)
@include_once dirname(__DIR__, 3) . '/config.php';

// Verify service token
$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
$isServiceAuth = false;
if (preg_match('/Bearer\s+(.+)/i', $authHeader, $m)) {
    $providedToken = trim($m[1]);
    $serviceToken = getenv('AGENTKIT_SERVICE_TOKEN') ?: '';
    if ($serviceToken === '' && defined('AGENTKIT_SERVICE_TOKEN')) {
        $serviceToken = (string) AGENTKIT_SERVICE_TOKEN;
    }
    if ($serviceToken !== '' && hash_equals($serviceToken, $providedToken)) {
        $isServiceAuth = true;
    }
}

// If not service-auth, allow logged-in global_admin (or admin) to call directly
if (!$isServiceAuth) {
    if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || !in_array($_SESSION['role'], ['global_admin','admin'])) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Forbidden']);
        exit();
    }
}

// Parse input
$raw = file_get_contents('php://input');
$json = json_decode($raw, true);
$projectId = 0;
$projectQuery = '';
$limit = 10;
if (is_array($json)) {
    if (isset($json['project_id'])) {
        $projectId = (int)$json['project_id'];
    }
    if (isset($json['project_query'])) {
        $projectQuery = trim((string)$json['project_query']);
    }
    // Accept Agent Builder schema alias: projectName
    if ($projectQuery === '' && isset($json['projectName'])) {
        $projectQuery = trim((string)$json['projectName']);
    }
    if (isset($json['limit'])) {
        $limit = max(1, min(100, (int)$json['limit']));
    }
}

$t0 = microtime(true);

$conn = getDBConnection();
if (!$conn) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database unavailable']);
    exit();
}

// Resolve project by ID or by name
$project = null;
if ($projectId > 0) {
    $stmt = $conn->prepare("SELECT id, account_id, project_name, warehouse_id, street_address, city, state, zip_code FROM projects WHERE id = ? LIMIT 1");
    $stmt->bind_param('i', $projectId);
} else {
    if ($projectQuery === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Provide project_id or project_query']);
        exit();
    }
    $stmt = $conn->prepare("SELECT id, account_id, project_name, warehouse_id, street_address, city, state, zip_code FROM projects WHERE project_name = ? LIMIT 1");
    $stmt->bind_param('s', $projectQuery);
}

if (!$stmt) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Query preparation failed']);
    exit();
}

$stmt->execute();
$res = $stmt->get_result();
if ($res && $res->num_rows > 0) {
    $project = $res->fetch_assoc();
}
$stmt->close();

if (!$project) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Project not found']);
    $conn->close();
    exit();
}

$pid = (int)$project['id'];

// Aggregate counts
$summary = [
    'project' => [
        'id' => $pid,
        'name' => $project['project_name'],
        'account_id' => (int)$project['account_id'],
        'location' => trim(($project['city'] ?: '') . ', ' . ($project['state'] ?: '')), 
    ],
    'counts' => [
        'modules' => 0,
        'deliveries' => 0,
        'documents' => new stdClass(),
    ],
];

// Modules count
if ($stmt = $conn->prepare("SELECT COUNT(*) AS c FROM modules WHERE project_id = ?")) {
    $stmt->bind_param('i', $pid);
    $stmt->execute();
    $r = $stmt->get_result();
    if ($r && ($row = $r->fetch_assoc())) { $summary['counts']['modules'] = (int)$row['c']; }
    $stmt->close();
}

// Deliveries count
if ($stmt = $conn->prepare("SELECT COUNT(*) AS c FROM deliveries WHERE project_id = ?")) {
    $stmt->bind_param('i', $pid);
    $stmt->execute();
    $r = $stmt->get_result();
    if ($r && ($row = $r->fetch_assoc())) { $summary['counts']['deliveries'] = (int)$row['c']; }
    $stmt->close();
}

// Documents by type (only active)
if ($stmt = $conn->prepare("SELECT document_type, COUNT(*) AS c FROM project_documents WHERE project_id = ? AND is_active = 1 GROUP BY document_type")) {
    $stmt->bind_param('i', $pid);
    $stmt->execute();
    $r = $stmt->get_result();
    if ($r) {
        $docs = [];
        while ($row = $r->fetch_assoc()) { $docs[$row['document_type']] = (int)$row['c']; }
        $summary['counts']['documents'] = (object)$docs;
    }
    $stmt->close();
}

$conn->close();

$summary['elapsedMs'] = (int) round((microtime(true) - $t0) * 1000);

echo json_encode(['success' => true, 'data' => $summary]);
exit();
?>


