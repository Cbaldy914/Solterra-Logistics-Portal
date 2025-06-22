<?php
declare(strict_types=1);

session_name('logistics_session');
session_start();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit();
}

if (!isset($_SESSION['user_id'], $_SESSION['role'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input) || !isset($input['message'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid request']);
    exit();
}

$message = trim((string) $input['message']);

if (preg_match('/\b(insert|update|delete|truncate|drop)\b/i', $message)) {
    http_response_code(400);
    echo json_encode(['error' => 'Only read-only queries are allowed.']);
    exit();
}

require_once '../config.php';
$conn = getDBConnection();

function listTables(mysqli $conn): array
{
    $tables = [];
    $result = $conn->query('SHOW TABLES');
    while ($row = $result->fetch_array()) {
        $tables[] = $row[0];
    }
    return $tables;
}

function listColumns(mysqli $conn, string $table): array
{
    $stmt = $conn->prepare("SHOW COLUMNS FROM `{$table}`");
    $stmt->execute();
    $result = $stmt->get_result();
    $columns = [];
    while ($row = $result->fetch_assoc()) {
        $columns[] = $row['Field'];
    }
    $stmt->close();
    return $columns;
}

$schemaText = "";
foreach (listTables($conn) as $table) {
    $cols = listColumns($conn, $table);
    $schemaText .= "- {$table}: " . implode(', ', $cols) . "\n";
}

$role = (string) $_SESSION['role'];
$userId = (int) $_SESSION['user_id'];

$adjustedMessage = $message;
if ($role === 'user' && stripos($message, 'select') !== false) {
    if (!preg_match('/where\s+[^;]*user_id/i', $message)) {
        $adjustedMessage = rtrim($message, "; ") . " WHERE user_id = {$userId}";
    }
}

$prompt = "You are Sunny, an AI assistant for the Solterra Logistics Portal. "
    . "Only use read-only SQL (SELECT/SHOW). The current user role is {$role} "
    . "with ID {$userId}. Available tables and columns:\n{$schemaText}\n"
    . "User message: {$adjustedMessage}";

$aiHost = getenv('OC_AI_HOST');
$aiPort = getenv('OC_AI_PORT');
$model  = getenv('OC_AI_MODEL');

$payload = json_encode([
    'model' => $model,
    'prompt' => $prompt,
    'stream' => true,
]);

$ch = curl_init("http://{$aiHost}:{$aiPort}/api/generate");
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);

$reply = '';
$usageTokens = 0;

curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($ch, $data) use (&$reply, &$usageTokens) {
    static $buffer = '';
    $buffer .= $data;
    while (($pos = strpos($buffer, "\n")) !== false) {
        $line = trim(substr($buffer, 0, $pos));
        $buffer = substr($buffer, $pos + 1);
        if ($line === '') {
            continue;
        }
        $json = json_decode($line, true);
        if (!$json) {
            continue;
        }
        if (isset($json['response'])) {
            $reply .= $json['response'];
            echo json_encode(['token' => $json['response']]) . "\n";
            @ob_flush();
            flush();
        }
        if (!empty($json['done'])) {
            $usageTokens = ($json['prompt_eval_count'] ?? 0) + ($json['eval_count'] ?? 0);
        }
    }
    return strlen($data);
});

curl_exec($ch);
curl_close($ch);

echo json_encode([
    'done' => true,
    'reply' => $reply,
    'usage_tokens' => $usageTokens,
]) . "\n";
