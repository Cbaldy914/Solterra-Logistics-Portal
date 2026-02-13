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
    // Legacy full set; kept for compatibility
    return [
        'Submitted',
        'In Review',
        'Pending Manufacturer',
        'Pending EPC',
        'Pending Carrier',
        'Approved - Credit',
        'Approved - Replacement',
        'Replacement Shipped',
        'Closed',
        'Rejected',
    ];
}

// UI path with labels (collapses approvals under one step and picks Pending by responsible)
function warrantyUiPath(string $responsibleParty): array {
    // Default/legacy path retained for compatibility
    $pending = 'Pending ' . ($responsibleParty ?: 'Manufacturer');
    return ['Submitted','In Review',$pending,'Approved/Rejected','Closed'];
}

function warrantyUiPathAdvanced(string $responsibleParty, string $status, ?string $resolutionType, array $replacementStatusTotals = []): array {
    $pending = 'Pending ' . ($responsibleParty ?: 'Manufacturer');

    // Before decision
    if (in_array($status, ['Submitted','Draft','In Review','Pending Manufacturer','Pending EPC','Pending Carrier'], true)) {
        return ['Submitted','In Review',$pending,'Approved/Rejected','Closed'];
    }

    // Rejected path
    if ($status === 'Rejected') {
        return ['Submitted','In Review',$pending,'Rejected','Closed'];
    }

    // Approved path -> include resolution step
    if ($status === 'Approved - Credit' || $status === 'Approved - Replacement' || $status === 'Replacement Shipped' || $status === 'Closed') {
        $resLabel = $resolutionType ?: 'Resolution';
        
        // For replacement path, show current replacement status
        if ($resolutionType === 'Replacement' && !empty($replacementStatusTotals)) {
            $currentReplacementStatus = getPredominantReplacementStatus($replacementStatusTotals);
            if ($currentReplacementStatus) {
                $resLabel = 'Replacement - ' . $currentReplacementStatus;
                
                // If replacement is delivered to project, show "Closed" as final step for better UX
                if ($currentReplacementStatus === 'Delivered to Project') {
                    return ['Submitted','In Review',$pending,'Approved',$resLabel,'Closed'];
                }
            } else {
                $resLabel = 'Replacement';
            }
        } elseif ($status === 'Replacement Shipped') { 
            $resLabel = 'Replacement Shipped'; 
        }
        
        return ['Submitted','In Review',$pending,'Approved',$resLabel,'Closed'];
    }

    // Fallback to default
    return ['Submitted','In Review',$pending,'Approved/Rejected','Closed'];
}

function uiIndexForStatus(string $status, string $responsibleParty, ?string $resolutionType, array $replacementStatusTotals = []): int {
    $path = warrantyUiPathAdvanced($responsibleParty, $status, $resolutionType, $replacementStatusTotals);
    // Map known statuses to index in advanced path
    $pending = 'Pending ' . ($responsibleParty ?: 'Manufacturer');
    
    // Special case: if replacement is delivered to project, mark replacement step as complete
    $replacementDelivered = false;
    if ($resolutionType === 'Replacement' && !empty($replacementStatusTotals)) {
        $currentReplacementStatus = getPredominantReplacementStatus($replacementStatusTotals);
        $replacementDelivered = ($currentReplacementStatus === 'Delivered to Project');
    }
    
    $map = [
        'Submitted' => 0,
        'In Review' => 1,
        'Pending Manufacturer' => 2,
        'Pending EPC' => 2,
        'Pending Carrier' => 2,
        'Approved - Credit' => 3,
        'Approved - Replacement' => $replacementDelivered ? 4 : 3, // If delivered, show as completed step
        'Replacement Shipped' => 4,
        'Rejected' => 3, // in rejected path, index 3 maps to 'Rejected'
        'Closed' => array_key_exists(5, $path) ? 5 : 4,
    ];
    if (isset($map[$status])) {
        $idx = $map[$status];
        // Ensure it doesn't exceed path length
        return min($idx, max(0, count($path)-1));
    }
    return 0;
}

function warrantyValidTransitions(): array {
    return [
        'Draft' => ['Submitted'], // in case legacy rows exist
        'Submitted' => ['In Review', 'Rejected'],
        'In Review' => ['Pending Manufacturer', 'Pending EPC', 'Pending Carrier', 'Rejected'],
        'Pending Manufacturer' => ['Approved - Credit', 'Approved - Replacement', 'Rejected'],
        'Pending EPC' => ['Approved - Credit', 'Approved - Replacement', 'Rejected'],
        'Pending Carrier' => ['Approved - Credit', 'Approved - Replacement', 'Rejected'],
        'Approved - Credit' => ['Closed'],
        'Approved - Replacement' => ['Replacement Shipped', 'Closed'], // Allow direct closure when replacement delivered
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
    return in_array($role, ['admin', 'global_admin', 'customer_admin'], true);
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

/**
 * Get the predominant status of replacement pallets for timeline display
 */
function getPredominantReplacementStatus(array $replacementStatusTotals): ?string {
    if (empty($replacementStatusTotals)) {
        return null;
    }
    
    // Priority order for status display (most important first)
    $statusPriority = [
        'Delivered to Project',
        'In Transit to Project', 
        'In Warehouse',
        'In Transit to Warehouse',
        'Cleared Customs',
        'On Water',
        'At Manufacturer'
    ];
    
    // Find the most significant status with pallets
    foreach ($statusPriority as $status) {
        if (isset($replacementStatusTotals[$status]) && ($replacementStatusTotals[$status]['pallets'] ?? 0) > 0) {
            return $status;
        }
    }
    
    return null;
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
    $allowedMime = [
        'pdf'  => 'application/pdf',
        'png'  => 'image/png',
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'webp' => 'image/webp',
    ];
    $maxBytes = 12 * 1024 * 1024; // 12 MB per file
    $count = count($files['name']);
    for ($i=0; $i<$count; $i++) {
        if ($files['error'][$i] !== UPLOAD_ERR_OK) continue;
        if (!is_uploaded_file($files['tmp_name'][$i])) continue;
        if (filesize($files['tmp_name'][$i]) > $maxBytes) continue;
        $name = $files['name'][$i];
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($ext, $allowed, true)) continue;
        // MIME sniffing
        $finfo = function_exists('finfo_open') ? finfo_open(FILEINFO_MIME_TYPE) : false;
        if ($finfo) {
            $mime = finfo_file($finfo, $files['tmp_name'][$i]);
            finfo_close($finfo);
            // allow jpeg variations
            if ($ext === 'jpg' || $ext === 'jpeg') {
                if (strpos((string)$mime, 'image/jpeg') !== 0) continue;
            } else {
                if (($allowedMime[$ext] ?? '') !== $mime) continue;
            }
        }
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
    // Track removed files from pictures
    $beforePics = jsonToArray($before['pictures'] ?? '');
    $afterPics = jsonToArray($after['pictures'] ?? '');
    $removed = array_values(array_diff($beforePics, $afterPics));
    if (!empty($removed)) {
        $changes['uploads_removed'] = $removed;
    }
    return $changes; // empty => no public changes
}

function summarizePublicChanges(mysqli $conn, array $changes): string {
    $parts = [];
    if (isset($changes['status'])) {
        $parts[] = 'Status changed: ' . ($changes['status']['from'] ?? '—') . ' to ' . ($changes['status']['to'] ?? '—');
    }
    if (isset($changes['responsible_party'])) {
        $parts[] = 'Responsible Party Changed: ' . ($changes['responsible_party']['from'] ?? '—') . ' to ' . ($changes['responsible_party']['to'] ?? '—');
    }
    if (isset($changes['resolution_type'])) {
        $parts[] = 'Resolution Changed: ' . ($changes['resolution_type']['from'] ?? '—') . ' to ' . ($changes['resolution_type']['to'] ?? '—');
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
    if (isset($changes['uploads_removed']) && !empty($changes['uploads_removed'])) {
        $files = array_map(function($p){ return basename($p); }, $changes['uploads_removed']);
        $parts[] = 'Files removed: ' . implode(', ', $files);
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


