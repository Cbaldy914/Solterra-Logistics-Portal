<?php
/**
 * Warehouse Audit Helpers
 *
 * Shared server-side logic for the mobile-first warehouse pallet-by-pallet
 * audit feature. Endpoints under api/audit/* compose these functions.
 *
 * Tables touched: warehouse_audit_sessions, warehouse_audit_scans,
 * warehouse_audit_scan_photos, warehouse_audit_equipment_checks,
 * warehouse_audit_session_events, warehouse_audit_offline_queue_log,
 * inventory_pallets (via commit step), project_documents (via photos).
 */

// ---------------------------------------------------------------------------
// Constants
// ---------------------------------------------------------------------------

const AUDIT_SESSION_STATUSES = ['draft', 'in_progress', 'review', 'committed', 'completed', 'cancelled'];
const AUDIT_SCAN_METHODS     = ['barcode', 'qr', 'ai_vision', 'manual'];
const AUDIT_PHOTO_ROLES      = ['label', 'pallet_overview', 'damage', 'context', 'equipment_tag', 'equipment_overview', 'signature'];
const AUDIT_EQUIPMENT_STATUSES = ['not_verified', 'verified', 'missing', 'damaged', 'wrong_model'];

const AUDIT_DISCREPANCY_CODES = [
    'UNKNOWN_WATT',          // parsed_wattage not in expected_wattages_json
    'NO_WATT_DETECTED',      // couldn't extract wattage from raw_value OR wattage <= 0
    'QUANTITY_DRIFT',        // quantity != expected_modules_per_pallet
    'INVALID_QUANTITY',      // quantity <= 0 (empty or invalid)
    'DUPLICATE_IN_SESSION',  // normalized_pallet_id already seen in this session
    'ALREADY_IN_INVENTORY',  // matches existing inventory_pallets row (checked at commit)
    'INVALID_CORE_FIELDS',   // set at commit time when a scan is skipped due to missing core data
    'UNREADABLE',            // manual entry flagged label as damaged
    'DAMAGED_PALLET',        // a damage photo was attached
    'LOW_AI_CONFIDENCE',     // ai_vision confidence < 0.6
];

/**
 * Extraction prompt sent to Gemini alongside each pallet label image.
 * The responseSchema in the generationConfig enforces JSON shape — the prompt
 * is purely for semantic guidance.
 */
const GEMINI_LABEL_EXTRACTION_PROMPT = <<<PROMPT
You are reading a physical solar module pallet label from a warehouse audit photo.
Extract the following fields from the image and return them as JSON matching the schema.

Fields:
- pallet_id: the full serial/ID printed on the label (may contain slashes, dashes, letters, digits)
- wattage: module wattage in integer watts (e.g. 690, 695, 700)
- modules_per_pallet: number of modules on this pallet if printed
- supplier: manufacturer name (e.g. "Canadian Solar", "CSI", "Meyer Burger")
- equipment_type: model/type designation (e.g. "CSI 690 W", "MOD 695")
- confidence: your overall confidence in the extraction, 0.00-1.00

Rules:
- Return null for any field you cannot read with high confidence.
- Do not invent, guess, or infer values that are not clearly legible.
- confidence reflects the label's readability, not your certainty in field boundaries.
PROMPT;


// ---------------------------------------------------------------------------
// Access control and context resolution
// ---------------------------------------------------------------------------

/**
 * Returns the account_id for the current user based on role.
 * global_admin gets NULL (access everything); admin / customer_admin / user
 * get their account via customer_account_users lookup.
 */
function audit_get_user_account_id($conn, $user_id, $role)
{
    if ($role === 'global_admin') {
        return null; // global scope
    }
    $stmt = $conn->prepare("SELECT account_id FROM customer_account_users WHERE user_id = ? LIMIT 1");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();
    return $row ? (int)$row['account_id'] : null;
}

/**
 * Verifies that the current user/role can access the given session.
 * Throws RuntimeException with descriptive message on failure.
 * Returns the loaded session row on success.
 */
function audit_require_session_access($conn, $session_id, $user_id, $role)
{
    if (!in_array($role, ['admin', 'global_admin', 'customer_admin'], true)) {
        throw new RuntimeException('Admin role required');
    }
    $session = audit_get_session_by_id($conn, (int)$session_id);
    if (!$session) {
        throw new RuntimeException('Session not found');
    }
    if ($role === 'global_admin') {
        return $session;
    }
    $account_id = audit_get_user_account_id($conn, $user_id, $role);
    if ($account_id === null || (int)$session['account_id'] !== (int)$account_id) {
        throw new RuntimeException('Access denied to this session');
    }
    return $session;
}


// ---------------------------------------------------------------------------
// Session CRUD
// ---------------------------------------------------------------------------

/**
 * Generates a 12-char human-friendly session code using Crockford base32.
 * Format: 3 letters (derived from warehouse/city prefix) + hyphen + 8 base32 chars.
 * The warehouse prefix is passed in so operators can scan the dashboard and
 * instantly recognize which facility a session belongs to.
 */
function audit_generate_session_code($warehouse_prefix = 'AUD')
{
    // Crockford base32 alphabet (no I, L, O, U to avoid confusion)
    $alphabet = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
    $bytes = random_bytes(5);
    $code = '';
    for ($i = 0; $i < 8; $i++) {
        $code .= $alphabet[ord($bytes[$i % 5]) % 32];
    }
    $prefix = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $warehouse_prefix) ?: 'AUD', 0, 3));
    if (strlen($prefix) < 3) {
        $prefix = str_pad($prefix, 3, 'X');
    }
    return $prefix . '-' . $code;
}

/**
 * Inserts a new warehouse_audit_sessions row. Returns the new session ID.
 * Does not call writeSessionEvent — the caller should (so the event can
 * carry the full creation payload).
 */
function audit_create_session($conn, array $params)
{
    $required = ['account_id', 'warehouse_id', 'created_by_user_id', 'session_code'];
    foreach ($required as $field) {
        if (empty($params[$field])) {
            throw new InvalidArgumentException("Missing required field: $field");
        }
    }

    $account_id                  = (int)$params['account_id'];
    $warehouse_id                = (int)$params['warehouse_id'];
    $project_id                  = isset($params['project_id']) && $params['project_id'] !== '' ? (int)$params['project_id'] : null;
    $manufacturer_id             = isset($params['manufacturer_id']) && $params['manufacturer_id'] !== '' ? (int)$params['manufacturer_id'] : null;
    $created_by_user_id          = (int)$params['created_by_user_id'];
    $customer_name               = $params['customer_name']        ?? null;
    $service_description         = $params['service_description']  ?? null;
    $service_fee_usd             = isset($params['service_fee_usd']) && $params['service_fee_usd'] !== '' ? (float)$params['service_fee_usd'] : null;
    $session_code                = $params['session_code'];
    $status                      = $params['status'] ?? 'in_progress';
    $expected_pallet_count       = isset($params['expected_pallet_count']) && $params['expected_pallet_count'] !== '' ? (int)$params['expected_pallet_count'] : null;
    $expected_wattages_json      = isset($params['expected_wattages']) ? json_encode(array_values(array_map('intval', $params['expected_wattages']))) : null;
    $expected_modules_per_pallet = isset($params['expected_modules_per_pallet']) && $params['expected_modules_per_pallet'] !== '' ? (int)$params['expected_modules_per_pallet'] : null;
    $expected_equipment_json     = isset($params['expected_equipment']) ? json_encode($params['expected_equipment']) : null;
    $ai_vision_enabled           = isset($params['ai_vision_enabled']) ? (int)(bool)$params['ai_vision_enabled'] : 1;
    $ai_vision_model             = $params['ai_vision_model'] ?? 'gemini-2.5-flash';
    $ai_vision_call_cap          = isset($params['ai_vision_call_cap']) ? (int)$params['ai_vision_call_cap'] : 2000;

    if (!in_array($status, AUDIT_SESSION_STATUSES, true)) {
        throw new InvalidArgumentException("Invalid status: $status");
    }

    $started_at = ($status === 'in_progress') ? date('Y-m-d H:i:s') : null;

    $stmt = $conn->prepare("
        INSERT INTO warehouse_audit_sessions (
            account_id, warehouse_id, project_id, manufacturer_id, created_by_user_id,
            customer_name, service_description, service_fee_usd, session_code, status,
            expected_pallet_count, expected_wattages_json, expected_modules_per_pallet,
            expected_equipment_json, ai_vision_enabled, ai_vision_model, ai_vision_call_cap,
            started_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    $stmt->bind_param(
        "iiiiissdssisisisi" . "s",
        $account_id,
        $warehouse_id,
        $project_id,
        $manufacturer_id,
        $created_by_user_id,
        $customer_name,
        $service_description,
        $service_fee_usd,
        $session_code,
        $status,
        $expected_pallet_count,
        $expected_wattages_json,
        $expected_modules_per_pallet,
        $expected_equipment_json,
        $ai_vision_enabled,
        $ai_vision_model,
        $ai_vision_call_cap,
        $started_at
    );
    $stmt->execute();
    $session_id = $stmt->insert_id;
    $stmt->close();
    return (int)$session_id;
}

/**
 * Loads a session row by ID (no access check).
 * Use audit_require_session_access to gate.
 */
function audit_get_session_by_id($conn, $session_id)
{
    $stmt = $conn->prepare("SELECT * FROM warehouse_audit_sessions WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $session_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) {
        return null;
    }
    // Decode JSON columns for consumer convenience
    $row['expected_wattages']  = $row['expected_wattages_json']  ? json_decode($row['expected_wattages_json'], true)  : [];
    $row['expected_equipment'] = $row['expected_equipment_json'] ? json_decode($row['expected_equipment_json'], true) : [];
    return $row;
}

/**
 * Returns the full session state payload used by the client to rehydrate
 * the live scanning page. Includes session row, all scans (non-deleted),
 * all equipment checks, photo counts, and reconciliation summary.
 */
function audit_get_session_state($conn, $session_id)
{
    $session = audit_get_session_by_id($conn, $session_id);
    if (!$session) {
        return null;
    }

    // Scans
    $stmt = $conn->prepare("
        SELECT id, sequence_number, scan_method, raw_value, normalized_pallet_id,
               parsed_wattage, parsed_serial, equipment_code, supplier, quantity,
               expected_wattage_match, is_discrepancy, discrepancy_codes, discrepancy_notes,
               notes, ai_confidence, client_uuid, client_captured_at, verified_by_user_id,
               inventory_pallet_id, created_at, updated_at
        FROM warehouse_audit_scans
        WHERE session_id = ? AND is_soft_deleted = 0
        ORDER BY sequence_number ASC
    ");
    $stmt->bind_param("i", $session_id);
    $stmt->execute();
    $scans_result = $stmt->get_result();
    $scans = [];
    while ($row = $scans_result->fetch_assoc()) {
        $scans[] = $row;
    }
    $stmt->close();

    // Equipment checks
    $stmt = $conn->prepare("
        SELECT * FROM warehouse_audit_equipment_checks
        WHERE session_id = ?
        ORDER BY id ASC
    ");
    $stmt->bind_param("i", $session_id);
    $stmt->execute();
    $equipment_result = $stmt->get_result();
    $equipment = [];
    while ($row = $equipment_result->fetch_assoc()) {
        $equipment[] = $row;
    }
    $stmt->close();

    // Photo counts per scan
    $stmt = $conn->prepare("
        SELECT scan_id, equipment_check_id, COUNT(*) AS photo_count
        FROM warehouse_audit_scan_photos
        WHERE session_id = ?
        GROUP BY scan_id, equipment_check_id
    ");
    $stmt->bind_param("i", $session_id);
    $stmt->execute();
    $photo_counts_result = $stmt->get_result();
    $photo_counts = ['scans' => [], 'equipment' => []];
    while ($row = $photo_counts_result->fetch_assoc()) {
        if ($row['scan_id']) {
            $photo_counts['scans'][(int)$row['scan_id']] = (int)$row['photo_count'];
        }
        if ($row['equipment_check_id']) {
            $photo_counts['equipment'][(int)$row['equipment_check_id']] = (int)$row['photo_count'];
        }
    }
    $stmt->close();

    // Reconciliation summary
    $reconciliation = audit_build_reconciliation($conn, $session_id);

    return [
        'session'       => $session,
        'scans'         => $scans,
        'equipment'     => $equipment,
        'photo_counts'  => $photo_counts,
        'reconciliation'=> $reconciliation,
    ];
}

/**
 * Generic session update wrapper used by status transitions, notes, etc.
 * $updates is an associative array of column => value. Whitelists keys.
 */
function audit_update_session($conn, $session_id, array $updates)
{
    $allowed = [
        'status', 'customer_name', 'service_description', 'service_fee_usd',
        'expected_pallet_count', 'expected_wattages_json', 'expected_modules_per_pallet',
        'expected_equipment_json', 'discrepancy_notes', 'ai_vision_enabled', 'ai_vision_model',
        'ai_vision_call_cap', 'committed_to_inventory_at', 'committed_by_user_id',
        'module_batch_id', 'started_at', 'completed_at'
    ];
    $set_clauses = [];
    $params = [];
    $types = '';
    foreach ($updates as $col => $val) {
        if (!in_array($col, $allowed, true)) {
            continue;
        }
        $set_clauses[] = "$col = ?";
        $params[] = $val;
        $types .= is_int($val) ? 'i' : (is_float($val) ? 'd' : 's');
    }
    if (empty($set_clauses)) {
        return false;
    }
    $params[] = (int)$session_id;
    $types .= 'i';
    $sql = "UPDATE warehouse_audit_sessions SET " . implode(', ', $set_clauses) . " WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $result = $stmt->execute();
    $stmt->close();
    return $result;
}

/**
 * Append-only event log write. Payload is any JSON-serializable data.
 * Never throws on log failure — logging must not block business logic.
 */
function audit_write_session_event($conn, $session_id, $actor_user_id, $event_type, $payload = null)
{
    try {
        $payload_json = $payload !== null ? json_encode($payload) : null;
        $stmt = $conn->prepare("
            INSERT INTO warehouse_audit_session_events
                (session_id, actor_user_id, event_type, payload)
            VALUES (?, ?, ?, ?)
        ");
        $session_id_i = (int)$session_id;
        $actor_i      = $actor_user_id !== null ? (int)$actor_user_id : null;
        $stmt->bind_param("iiss", $session_id_i, $actor_i, $event_type, $payload_json);
        $stmt->execute();
        $stmt->close();
    } catch (Throwable $e) {
        error_log("audit_write_session_event failed: " . $e->getMessage());
    }
}


// ---------------------------------------------------------------------------
// Scan handling
// ---------------------------------------------------------------------------

/**
 * Normalizes a raw pallet label for duplicate comparison.
 * Uppercases, strips whitespace, strips non-alphanumeric separators (keeps /-_).
 */
function audit_normalize_pallet_id($raw)
{
    if ($raw === null) {
        return null;
    }
    $s = strtoupper(trim((string)$raw));
    // Keep alphanumerics + common separators used by manufacturer IDs
    $s = preg_replace('/[^A-Z0-9\/\-_]/', '', $s);
    return $s === '' ? null : $s;
}

/**
 * Extracts structured fields from a raw scan value against session expected config.
 * Best-effort: returns nulls when a field can't be confidently parsed.
 *
 * Wattage detection heuristic: looks for a trailing 3-digit number matching any
 * of the session's expected_wattages; falls back to any standalone 3-digit
 * number in the 500-800 W range (typical solar module watt range).
 */
function audit_parse_scan_payload($raw_value, array $session_config)
{
    $raw = (string)$raw_value;
    $expected = $session_config['expected_wattages'] ?? [];
    $expected = array_map('intval', is_array($expected) ? $expected : []);

    $parsed = [
        'normalized_pallet_id' => audit_normalize_pallet_id($raw),
        'parsed_wattage'       => null,
        'parsed_serial'        => null,
        'equipment_code'       => null,
        'supplier'             => null,
    ];

    // Strategy 1: trailing expected wattage ("/690" or "-700" or " 695 ")
    foreach ($expected as $w) {
        if (preg_match('/(?:^|[^0-9])(' . (int)$w . ')(?:$|[^0-9])/', $raw)) {
            $parsed['parsed_wattage'] = (int)$w;
            break;
        }
    }

    // Strategy 2: any 3-digit number in typical module-wattage range
    if ($parsed['parsed_wattage'] === null) {
        if (preg_match_all('/(?:^|[^0-9])(\d{3})(?:$|[^0-9])/', $raw, $matches)) {
            foreach ($matches[1] as $candidate) {
                $w = (int)$candidate;
                if ($w >= 300 && $w <= 800) {
                    $parsed['parsed_wattage'] = $w;
                    break;
                }
            }
        }
    }

    $parsed['parsed_serial'] = $parsed['normalized_pallet_id'];

    return $parsed;
}

/**
 * Runs the discrepancy classification rules against a single scan and a
 * session context. Mutates nothing. Returns an array of discrepancy codes.
 *
 * $prior_normalized is the set of already-seen normalized pallet IDs in
 * the session (used for DUPLICATE_IN_SESSION). Caller provides this to
 * avoid an N+1 query inside the classifier.
 */
function audit_classify_discrepancy(array $scan, array $session, array $prior_normalized, array $flags = [])
{
    $codes = [];
    $expected = isset($session['expected_wattages']) ? (array)$session['expected_wattages'] : [];
    $expected_mpp = $session['expected_modules_per_pallet'] ?? null;

    $parsed_w = $scan['parsed_wattage'] ?? null;
    $normalized = $scan['normalized_pallet_id'] ?? null;
    $scan_quantity = isset($scan['quantity']) ? (int)$scan['quantity'] : 0;

    // Wattage validity: null, zero, or negative are all "not detected".
    // This is a defense-in-depth check — the commit step also re-filters,
    // but flagging here keeps bad rows out of aggregation in the first place.
    if ($parsed_w === null || (int)$parsed_w <= 0) {
        $codes[] = 'NO_WATT_DETECTED';
    } elseif (!empty($expected) && !in_array((int)$parsed_w, array_map('intval', $expected), true)) {
        $codes[] = 'UNKNOWN_WATT';
    }

    // Invalid quantity: zero or negative. Flagged regardless of expected_mpp.
    if ($scan_quantity <= 0) {
        $codes[] = 'INVALID_QUANTITY';
    } elseif ($expected_mpp !== null && $scan_quantity !== (int)$expected_mpp) {
        $codes[] = 'QUANTITY_DRIFT';
    }

    if ($normalized && in_array($normalized, $prior_normalized, true)) {
        $codes[] = 'DUPLICATE_IN_SESSION';
    }

    if (!empty($flags['label_unreadable']) && ($scan['scan_method'] ?? '') === 'manual') {
        $codes[] = 'UNREADABLE';
    }

    if (!empty($flags['damage_photo'])) {
        $codes[] = 'DAMAGED_PALLET';
    }

    if (($scan['scan_method'] ?? '') === 'ai_vision'
        && isset($scan['ai_confidence'])
        && $scan['ai_confidence'] !== null
        && (float)$scan['ai_confidence'] < 0.6) {
        $codes[] = 'LOW_AI_CONFIDENCE';
    }

    return $codes;
}

/**
 * Fetches the set of already-seen normalized pallet IDs for a session.
 * Small helper used before calling audit_classify_discrepancy.
 */
function audit_get_prior_normalized_ids($conn, $session_id, $exclude_client_uuid = null)
{
    $sql = "SELECT normalized_pallet_id FROM warehouse_audit_scans
            WHERE session_id = ? AND is_soft_deleted = 0 AND normalized_pallet_id IS NOT NULL";
    $types = 'i';
    $params = [(int)$session_id];
    if ($exclude_client_uuid !== null) {
        $sql .= " AND client_uuid != ?";
        $types .= 's';
        $params[] = $exclude_client_uuid;
    }
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    $ids = [];
    while ($row = $result->fetch_assoc()) {
        $ids[] = $row['normalized_pallet_id'];
    }
    $stmt->close();
    return $ids;
}

/**
 * Upserts a scan by (session_id, client_uuid). This is the idempotent
 * offline-retry-safe entry point.
 *
 * Returns the full scan row after upsert (including id and sequence_number).
 */
function audit_upsert_scan($conn, $session_id, array $payload, $actor_user_id)
{
    if (empty($payload['client_uuid'])) {
        throw new InvalidArgumentException('client_uuid required for scan upsert');
    }
    $client_uuid = (string)$payload['client_uuid'];

    $session = audit_get_session_by_id($conn, (int)$session_id);
    if (!$session) {
        throw new RuntimeException('Session not found');
    }

    $scan_method = $payload['scan_method'] ?? 'manual';
    if (!in_array($scan_method, AUDIT_SCAN_METHODS, true)) {
        throw new InvalidArgumentException("Invalid scan_method: $scan_method");
    }

    $raw_value         = (string)($payload['raw_value'] ?? '');
    $quantity          = isset($payload['quantity']) ? (int)$payload['quantity'] : ($session['expected_modules_per_pallet'] ?: 0);
    $notes             = $payload['notes'] ?? null;
    $ai_confidence     = isset($payload['ai_confidence']) && $payload['ai_confidence'] !== '' ? (float)$payload['ai_confidence'] : null;
    $ai_raw_response   = isset($payload['ai_raw_response']) ? (is_string($payload['ai_raw_response']) ? $payload['ai_raw_response'] : json_encode($payload['ai_raw_response'])) : null;
    $client_captured_at= $payload['client_captured_at'] ?? null;

    // Parse label fields from raw value (may be overridden by explicit payload fields)
    $parsed = audit_parse_scan_payload($raw_value, $session);
    $parsed_wattage     = isset($payload['parsed_wattage'])  && $payload['parsed_wattage']  !== '' ? (int)$payload['parsed_wattage']  : $parsed['parsed_wattage'];
    $parsed_serial      = $payload['parsed_serial']      ?? $parsed['parsed_serial'];
    $equipment_code     = $payload['equipment_code']     ?? $parsed['equipment_code'];
    $supplier           = $payload['supplier']           ?? $parsed['supplier'];
    $normalized_pallet  = $payload['normalized_pallet_id'] ?? $parsed['normalized_pallet_id'];

    $expected_wattage_match = null;
    if ($parsed_wattage !== null && !empty($session['expected_wattages'])) {
        $expected_wattage_match = in_array((int)$parsed_wattage, array_map('intval', $session['expected_wattages']), true) ? 1 : 0;
    }

    // Check if an existing scan exists for this client_uuid (upsert path)
    $stmt = $conn->prepare("SELECT id, sequence_number FROM warehouse_audit_scans WHERE session_id = ? AND client_uuid = ? LIMIT 1");
    $stmt->bind_param("is", $session_id, $client_uuid);
    $stmt->execute();
    $existing = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    // Classify discrepancy
    $prior_normalized = audit_get_prior_normalized_ids($conn, $session_id, $client_uuid);
    $scan_for_classification = [
        'scan_method'          => $scan_method,
        'parsed_wattage'       => $parsed_wattage,
        'normalized_pallet_id' => $normalized_pallet,
        'quantity'             => $quantity,
        'ai_confidence'        => $ai_confidence,
    ];
    $codes = audit_classify_discrepancy(
        $scan_for_classification,
        $session,
        $prior_normalized,
        [
            'label_unreadable' => !empty($payload['label_unreadable']),
            'damage_photo'     => !empty($payload['damage_photo']),
        ]
    );
    $is_discrepancy = empty($codes) ? 0 : 1;
    $discrepancy_codes = empty($codes) ? null : implode(',', $codes);
    $discrepancy_notes = $payload['discrepancy_notes'] ?? null;

    if ($existing) {
        // Update
        $stmt = $conn->prepare("
            UPDATE warehouse_audit_scans SET
                scan_method = ?, raw_value = ?, normalized_pallet_id = ?,
                parsed_wattage = ?, parsed_serial = ?, equipment_code = ?, supplier = ?,
                quantity = ?, expected_wattage_match = ?, is_discrepancy = ?,
                discrepancy_codes = ?, discrepancy_notes = ?, notes = ?,
                ai_confidence = ?, ai_raw_response = ?,
                client_captured_at = ?, verified_by_user_id = ?
            WHERE id = ?
        ");
        // Type string breakdown (18 params):
        //   scan_method s, raw_value s, normalized s, parsed_wattage i,
        //   parsed_serial s, equipment_code s, supplier s,
        //   quantity i, expected_wattage_match i, is_discrepancy i,
        //   discrepancy_codes s, discrepancy_notes s, notes s,
        //   ai_confidence d, ai_raw_response s,
        //   client_captured_at s, verified_by i, WHERE id i
        $existing_id = (int)$existing['id'];
        $stmt->bind_param(
            "sssisssiiisssdssii",
            $scan_method, $raw_value, $normalized_pallet,
            $parsed_wattage, $parsed_serial, $equipment_code, $supplier,
            $quantity, $expected_wattage_match, $is_discrepancy,
            $discrepancy_codes, $discrepancy_notes, $notes,
            $ai_confidence, $ai_raw_response,
            $client_captured_at, $actor_user_id,
            $existing_id
        );
        $stmt->execute();
        $stmt->close();
        $scan_id = (int)$existing['id'];
        audit_write_session_event($conn, $session_id, $actor_user_id, 'scan_edited', ['scan_id' => $scan_id, 'client_uuid' => $client_uuid]);
    } else {
        // Get next sequence number
        $stmt = $conn->prepare("SELECT COALESCE(MAX(sequence_number), 0) + 1 AS next_seq FROM warehouse_audit_scans WHERE session_id = ?");
        $stmt->bind_param("i", $session_id);
        $stmt->execute();
        $seq_row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $sequence_number = (int)$seq_row['next_seq'];

        $stmt = $conn->prepare("
            INSERT INTO warehouse_audit_scans (
                session_id, sequence_number, scan_method, raw_value, normalized_pallet_id,
                parsed_wattage, parsed_serial, equipment_code, supplier,
                quantity, expected_wattage_match, is_discrepancy,
                discrepancy_codes, discrepancy_notes, notes,
                ai_confidence, ai_raw_response,
                client_uuid, client_captured_at, verified_by_user_id
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        // Type string breakdown (20 params):
        //   session_id i, sequence_number i, scan_method s, raw_value s, normalized s,
        //   parsed_wattage i, parsed_serial s, equipment_code s, supplier s,
        //   quantity i, expected_wattage_match i, is_discrepancy i,
        //   discrepancy_codes s, discrepancy_notes s, notes s,
        //   ai_confidence d, ai_raw_response s,
        //   client_uuid s, client_captured_at s, verified_by i
        $stmt->bind_param(
            "iisssisssiiisssdsssi",
            $session_id, $sequence_number, $scan_method, $raw_value, $normalized_pallet,
            $parsed_wattage, $parsed_serial, $equipment_code, $supplier,
            $quantity, $expected_wattage_match, $is_discrepancy,
            $discrepancy_codes, $discrepancy_notes, $notes,
            $ai_confidence, $ai_raw_response,
            $client_uuid, $client_captured_at, $actor_user_id
        );
        $stmt->execute();
        $scan_id = (int)$stmt->insert_id;
        $stmt->close();
        audit_write_session_event($conn, $session_id, $actor_user_id, 'scan_added', ['scan_id' => $scan_id, 'client_uuid' => $client_uuid, 'seq' => $sequence_number]);
    }

    // Reload the row for return
    $stmt = $conn->prepare("SELECT * FROM warehouse_audit_scans WHERE id = ?");
    $stmt->bind_param("i", $scan_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row;
}

/**
 * Soft-deletes a scan and cascades through photos in the join table.
 * Scoped by session_id to prevent IDOR: a user with access to one session
 * cannot soft-delete scans belonging to a different session by guessing IDs.
 * Throws RuntimeException if the scan is not found within the session.
 */
function audit_soft_delete_scan($conn, $session_id, $scan_id, $actor_user_id)
{
    $stmt = $conn->prepare("UPDATE warehouse_audit_scans SET is_soft_deleted = 1 WHERE id = ? AND session_id = ?");
    $stmt->bind_param("ii", $scan_id, $session_id);
    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();

    if ($affected === 0) {
        throw new RuntimeException('Scan not found within this session');
    }
    audit_write_session_event($conn, $session_id, $actor_user_id, 'scan_deleted', ['scan_id' => (int)$scan_id]);
}


// ---------------------------------------------------------------------------
// Equipment handling
// ---------------------------------------------------------------------------

/**
 * Creates or updates an equipment check row within a session.
 * If $payload['id'] is present, update; otherwise insert.
 */
function audit_upsert_equipment_check($conn, $session_id, array $payload, $actor_user_id)
{
    $equipment_type    = $payload['equipment_type'] ?? '';
    if ($equipment_type === '') {
        throw new InvalidArgumentException('equipment_type required');
    }
    $manufacturer_name = $payload['manufacturer_name'] ?? null;
    $model_number      = $payload['model_number']      ?? null;
    $serial_number     = $payload['serial_number']     ?? null;
    $asset_tag         = $payload['asset_tag']         ?? null;
    $expected_quantity = isset($payload['expected_quantity']) ? (int)$payload['expected_quantity'] : 1;
    $verified_quantity = isset($payload['verified_quantity']) ? (int)$payload['verified_quantity'] : 0;
    $status            = $payload['status'] ?? 'not_verified';
    $notes             = $payload['notes'] ?? null;

    if (!in_array($status, AUDIT_EQUIPMENT_STATUSES, true)) {
        throw new InvalidArgumentException("Invalid equipment status: $status");
    }

    $verified_at   = ($status !== 'not_verified') ? date('Y-m-d H:i:s') : null;
    $verified_by   = ($status !== 'not_verified') ? (int)$actor_user_id : null;

    if (!empty($payload['id'])) {
        $id = (int)$payload['id'];
        $stmt = $conn->prepare("
            UPDATE warehouse_audit_equipment_checks SET
                equipment_type = ?, manufacturer_name = ?, model_number = ?,
                serial_number = ?, asset_tag = ?, expected_quantity = ?,
                verified_quantity = ?, status = ?, notes = ?,
                verified_by_user_id = ?, verified_at = ?
            WHERE id = ? AND session_id = ?
        ");
        // 13 params: 5 strings, 2 ints, 2 strings, 1 int (nullable), 1 string, then id + session_id (ints)
        $stmt->bind_param(
            "sssssiissisii",
            $equipment_type, $manufacturer_name, $model_number,
            $serial_number, $asset_tag, $expected_quantity,
            $verified_quantity, $status, $notes,
            $verified_by, $verified_at,
            $id, $session_id
        );
        $stmt->execute();
        $stmt->close();
        audit_write_session_event($conn, $session_id, $actor_user_id, 'equipment_checked', ['equipment_id' => $id, 'status' => $status]);
        return $id;
    }

    $stmt = $conn->prepare("
        INSERT INTO warehouse_audit_equipment_checks (
            session_id, equipment_type, manufacturer_name, model_number,
            serial_number, asset_tag, expected_quantity, verified_quantity,
            status, notes, verified_by_user_id, verified_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->bind_param(
        "isssssiissis",
        $session_id, $equipment_type, $manufacturer_name, $model_number,
        $serial_number, $asset_tag, $expected_quantity, $verified_quantity,
        $status, $notes, $verified_by, $verified_at
    );
    $stmt->execute();
    $new_id = (int)$stmt->insert_id;
    $stmt->close();
    audit_write_session_event($conn, $session_id, $actor_user_id, 'equipment_checked', ['equipment_id' => $new_id, 'status' => $status, 'created' => true]);
    return $new_id;
}


// ---------------------------------------------------------------------------
// Reconciliation
// ---------------------------------------------------------------------------

/**
 * Returns a reconciliation summary for the session.
 * Shape:
 *   [
 *     'expected_count' => 1174,
 *     'verified_count' => 1172,
 *     'discrepancy_count' => 2,
 *     'missing_count' => 0,
 *     'total_scans' => 1174,
 *     'by_wattage' => [690 => ['verified' => 400, 'discrepancy' => 1], ...],
 *     'equipment' => [
 *       'expected' => 2, 'verified' => 2, 'missing' => 0, 'damaged' => 0
 *     ]
 *   ]
 */
function audit_build_reconciliation($conn, $session_id)
{
    $session = audit_get_session_by_id($conn, $session_id);
    if (!$session) {
        return null;
    }

    // Totals
    $stmt = $conn->prepare("
        SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN is_discrepancy = 0 THEN 1 ELSE 0 END) AS verified,
            SUM(CASE WHEN is_discrepancy = 1 THEN 1 ELSE 0 END) AS discrepancy
        FROM warehouse_audit_scans
        WHERE session_id = ? AND is_soft_deleted = 0
    ");
    $stmt->bind_param("i", $session_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $total = (int)($row['total'] ?? 0);
    $verified = (int)($row['verified'] ?? 0);
    $discrepancy = (int)($row['discrepancy'] ?? 0);
    $expected = (int)($session['expected_pallet_count'] ?? 0);
    $missing = max(0, $expected - $total);

    // By wattage
    $stmt = $conn->prepare("
        SELECT parsed_wattage,
               SUM(CASE WHEN is_discrepancy = 0 THEN 1 ELSE 0 END) AS verified,
               SUM(CASE WHEN is_discrepancy = 1 THEN 1 ELSE 0 END) AS discrepancy,
               SUM(quantity) AS total_modules
        FROM warehouse_audit_scans
        WHERE session_id = ? AND is_soft_deleted = 0
        GROUP BY parsed_wattage
        ORDER BY parsed_wattage
    ");
    $stmt->bind_param("i", $session_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $by_wattage = [];
    while ($w = $result->fetch_assoc()) {
        $key = $w['parsed_wattage'] !== null ? (int)$w['parsed_wattage'] : 'unknown';
        $by_wattage[$key] = [
            'verified'    => (int)$w['verified'],
            'discrepancy' => (int)$w['discrepancy'],
            'total_modules' => (int)$w['total_modules'],
        ];
    }
    $stmt->close();

    // Equipment rollup
    $stmt = $conn->prepare("
        SELECT status, COUNT(*) AS n, SUM(verified_quantity) AS qty
        FROM warehouse_audit_equipment_checks
        WHERE session_id = ?
        GROUP BY status
    ");
    $stmt->bind_param("i", $session_id);
    $stmt->execute();
    $eqresult = $stmt->get_result();
    $equipment = ['not_verified' => 0, 'verified' => 0, 'missing' => 0, 'damaged' => 0, 'wrong_model' => 0];
    while ($eqrow = $eqresult->fetch_assoc()) {
        $equipment[$eqrow['status']] = (int)$eqrow['n'];
    }
    $stmt->close();

    return [
        'expected_count'    => $expected,
        'verified_count'    => $verified,
        'discrepancy_count' => $discrepancy,
        'missing_count'     => $missing,
        'total_scans'       => $total,
        'by_wattage'        => $by_wattage,
        'equipment'         => $equipment,
    ];
}


// ---------------------------------------------------------------------------
// Commit to inventory
// ---------------------------------------------------------------------------

/**
 * Atomically commits verified (non-discrepancy, non-soft-deleted) scans
 * into inventory_pallets. Branches on project_id presence:
 *   - Project-scoped: uses findOrCreateModuleBatch + findOrCreateModuleItem
 *     from process_pallet_upload.php to wire pallets into the canonical
 *     project inventory structure.
 *   - Warehouse-only: inserts pallets with NULL assigned_project_id and
 *     NULL unassigned_module_item_id (allowed by the 2026-04-13 migration).
 *
 * Skipped scans are flagged with ALREADY_IN_INVENTORY in discrepancy_codes
 * rather than failing the whole commit.
 *
 * Returns a summary array with counts and any skip reasons.
 */
function audit_commit_scans_to_inventory($conn, $session_id, $committing_user_id)
{
    require_once __DIR__ . '/pallet_import_helpers.php';

    $session = audit_get_session_by_id($conn, $session_id);
    if (!$session) {
        throw new RuntimeException('Session not found');
    }
    if (in_array($session['status'], ['committed', 'completed'], true)) {
        throw new RuntimeException('Session already committed');
    }

    $project_id      = $session['project_id'] !== null ? (int)$session['project_id'] : null;
    $account_id      = (int)$session['account_id'];
    $manufacturer_id = $session['manufacturer_id'] !== null ? (int)$session['manufacturer_id'] : null;
    $warehouse_id    = (int)$session['warehouse_id'];

    if ($project_id !== null && $manufacturer_id === null) {
        throw new RuntimeException(
            'Cannot commit: session is project-scoped but has no manufacturer set. ' .
            'Either pick a manufacturer on the session, or clear the project to commit as warehouse-only inventory.'
        );
    }

    $conn->begin_transaction();
    try {
        $pallets_created = 0;
        $pallets_skipped = 0;
        $skip_reasons = [];
        $module_batch_id = null;

        // Resolve manufacturer name for module batch + inventory_pallets.manufacturer column
        $manufacturer_name = '';
        if ($manufacturer_id) {
            $stmt = $conn->prepare("SELECT name FROM manufacturers WHERE id = ? LIMIT 1");
            $stmt->bind_param("i", $manufacturer_id);
            $stmt->execute();
            $mrow = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            $manufacturer_name = $mrow ? (string)$mrow['name'] : '';
        }

        // Load non-discrepancy scans.
        $stmt = $conn->prepare("
            SELECT id, client_uuid, normalized_pallet_id, parsed_wattage, quantity,
                   parsed_serial, discrepancy_codes
            FROM warehouse_audit_scans
            WHERE session_id = ? AND is_soft_deleted = 0 AND is_discrepancy = 0
            ORDER BY sequence_number ASC
        ");
        $stmt->bind_param("i", $session_id);
        $stmt->execute();
        $scans_result = $stmt->get_result();
        $raw_verified_scans = [];
        while ($row = $scans_result->fetch_assoc()) {
            $raw_verified_scans[] = $row;
        }
        $stmt->close();

        // ==============================================================
        // PASS 1: Filter out scans with invalid core fields BEFORE any
        // module_items or module_batch rows are written. This is the
        // critical fix: previously we aggregated quantities_by_wattage
        // from all non-discrepancy scans (including wattage=0 rows that
        // slipped past the classifier when expected_wattages was empty),
        // created module items from that map, and only THEN filtered
        // rows with missing fields. Result: orphaned wattage=0 module
        // items with no pallets referencing them. Now validation happens
        // first, so only valid scans influence module batch state.
        // Skipped scans are tagged with INVALID_CORE_FIELDS on the scan
        // row so they surface in the review UI and final PDF.
        // ==============================================================
        $valid_scans = [];
        foreach ($raw_verified_scans as $scan) {
            $scan_id_v     = (int)$scan['id'];
            $pallet_id_txt = $scan['normalized_pallet_id'] ?: $scan['parsed_serial'];
            $wattage_v     = (int)$scan['parsed_wattage'];
            $quantity_v    = (int)$scan['quantity'];

            if (!$pallet_id_txt || $wattage_v <= 0 || $quantity_v <= 0) {
                // Tag the scan so review page + PDF show why it was skipped
                $existing_codes = $scan['discrepancy_codes'] ? explode(',', $scan['discrepancy_codes']) : [];
                if (!in_array('INVALID_CORE_FIELDS', $existing_codes, true)) {
                    $existing_codes[] = 'INVALID_CORE_FIELDS';
                }
                $new_codes = implode(',', $existing_codes);
                $tag_stmt = $conn->prepare("UPDATE warehouse_audit_scans SET discrepancy_codes = ?, is_discrepancy = 1 WHERE id = ?");
                $tag_stmt->bind_param("si", $new_codes, $scan_id_v);
                $tag_stmt->execute();
                $tag_stmt->close();

                $pallets_skipped++;
                $skip_reasons[] = [
                    'scan_id'  => $scan_id_v,
                    'reason'   => 'invalid_core_fields',
                    'pallet_id' => $pallet_id_txt,
                    'wattage'  => $wattage_v,
                    'quantity' => $quantity_v,
                ];
                continue;
            }
            $valid_scans[] = $scan;
        }

        // PASS 2: Aggregate quantities_by_wattage from VALID scans only.
        $quantities_by_wattage = [];
        foreach ($valid_scans as $scan) {
            $w = (int)$scan['parsed_wattage'];
            if (!isset($quantities_by_wattage[$w])) {
                $quantities_by_wattage[$w] = 0;
            }
            $quantities_by_wattage[$w] += (int)$scan['quantity'];
        }

        // PASS 3: Project-scoped batch + module item creation.
        // Only runs if we have valid scans AND a project+manufacturer.
        $module_item_ids = [];
        if ($project_id !== null && $manufacturer_id !== null && !empty($valid_scans)) {
            $batch_name = 'Audit ' . $session['session_code'];
            $initial_location = 'Warehouse Audit';
            $logistics = [];
            if (!empty($session['expected_modules_per_pallet'])) {
                $logistics['modules_per_pallet'] = (int)$session['expected_modules_per_pallet'];
            }
            $module_batch_id = audit_find_or_create_module_batch(
                $conn, $account_id, $project_id, $manufacturer_id, $manufacturer_name,
                $initial_location, $logistics, $batch_name
            );

            foreach ($quantities_by_wattage as $wattage => $qty) {
                $module_item_ids[$wattage] = audit_find_or_create_module_item($conn, $module_batch_id, (int)$wattage, (int)$qty);
            }
        }

        $now = date('Y-m-d H:i:s');

        // PASS 4: Insert pallets (iterating only over valid scans now).
        foreach ($valid_scans as $scan) {
            $scan_id       = (int)$scan['id'];
            $normalized    = $scan['normalized_pallet_id'];
            $pallet_id_txt = $normalized ?: $scan['parsed_serial'];
            $wattage       = (int)$scan['parsed_wattage'];
            $quantity      = (int)$scan['quantity'];

            // Dedup: project-scoped or warehouse-scoped
            if ($project_id !== null) {
                $dup_stmt = $conn->prepare("
                    SELECT id FROM inventory_pallets
                    WHERE assigned_project_id = ?
                      AND (manufacturer_pallet_id = ? OR (manufacturer_pallet_id IS NULL AND pallet_identifier = ?))
                    LIMIT 1
                ");
                $dup_stmt->bind_param("iss", $project_id, $pallet_id_txt, $pallet_id_txt);
            } else {
                $dup_stmt = $conn->prepare("
                    SELECT id FROM inventory_pallets
                    WHERE current_warehouse_id = ?
                      AND (manufacturer_pallet_id = ? OR (manufacturer_pallet_id IS NULL AND pallet_identifier = ?))
                    LIMIT 1
                ");
                $dup_stmt->bind_param("iss", $warehouse_id, $pallet_id_txt, $pallet_id_txt);
            }
            $dup_stmt->execute();
            $existing = $dup_stmt->get_result()->fetch_assoc();
            $dup_stmt->close();

            if ($existing) {
                // Mark the scan with ALREADY_IN_INVENTORY but don't fail commit
                $codes = $scan['discrepancy_codes'] ? explode(',', $scan['discrepancy_codes']) : [];
                if (!in_array('ALREADY_IN_INVENTORY', $codes, true)) {
                    $codes[] = 'ALREADY_IN_INVENTORY';
                }
                $new_codes = implode(',', $codes);
                $mark_stmt = $conn->prepare("UPDATE warehouse_audit_scans SET discrepancy_codes = ?, is_discrepancy = 1 WHERE id = ?");
                $mark_stmt->bind_param("si", $new_codes, $scan_id);
                $mark_stmt->execute();
                $mark_stmt->close();
                $pallets_skipped++;
                $skip_reasons[] = ['scan_id' => $scan_id, 'reason' => 'already_in_inventory', 'existing_pallet_id' => (int)$existing['id']];
                continue;
            }

            // Insert new pallet row
            $status_val = 'In Warehouse';
            $module_item_id = ($project_id !== null && isset($module_item_ids[$wattage])) ? $module_item_ids[$wattage] : null;

            if ($module_item_id !== null) {
                $ins_stmt = $conn->prepare("
                    INSERT INTO inventory_pallets (
                        pallet_identifier, manufacturer_pallet_id, unassigned_module_item_id,
                        wattage, quantity, status, manufacturer, assigned_project_id,
                        current_warehouse_id, arrival_date, created_from_audit_scan_id
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                // 11 params: pallet_id s, mfg_pallet_id s, module_item_id i,
                //            wattage i, quantity i, status s, manufacturer s,
                //            project_id i, warehouse_id i, arrival_date s, audit_scan_id i
                $ins_stmt->bind_param(
                    "ssiiissiisi",
                    $pallet_id_txt, $pallet_id_txt, $module_item_id,
                    $wattage, $quantity, $status_val, $manufacturer_name,
                    $project_id, $warehouse_id, $now, $scan_id
                );
            } else {
                // Warehouse-only path: null module_item_id, null project_id
                $ins_stmt = $conn->prepare("
                    INSERT INTO inventory_pallets (
                        pallet_identifier, manufacturer_pallet_id, unassigned_module_item_id,
                        wattage, quantity, status, manufacturer, assigned_project_id,
                        current_warehouse_id, arrival_date, created_from_audit_scan_id
                    ) VALUES (?, ?, NULL, ?, ?, ?, ?, NULL, ?, ?, ?)
                ");
                // 9 params: pallet_id s, mfg_pallet_id s,
                //           wattage i, quantity i, status s, manufacturer s,
                //           warehouse_id i, arrival_date s, audit_scan_id i
                $ins_stmt->bind_param(
                    "ssiissisi",
                    $pallet_id_txt, $pallet_id_txt,
                    $wattage, $quantity, $status_val, $manufacturer_name,
                    $warehouse_id, $now, $scan_id
                );
            }
            $ins_stmt->execute();
            $new_pallet_id = (int)$ins_stmt->insert_id;
            $ins_stmt->close();

            // Link the scan back to the new pallet
            $link_stmt = $conn->prepare("UPDATE warehouse_audit_scans SET inventory_pallet_id = ? WHERE id = ?");
            $link_stmt->bind_param("ii", $new_pallet_id, $scan_id);
            $link_stmt->execute();
            $link_stmt->close();

            $pallets_created++;
        }

        // Update session
        $upd = $conn->prepare("
            UPDATE warehouse_audit_sessions SET
                status = 'committed',
                module_batch_id = ?,
                committed_to_inventory_at = ?,
                committed_by_user_id = ?
            WHERE id = ?
        ");
        $upd->bind_param("isii", $module_batch_id, $now, $committing_user_id, $session_id);
        $upd->execute();
        $upd->close();

        audit_write_session_event(
            $conn, $session_id, $committing_user_id, 'committed_to_inventory',
            [
                'pallets_created' => $pallets_created,
                'pallets_skipped' => $pallets_skipped,
                'module_batch_id' => $module_batch_id,
                'project_scoped'  => ($project_id !== null),
            ]
        );

        $conn->commit();

        return [
            'success'         => true,
            'pallets_created' => $pallets_created,
            'pallets_skipped' => $pallets_skipped,
            'skip_reasons'    => $skip_reasons,
            'module_batch_id' => $module_batch_id,
        ];
    } catch (Throwable $e) {
        $conn->rollback();
        audit_write_session_event($conn, $session_id, $committing_user_id, 'commit_failed', ['error' => $e->getMessage()]);
        throw $e;
    }
}


// ---------------------------------------------------------------------------
// Gemini integration (tier 2 AI vision label reader)
// ---------------------------------------------------------------------------

/**
 * Loads Sunny's Gemini config. Throws if missing so the caller can surface
 * a clear "AI vision unavailable" error to the client without crashing.
 */
function audit_load_sunny_config()
{
    $path = __DIR__ . '/ai-assistant/config/sunny-config.php';
    if (!file_exists($path)) {
        throw new RuntimeException('Sunny config not found at ' . $path);
    }
    $config = require $path;
    if (!is_array($config) || empty($config['gemini']['api_key'])) {
        throw new RuntimeException('Gemini API key not configured in Sunny config');
    }
    return $config;
}

/**
 * Calls Gemini with a pallet label image and returns the parsed extraction.
 *
 * @param string $image_bytes Raw binary image data (any common image mime)
 * @param string $mime_type   e.g. "image/jpeg"
 * @param string $model       Gemini model ID (default gemini-2.5-flash)
 * @return array {
 *     'success' => bool,
 *     'fields' => ['pallet_id','wattage','modules_per_pallet','supplier','equipment_type','confidence'],
 *     'raw_response' => array,
 *     'usage' => ['input_tokens','output_tokens','cost_usd'],
 *     'error' => string|null,
 * }
 */
function audit_call_gemini_label_parser($image_bytes, $mime_type, $model = 'gemini-2.5-flash')
{
    $config = audit_load_sunny_config();
    $api_key  = $config['gemini']['api_key'];
    $base_url = rtrim($config['gemini']['base_url'], '/');
    $costs    = $config['model_costs'][$model] ?? null;

    $url = $base_url . '/models/' . rawurlencode($model) . ':generateContent?key=' . rawurlencode($api_key);

    $payload = [
        'contents' => [[
            'role' => 'user',
            'parts' => [
                ['text' => GEMINI_LABEL_EXTRACTION_PROMPT],
                ['inline_data' => [
                    'mime_type' => $mime_type,
                    'data'      => base64_encode($image_bytes),
                ]],
            ],
        ]],
        'generationConfig' => [
            'responseMimeType' => 'application/json',
            'responseSchema' => [
                'type' => 'object',
                'properties' => [
                    'pallet_id'          => ['type' => 'string', 'nullable' => true],
                    'wattage'            => ['type' => 'integer', 'nullable' => true],
                    'modules_per_pallet' => ['type' => 'integer', 'nullable' => true],
                    'supplier'           => ['type' => 'string', 'nullable' => true],
                    'equipment_type'     => ['type' => 'string', 'nullable' => true],
                    'confidence'         => ['type' => 'number'],
                ],
                'required' => ['confidence'],
            ],
            'temperature' => 0.0,
            'maxOutputTokens' => 400,
        ],
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $response_body = curl_exec($ch);
    $http_code     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error    = curl_error($ch);
    curl_close($ch);

    if ($response_body === false || $http_code >= 400) {
        return [
            'success' => false,
            'fields'  => null,
            'raw_response' => null,
            'usage'   => null,
            'error'   => $curl_error ?: ("HTTP " . $http_code . ": " . substr((string)$response_body, 0, 400)),
        ];
    }

    $decoded = json_decode($response_body, true);
    if (!is_array($decoded)) {
        return [
            'success' => false,
            'fields'  => null,
            'raw_response' => null,
            'usage'   => null,
            'error'   => 'Failed to decode Gemini response',
        ];
    }

    // Extract the text part from candidates[0].content.parts[0].text
    $fields = null;
    $text   = $decoded['candidates'][0]['content']['parts'][0]['text'] ?? null;
    if ($text) {
        $parsed = json_decode($text, true);
        if (is_array($parsed)) {
            $fields = [
                'pallet_id'          => $parsed['pallet_id']          ?? null,
                'wattage'            => isset($parsed['wattage']) ? (int)$parsed['wattage'] : null,
                'modules_per_pallet' => isset($parsed['modules_per_pallet']) ? (int)$parsed['modules_per_pallet'] : null,
                'supplier'           => $parsed['supplier']           ?? null,
                'equipment_type'     => $parsed['equipment_type']     ?? null,
                'confidence'         => isset($parsed['confidence']) ? (float)$parsed['confidence'] : 0.0,
            ];
        }
    }

    if ($fields === null) {
        return [
            'success' => false,
            'fields'  => null,
            'raw_response' => $decoded,
            'usage'   => null,
            'error'   => 'Gemini response missing extraction JSON',
        ];
    }

    // Usage + cost estimate
    $usage = $decoded['usageMetadata'] ?? [];
    $input_tokens  = (int)($usage['promptTokenCount']     ?? 0);
    $output_tokens = (int)($usage['candidatesTokenCount'] ?? 0);
    $cost_usd = 0.0;
    if ($costs) {
        $cost_usd = ($input_tokens / 1000000) * ($costs['input_per_million']  ?? 0)
                  + ($output_tokens / 1000000) * ($costs['output_per_million'] ?? 0);
    }

    return [
        'success' => true,
        'fields'  => $fields,
        'raw_response' => $decoded,
        'usage'   => [
            'input_tokens'  => $input_tokens,
            'output_tokens' => $output_tokens,
            'cost_usd'      => round($cost_usd, 6),
        ],
        'error'   => null,
    ];
}

/**
 * Atomically reserves an AI vision call slot on the session.
 *
 * This replaces a previous check-then-increment pattern that had a TOCTOU
 * race: two concurrent requests could both pass the pre-check and push the
 * counter past the cap, overrunning the session's cost budget.
 *
 * The UPDATE conditionally increments the counter only if the session
 * still has budget AND AI vision is enabled. MySQL guarantees the row-level
 * lock on UPDATE, so concurrent callers serialize. If affected_rows == 0
 * the caller either exceeded the cap or AI vision was disabled and should
 * return 429 without calling Gemini.
 *
 * On success, the counter is now permanently incremented. Failed Gemini
 * calls still "burn" the slot — this is intentional so that flaky networks
 * can't be used to slowly drain budget undetected, and matches how cloud
 * APIs often count attempted calls against rate limits.
 *
 * Returns true if a slot was reserved, false if the cap was reached or
 * AI vision is disabled for the session.
 */
function audit_reserve_ai_vision_slot($conn, $session_id)
{
    $stmt = $conn->prepare("
        UPDATE warehouse_audit_sessions
        SET ai_vision_call_count = ai_vision_call_count + 1
        WHERE id = ?
          AND ai_vision_enabled = 1
          AND ai_vision_call_count < ai_vision_call_cap
    ");
    $stmt->bind_param("i", $session_id);
    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();
    return $affected > 0;
}

/**
 * Records the cost of a successful Gemini call. The call count was already
 * incremented by audit_reserve_ai_vision_slot; this only updates the
 * running cost estimate column.
 */
function audit_record_ai_vision_cost($conn, $session_id, $cost_usd)
{
    $stmt = $conn->prepare("
        UPDATE warehouse_audit_sessions
        SET ai_vision_cost_estimate_usd = ai_vision_cost_estimate_usd + ?
        WHERE id = ?
    ");
    $stmt->bind_param("di", $cost_usd, $session_id);
    $stmt->execute();
    $stmt->close();
}


// ---------------------------------------------------------------------------
// Offline queue diagnostic log
// ---------------------------------------------------------------------------

function audit_log_offline_queue_attempt($conn, $session_id, $client_uuid, $result, $retry_count = 0, $detail = null, $client_captured_at = null)
{
    try {
        $ip = $_SERVER['REMOTE_ADDR']    ?? null;
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? null;
        if ($ua !== null && strlen($ua) > 255) {
            $ua = substr($ua, 0, 255);
        }
        $stmt = $conn->prepare("
            INSERT INTO warehouse_audit_offline_queue_log
                (session_id, client_uuid, retry_count, client_captured_at, ip_address, user_agent, result, result_detail)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->bind_param(
            "isisssss",
            $session_id, $client_uuid, $retry_count, $client_captured_at, $ip, $ua, $result, $detail
        );
        $stmt->execute();
        $stmt->close();
    } catch (Throwable $e) {
        error_log("audit_log_offline_queue_attempt failed: " . $e->getMessage());
    }
}


// ---------------------------------------------------------------------------
// API endpoint support: JSON helpers + bootstrapping
// ---------------------------------------------------------------------------

/**
 * Emits a JSON success response and exits.
 */
function audit_json_ok($data = [])
{
    header('Content-Type: application/json');
    echo json_encode(array_merge(['success' => true], $data));
    exit;
}

/**
 * Emits a JSON error response with HTTP status and exits.
 */
function audit_json_error($message, $http_status = 400, $extra = [])
{
    http_response_code($http_status);
    header('Content-Type: application/json');
    echo json_encode(array_merge(['success' => false, 'error' => $message], $extra));
    exit;
}

/**
 * Admin-gate boot for an API endpoint. Starts session, validates login +
 * role, returns [$user_id, $role]. Exits on failure.
 */
function audit_require_admin_boot()
{
    if (session_status() === PHP_SESSION_NONE) {
        session_name("logistics_session");
        session_start();
    }
    if (!isset($_SESSION['user_id'])) {
        audit_json_error('Not authenticated', 401);
    }
    $role = $_SESSION['role'] ?? 'user';
    if (!in_array($role, ['admin', 'global_admin', 'customer_admin'], true)) {
        audit_json_error('Admin role required', 403);
    }
    return [(int)$_SESSION['user_id'], $role];
}

/**
 * Validates the request CSRF token against $_SESSION['csrf_token'].
 * Skips validation for GET requests (idempotent by convention).
 */
function audit_require_csrf()
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
        return;
    }
    $expected = $_SESSION['csrf_token'] ?? null;
    $provided = $_POST['csrf_token']
        ?? $_SERVER['HTTP_X_CSRF_TOKEN']
        ?? null;
    if (!$expected || !$provided || !hash_equals($expected, $provided)) {
        audit_json_error('Invalid CSRF token', 403);
    }
}
