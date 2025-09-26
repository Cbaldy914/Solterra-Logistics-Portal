<?php
/**
 * Sunny Chat File Upload
 * Authenticated endpoint to upload a document for AI analysis.
 */

// JSON response
header('Content-Type: application/json');

if (session_status() === PHP_SESSION_NONE) {
    // Align with portal session name
    session_name('logistics_session');
    session_start();
}

// Require auth
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Authentication required']);
    exit;
}

try {
    if (!isset($_FILES['file'])) {
        throw new Exception('No file uploaded');
    }

    $file = $_FILES['file'];
    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('Upload error: ' . $file['error']);
    }

    // Limits
    $maxSize = 10 * 1024 * 1024; // 10MB
    if ($file['size'] > $maxSize) {
        throw new Exception('File too large (max 10MB)');
    }

    // Validate mime/type
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']);
    $allowed = [
        'application/pdf',
        'text/plain',
        'text/csv',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' // .docx
    ];
    if (!in_array($mime, $allowed)) {
        $acceptedList = 'PDF, DOCX, TXT, CSV';
        $maxMB = intval($maxSize / (1024 * 1024));
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'Unsupported file type: ' . $mime,
            'accepted' => $acceptedList,
            'max_size_bytes' => $maxSize,
            'max_size' => $maxMB . 'MB'
        ]);
        exit;
    }

    // Ensure upload dir exists
    $uploadDir = __DIR__ . '/../uploads';
    if (!is_dir($uploadDir)) {
        @mkdir($uploadDir, 0755, true);
    }

    // Unique name
    $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
    $safeBase = preg_replace('/[^A-Za-z0-9._-]/', '_', pathinfo($file['name'], PATHINFO_FILENAME));
    $unique = $safeBase . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4));
    $destName = $unique . ($ext ? ('.' . $ext) : '');
    $destPath = $uploadDir . '/' . $destName;

    if (!move_uploaded_file($file['tmp_name'], $destPath)) {
        throw new Exception('Failed to move uploaded file');
    }

    // Track upload in session for easy reference
    $upload = [
        'id' => $unique,
        'filename' => $file['name'],
        'stored_name' => $destName,
        'path' => $destPath,
        'mime' => $mime,
        'size' => $file['size'],
        'uploaded_at' => date('c')
    ];

    if (!isset($_SESSION['sunny_uploads'])) {
        $_SESSION['sunny_uploads'] = [];
    }
    $_SESSION['sunny_uploads'][$unique] = $upload;
    $_SESSION['sunny_last_upload'] = $unique;

    echo json_encode([
        'success' => true,
        'upload' => [
            'id' => $unique,
            'filename' => $file['name'],
            'mime' => $mime,
            'size' => $file['size']
        ]
    ]);
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
