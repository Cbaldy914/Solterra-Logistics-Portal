<?php
/**
 * POST /api/audit/upload_photo.php  (multipart/form-data)
 * Uploads a photo and links it to a scan or equipment check within a session.
 *
 * Required form fields:
 *   session_id, photo_role (one of AUDIT_PHOTO_ROLES)
 *   file: uploaded photo
 * Optional:
 *   scan_id, equipment_check_id, caption
 */

require_once __DIR__ . '/../../warehouse_audit_helpers.php';
require_once __DIR__ . '/../../document_helpers.php';
require_once __DIR__ . '/../../../config.php';

list($user_id, $role) = audit_require_admin_boot();
audit_require_csrf();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    audit_json_error('POST required', 405);
}

$conn = getDBConnection();
if (!$conn) {
    audit_json_error('Database connection failed', 500);
}

try {
    $session_id = isset($_POST['session_id']) ? (int)$_POST['session_id'] : 0;
    $photo_role = $_POST['photo_role'] ?? '';
    $scan_id    = !empty($_POST['scan_id']) ? (int)$_POST['scan_id'] : null;
    $equipment_check_id = !empty($_POST['equipment_check_id']) ? (int)$_POST['equipment_check_id'] : null;
    $caption    = $_POST['caption'] ?? null;

    if (!$session_id || !$photo_role) {
        audit_json_error('session_id and photo_role required', 400);
    }
    if (!in_array($photo_role, AUDIT_PHOTO_ROLES, true)) {
        audit_json_error('Invalid photo_role', 400);
    }
    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        audit_json_error('File upload failed or missing', 400);
    }

    $session = audit_require_session_access($conn, $session_id, $user_id, $role);

    // Cross-session row scoping: if scan_id / equipment_check_id are supplied,
    // verify they belong to THIS session before accepting the upload. Without
    // this check a user with access to session A could attach evidence to
    // records in session B just by passing B's IDs (IDOR).
    if ($scan_id !== null) {
        $stmt = $conn->prepare("SELECT id FROM warehouse_audit_scans WHERE id = ? AND session_id = ? AND is_soft_deleted = 0 LIMIT 1");
        $stmt->bind_param("ii", $scan_id, $session_id);
        $stmt->execute();
        $scan_row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$scan_row) {
            audit_json_error('scan_id does not belong to this session', 403);
        }
    }
    if ($equipment_check_id !== null) {
        $stmt = $conn->prepare("SELECT id FROM warehouse_audit_equipment_checks WHERE id = ? AND session_id = ? LIMIT 1");
        $stmt->bind_param("ii", $equipment_check_id, $session_id);
        $stmt->execute();
        $eq_row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$eq_row) {
            audit_json_error('equipment_check_id does not belong to this session', 403);
        }
    }

    // Per-scan photo cap (plan called for max 5 photos per scan)
    if ($scan_id !== null) {
        $stmt = $conn->prepare("SELECT COUNT(*) AS n FROM warehouse_audit_scan_photos WHERE scan_id = ?");
        $stmt->bind_param("i", $scan_id);
        $stmt->execute();
        $count_row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ((int)$count_row['n'] >= 5) {
            audit_json_error('Maximum of 5 photos per scan reached', 409);
        }
    }

    $orig     = $_FILES['file']['name'];
    $tmp_name = $_FILES['file']['tmp_name'];
    $size     = (int)$_FILES['file']['size'];

    // Size cap: 15 MB per photo (iPhone HEIC from newer models can be ~4-8 MB)
    if ($size > 15 * 1024 * 1024) {
        audit_json_error('Photo too large (max 15 MB)', 413);
    }

    // Polyglot defense: use finfo for MIME detection (more reliable than
    // mime_content_type), AND require the filename extension to match the
    // detected MIME. This blocks malicious.php files that have a valid image
    // magic-byte header — their extension will not match the MIME table below.
    // Mirrors processDocumentUpload() at document_helpers.php:105 but with an
    // image-only whitelist extended to include iPhone/Android native formats.
    $ALLOWED_IMAGE_MIMES = [
        'image/jpeg' => ['jpg', 'jpeg'],
        'image/png'  => ['png'],
        'image/heic' => ['heic'],
        'image/heif' => ['heif'],
        'image/webp' => ['webp'],
        'image/gif'  => ['gif'],
    ];

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime  = $finfo ? finfo_file($finfo, $tmp_name) : false;
    if ($finfo) finfo_close($finfo);
    if (!$mime) {
        audit_json_error('Could not determine file MIME type', 400);
    }

    if (!isset($ALLOWED_IMAGE_MIMES[$mime])) {
        audit_json_error('Only JPEG / PNG / HEIC / HEIF / WebP / GIF images are allowed', 400);
    }

    $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
    if (!in_array($ext, $ALLOWED_IMAGE_MIMES[$mime], true)) {
        audit_json_error('File extension does not match detected file type (possible polyglot)', 400);
    }

    // Delegate file storage + project_documents insert to the shared helper
    $doc = [
        'project_id'        => $session['project_id'] ?: null,
        'warehouse_id'      => (int)$session['warehouse_id'],
        'document_type'     => 'warehouse_audit',
        'document_sub_type' => $photo_role,
        'original_name'     => $orig,
        'file_size'         => $size,
        'mime_type'         => $mime,
        'uploaded_by'       => $user_id,
        'tmp_name'          => $tmp_name,
        'description'       => $caption ?: ('Warehouse audit ' . $photo_role . ' photo for session ' . $session['session_code']),
    ];
    $result = saveDocumentToProjectDocuments($conn, $doc);
    $project_document_id = (int)$result['document_id'];

    // Backfill the warehouse_audit_session_id link column added by the migration
    $stmt = $conn->prepare("UPDATE project_documents SET warehouse_audit_session_id = ? WHERE id = ?");
    $stmt->bind_param("ii", $session_id, $project_document_id);
    $stmt->execute();
    $stmt->close();

    // Insert the join row
    // 7 params: session_id i, scan_id i (nullable), equipment_check_id i (nullable),
    //           project_document_id i, photo_role s, caption s (nullable), uploaded_by_user_id i
    $stmt = $conn->prepare("
        INSERT INTO warehouse_audit_scan_photos
            (session_id, scan_id, equipment_check_id, project_document_id, photo_role, caption, uploaded_by_user_id)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->bind_param("iiiissi", $session_id, $scan_id, $equipment_check_id, $project_document_id, $photo_role, $caption, $user_id);
    $stmt->execute();
    $join_id = (int)$stmt->insert_id;
    $stmt->close();

    audit_write_session_event($conn, $session_id, $user_id, 'photo_uploaded', [
        'photo_role' => $photo_role,
        'scan_id'    => $scan_id,
        'equipment_check_id' => $equipment_check_id,
        'project_document_id' => $project_document_id,
    ]);

    audit_json_ok([
        'scan_photo_id'       => $join_id,
        'project_document_id' => $project_document_id,
        'photo_role'          => $photo_role,
    ]);
} catch (Throwable $e) {
    audit_json_error($e->getMessage(), 400);
} finally {
    if (isset($conn) && $conn) { @$conn->close(); }
}
