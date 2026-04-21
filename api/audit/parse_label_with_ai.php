<?php
/**
 * POST /api/audit/parse_label_with_ai.php  (multipart/form-data)
 *
 * Tier 2 scanning: user photographs a pallet label, this endpoint sends the
 * image to Gemini 2.5 Flash (via Sunny's existing config) and returns a
 * structured extraction that the client can use to pre-fill the manual
 * scan form.
 *
 * Required: session_id (POST), file (uploaded image)
 * Returns: { pallet_id, wattage, modules_per_pallet, supplier, equipment_type, confidence, ai_raw_response, usage }
 */

require_once __DIR__ . '/../../warehouse_audit_helpers.php';
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
    if (!$session_id) {
        audit_json_error('session_id required', 400);
    }
    $session = audit_require_session_access($conn, $session_id, $user_id, $role);

    // Early check on the disabled flag so we return a clean 409 instead of
    // making the user upload the file just to get rejected. The atomic
    // reservation below re-checks both flags and is the authoritative gate.
    if (empty($session['ai_vision_enabled'])) {
        audit_json_error('AI vision is disabled for this session', 409);
    }

    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        audit_json_error('File upload failed or missing', 400);
    }

    // --- File validation (happens BEFORE cap reservation so invalid
    // uploads don't burn call slots) ---
    $tmp = $_FILES['file']['tmp_name'];

    // Polyglot defense: use finfo and require extension-to-MIME match.
    $ALLOWED_IMAGE_MIMES = [
        'image/jpeg' => ['jpg', 'jpeg'],
        'image/png'  => ['png'],
        'image/heic' => ['heic'],
        'image/heif' => ['heif'],
        'image/webp' => ['webp'],
    ];
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime  = $finfo ? finfo_file($finfo, $tmp) : false;
    if ($finfo) finfo_close($finfo);
    if (!$mime || !isset($ALLOWED_IMAGE_MIMES[$mime])) {
        audit_json_error('Only JPEG / PNG / HEIC / HEIF / WebP images are allowed for AI label reads', 400);
    }
    $orig_ext = strtolower(pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION));
    if (!in_array($orig_ext, $ALLOWED_IMAGE_MIMES[$mime], true)) {
        audit_json_error('File extension does not match detected file type', 400);
    }

    if ((int)$_FILES['file']['size'] > 12 * 1024 * 1024) {
        audit_json_error('Image too large (max 12 MB)', 413);
    }

    $image_bytes = file_get_contents($tmp);
    if ($image_bytes === false || strlen($image_bytes) === 0) {
        audit_json_error('Could not read uploaded image', 400);
    }

    // --- Atomic call-cap reservation ---
    // This replaces the previous check-then-increment pattern (TOCTOU).
    // On failure we return 429 and never call Gemini; on success the
    // counter is permanently incremented and any outcome (success or API
    // failure) still burns the slot.
    if (!audit_reserve_ai_vision_slot($conn, $session_id)) {
        audit_json_error('AI vision call cap reached or vision disabled for this session', 429);
    }

    $model = $session['ai_vision_model'] ?: 'gemini-2.5-flash';
    $result = audit_call_gemini_label_parser($image_bytes, $mime, $model);

    if (!$result['success']) {
        audit_write_session_event($conn, $session_id, $user_id, 'ai_parse_failed', [
            'error' => $result['error'],
            'model' => $model,
        ]);
        audit_json_error('AI parse failed: ' . ($result['error'] ?: 'unknown'), 502);
    }

    // Record cost only (count was already incremented by the reservation).
    $cost = ($result['usage'] && isset($result['usage']['cost_usd'])) ? (float)$result['usage']['cost_usd'] : 0.0;
    audit_record_ai_vision_cost($conn, $session_id, $cost);

    audit_write_session_event($conn, $session_id, $user_id, 'ai_parse_called', [
        'model'         => $model,
        'confidence'    => $result['fields']['confidence'] ?? null,
        'input_tokens'  => $result['usage']['input_tokens']  ?? null,
        'output_tokens' => $result['usage']['output_tokens'] ?? null,
        'cost_usd'      => $cost,
    ]);

    audit_json_ok([
        'fields'       => $result['fields'],
        'usage'        => $result['usage'],
        'model'        => $model,
        'raw_response' => $result['raw_response'],
    ]);
} catch (Throwable $e) {
    audit_json_error($e->getMessage(), 500);
} finally {
    if (isset($conn) && $conn) { @$conn->close(); }
}
