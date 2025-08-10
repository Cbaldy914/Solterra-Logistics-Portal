<?php
// Shared helpers for Warranty Claims workflow

if (session_status() === PHP_SESSION_NONE) {
    session_name('logistics_session');
    session_start();
}

require_once __DIR__ . '/bootstrap.php';

// Feature flags
const SHOW_CREDIT_TO_CUSTOMERS = true;

// Status path and transitions
function warrantyStatusPath(): array {
    return [
        'Submitted',
        'In Review',
        'Pending Manufacturer',
        'Approved - Credit',
        'Approved - Replacement',
        'Replacement Shipped',
        'Closed',
        'Rejected',
    ];
}

function warrantyValidTransitions(): array {
    return [
        'Draft' => ['Submitted'], // in case legacy rows exist
        'Submitted' => ['In Review', 'Rejected'],
        'In Review' => ['Pending Manufacturer', 'Rejected'],
        'Pending Manufacturer' => ['Approved - Credit', 'Approved - Replacement', 'Rejected'],
        'Approved - Credit' => ['Closed'],
        'Approved - Replacement' => ['Replacement Shipped'],
        'Replacement Shipped' => ['Closed'],
        'Closed' => [],
        'Rejected' => [],
    ];
}

function isValidWarrantyTransition(string $from, string $to): bool {
    $map = warrantyValidTransitions();
    if (!isset($map[$from])) {
        return false;
    }
    return in_array($to, $map[$from], true);
}

function isAdminRole(): bool {
    $role = $_SESSION['role'] ?? 'user';
    return in_array($role, ['admin', 'global_admin'], true);
}

function getAllowedProjectIds(mysqli $conn, int $userId, string $role): ?array {
    if ($role === 'global_admin') {
        return null; // null means all projects
    }
    // For admin/user: projects where user's account matches project's account
    $sql = "SELECT p.id
            FROM projects p
            WHERE p.account_id IN (
                SELECT cau.account_id FROM customer_account_users cau WHERE cau.user_id = ?
            )";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $res = $stmt->get_result();
    $ids = [];
    while ($row = $res->fetch_assoc()) {
        $ids[] = (int)$row['id'];
    }
    $stmt->close();
    return $ids;
}

function getClaimProjectId(mysqli $conn, int $claimId): ?int {
    $sql = "SELECT ss.project_id
            FROM warranty_claims w
            JOIN site_scheduling ss ON ss.id = w.scheduling_id
            WHERE w.id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $claimId);
    $stmt->execute();
    $stmt->bind_result($projectId);
    $projectId = null;
    if ($stmt->fetch()) {
        $stmt->close();
        return (int)$projectId;
    }
    $stmt->close();
    return null;
}

function getPalletIdentifiers(mysqli $conn, array $palletIds): array {
    if (empty($palletIds)) return [];
    $placeholders = implode(',', array_fill(0, count($palletIds), '?'));
    $types = str_repeat('i', count($palletIds));
    $sql = "SELECT id, COALESCE(pallet_identifier, CONCAT('ID ', id)) AS label FROM inventory_pallets WHERE id IN ($placeholders)";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$palletIds);
    $stmt->execute();
    $res = $stmt->get_result();
    $map = [];
    while ($row = $res->fetch_assoc()) {
        $map[(int)$row['id']] = $row['label'];
    }
    $stmt->close();
    return $map;
}

function ensureWarrantyUploadDir(int $claimId): string {
    $base = __DIR__ . '/uploads/warranty/' . $claimId;
    if (!is_dir($base)) {
        @mkdir($base, 0775, true);
    }
    return $base;
}

function storeUploadedFiles(array $files, int $claimId): array {
    $stored = [];
    if (!isset($files['name']) || !is_array($files['name'])) return $stored;
    $dir = ensureWarrantyUploadDir($claimId);
    $allowed = ['pdf','png','jpg','jpeg','webp'];
    $count = count($files['name']);
    for ($i=0; $i<$count; $i++) {
        if ($files['error'][$i] !== UPLOAD_ERR_OK) continue;
        $name = $files['name'][$i];
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($ext, $allowed, true)) continue;
        $safe = preg_replace('/[^a-zA-Z0-9-_\.]/', '_', basename($name));
        $target = $dir . '/' . time() . '_' . $safe;
        if (@move_uploaded_file($files['tmp_name'][$i], $target)) {
            // Web path relative to project root
            $webPath = 'uploads/warranty/' . $claimId . '/' . basename($target);
            $stored[] = $webPath;
        }
    }
    return $stored;
}

function loadClaimRow(mysqli $conn, int $claimId): ?array {
    $sql = "SELECT * FROM warranty_claims WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $claimId);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function jsonToArray(?string $json): array {
    if (empty($json)) return [];
    $arr = json_decode($json, true);
    return is_array($arr) ? $arr : [];
}

function arrayToJson(array $arr): string {
    return json_encode(array_values($arr), JSON_UNESCAPED_SLASHES);
}

// Determine public changes given prior and new state
function detectPublicChanges(array $before, array $after, array $linkedPalletIdsBefore, array $linkedPalletIdsAfter, array $newUploads): array {
    $changes = [];
    // Public fields list
    $fields = [
        'status',
        'responsible_party',
        'resolution_type',
        'credit_amount',
        'replacement_tracking',
        'proof_of_completion_path',
        'pictures'
    ];
    foreach ($fields as $f) {
        $b = $before[$f] ?? null;
        $a = $after[$f] ?? null;
        if ($f === 'credit_amount') {
            $b = $b === null ? null : (float)$b;
            $a = $a === null ? null : (float)$a;
        }
        if ($b != $a) {
            $changes[$f] = ['from' => $b, 'to' => $a];
        }
    }
    // Linked replacement pallets change
    sort($linkedPalletIdsBefore);
    sort($linkedPalletIdsAfter);
    if ($linkedPalletIdsBefore !== $linkedPalletIdsAfter) {
        $changes['linked_replacements'] = [
            'from' => $linkedPalletIdsBefore,
            'to' => $linkedPalletIdsAfter,
        ];
    }
    if (!empty($newUploads)) {
        $changes['uploads_added'] = $newUploads;
    }
    return $changes; // empty => no public changes
}

function summarizePublicChanges(mysqli $conn, array $changes): string {
    $parts = [];
    if (isset($changes['status'])) {
        $parts[] = 'Status changed: ' . ($changes['status']['from'] ?? '—') . ' → ' . ($changes['status']['to'] ?? '—');
    }
    if (isset($changes['responsible_party'])) {
        $parts[] = 'Responsible: ' . ($changes['responsible_party']['from'] ?? '—') . ' → ' . ($changes['responsible_party']['to'] ?? '—');
    }
    if (isset($changes['resolution_type'])) {
        $parts[] = 'Resolution: ' . ($changes['resolution_type']['from'] ?? '—') . ' → ' . ($changes['resolution_type']['to'] ?? '—');
    }
    if (isset($changes['credit_amount'])) {
        $to = $changes['credit_amount']['to'];
        $parts[] = 'Credit amount updated: ' . (is_null($to) ? '—' : ('$' . number_format((float)$to, 2)));
    }
    if (isset($changes['replacement_tracking'])) {
        $parts[] = 'Replacement tracking updated';
    }
    if (isset($changes['linked_replacements'])) {
        $ids = $changes['linked_replacements']['to'] ?? [];
        $labels = array_values(getPalletIdentifiers($conn, $ids));
        if (!empty($labels)) {
            $parts[] = 'Linked replacements: ' . implode(', ', $labels);
        }
    }
    if (isset($changes['proof_of_completion_path'])) {
        $parts[] = 'Proof uploaded';
    }
    if (isset($changes['uploads_added']) && !empty($changes['uploads_added'])) {
        $files = array_map(function($p){ return basename($p); }, $changes['uploads_added']);
        $parts[] = 'Files added: ' . implode(', ', $files);
    }
    return empty($parts) ? 'Update' : implode(' | ', $parts);
}

function listLinkedReplacementPalletIds(mysqli $conn, int $claimId): array {
    $sql = "SELECT pallet_id FROM warranty_claim_replacements WHERE claim_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $claimId);
    $stmt->execute();
    $res = $stmt->get_result();
    $ids = [];
    while ($row = $res->fetch_assoc()) $ids[] = (int)$row['pallet_id'];
    $stmt->close();
    return $ids;
}

function insertEvent(mysqli $conn, int $claimId, int $userId, string $text, int $isPublic = 1): void {
    $sql = "INSERT INTO warranty_claim_events (claim_id, user_id, event_text, is_public) VALUES (?, ?, ?, ?)";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('iisi', $claimId, $userId, $text, $isPublic);
    $stmt->execute();
    $stmt->close();
}

function setLastPublicUpdateNow(mysqli $conn, int $claimId): void {
    $sql = "UPDATE warranty_claims SET last_public_update_at = NOW() WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $claimId);
    $stmt->execute();
    $stmt->close();
}

?>


