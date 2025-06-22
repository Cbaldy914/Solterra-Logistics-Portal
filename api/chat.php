<?php
/**
 * Placeholder for Sunny’s chat endpoint.
 * Codex will replace this file with the real implementation.
 */
http_response_code(501);          // 501 Not Implemented
header('Content-Type: application/json');
echo json_encode([
    'status'  => 'error',
    'message' => 'Sunny chat API not implemented yet.'
]);
exit;