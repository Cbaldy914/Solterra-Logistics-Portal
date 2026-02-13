<?php

if (!function_exists('mr_audit_table_exists')) {
    function mr_audit_table_exists($conn) {
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }

        $sql = "
            SELECT COUNT(*) AS c
            FROM INFORMATION_SCHEMA.TABLES
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'module_batch_reconciliation_audit'
        ";
        $res = $conn->query($sql);
        if (!$res) {
            $cached = false;
            return false;
        }
        $row = $res->fetch_assoc();
        $cached = ((int)($row['c'] ?? 0) > 0);
        return $cached;
    }
}

if (!function_exists('mr_get_module_batch_meta')) {
    function mr_get_module_batch_meta($conn, $batchId) {
        $batchId = (int)$batchId;
        if ($batchId <= 0) {
            return null;
        }

        $stmt = $conn->prepare("
            SELECT id, account_id, project_id, batch_name, vendor_name, initial_location
            FROM modules
            WHERE id = ?
            LIMIT 1
        ");
        if (!$stmt) {
            throw new Exception('Failed preparing module batch meta query: ' . $conn->error);
        }
        $stmt->bind_param('i', $batchId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row) {
            return null;
        }

        return [
            'id' => (int)($row['id'] ?? 0),
            'account_id' => (int)($row['account_id'] ?? 0),
            'project_id' => (int)($row['project_id'] ?? 0),
            'batch_name' => (string)($row['batch_name'] ?? ''),
            'vendor_name' => (string)($row['vendor_name'] ?? ''),
            'initial_location' => (string)($row['initial_location'] ?? '')
        ];
    }
}

if (!function_exists('mr_get_module_batch_rows')) {
    function mr_get_module_batch_rows($conn, $batchId) {
        $batchId = (int)$batchId;
        if ($batchId <= 0) {
            return [];
        }

        $stmt = $conn->prepare("
            SELECT
                umi.id AS item_id,
                umi.wattage,
                umi.quantity,
                umi.domestic_content_pct,
                COALESCE(ps.pallet_count, 0) AS pallet_count,
                COALESCE(ps.pallet_modules, 0) AS pallet_modules,
                COALESCE(ps.locked_pallet_count, 0) AS locked_pallet_count,
                COALESCE(ps.locked_modules, 0) AS locked_modules
            FROM unassigned_module_items umi
            LEFT JOIN (
                SELECT
                    ip.unassigned_module_item_id,
                    COUNT(*) AS pallet_count,
                    COALESCE(SUM(ip.quantity), 0) AS pallet_modules,
                    SUM(
                        CASE
                            WHEN EXISTS (
                                SELECT 1
                                FROM delivery_pallets dp
                                WHERE dp.inventory_pallet_id = ip.id
                            ) OR EXISTS (
                                SELECT 1
                                FROM warranty_claim_replacements wcr
                                WHERE wcr.pallet_id = ip.id
                            )
                            THEN 1 ELSE 0
                        END
                    ) AS locked_pallet_count,
                    COALESCE(SUM(
                        CASE
                            WHEN EXISTS (
                                SELECT 1
                                FROM delivery_pallets dp
                                WHERE dp.inventory_pallet_id = ip.id
                            ) OR EXISTS (
                                SELECT 1
                                FROM warranty_claim_replacements wcr
                                WHERE wcr.pallet_id = ip.id
                            )
                            THEN ip.quantity ELSE 0
                        END
                    ), 0) AS locked_modules
                FROM inventory_pallets ip
                JOIN unassigned_module_items umi_filter
                    ON umi_filter.id = ip.unassigned_module_item_id
                WHERE umi_filter.unassigned_module_id = ?
                GROUP BY ip.unassigned_module_item_id
            ) ps ON ps.unassigned_module_item_id = umi.id
            WHERE umi.unassigned_module_id = ?
            ORDER BY umi.wattage ASC, umi.id ASC
        ");
        if (!$stmt) {
            throw new Exception('Failed preparing module batch row snapshot query: ' . $conn->error);
        }
        $stmt->bind_param('ii', $batchId, $batchId);
        $stmt->execute();
        $res = $stmt->get_result();

        $rows = [];
        while ($row = $res->fetch_assoc()) {
            $rows[] = [
                'item_id' => (int)($row['item_id'] ?? 0),
                'wattage' => (int)($row['wattage'] ?? 0),
                'quantity' => (int)($row['quantity'] ?? 0),
                'domestic_content_pct' => ($row['domestic_content_pct'] === null) ? null : (float)$row['domestic_content_pct'],
                'pallet_count' => (int)($row['pallet_count'] ?? 0),
                'pallet_modules' => (int)($row['pallet_modules'] ?? 0),
                'locked_pallet_count' => (int)($row['locked_pallet_count'] ?? 0),
                'locked_modules' => (int)($row['locked_modules'] ?? 0)
            ];
        }
        $stmt->close();

        return $rows;
    }
}

if (!function_exists('mr_module_rows_fingerprint')) {
    function mr_module_rows_fingerprint($rows) {
        $normalized = [];
        foreach ((array)$rows as $row) {
            $normalized[] = [
                'wattage' => (int)($row['wattage'] ?? 0),
                'quantity' => (int)($row['quantity'] ?? 0),
                'domestic_content_pct' => ($row['domestic_content_pct'] === null) ? null : round((float)$row['domestic_content_pct'], 6)
            ];
        }
        usort($normalized, function ($a, $b) {
            $cmp = (int)$a['wattage'] <=> (int)$b['wattage'];
            if ($cmp !== 0) {
                return $cmp;
            }
            $cmp = (int)$a['quantity'] <=> (int)$b['quantity'];
            if ($cmp !== 0) {
                return $cmp;
            }
            return (string)$a['domestic_content_pct'] <=> (string)$b['domestic_content_pct'];
        });

        return hash('sha256', json_encode($normalized));
    }
}

if (!function_exists('mr_insert_reconciliation_audit')) {
    function mr_insert_reconciliation_audit($conn, $payload) {
        if (!mr_audit_table_exists($conn)) {
            return false;
        }

        $moduleBatchId = (int)($payload['module_batch_id'] ?? 0);
        if ($moduleBatchId <= 0) {
            return false;
        }

        $projectId = (int)($payload['project_id'] ?? 0);
        $accountId = (int)($payload['account_id'] ?? 0);
        $actionType = trim((string)($payload['action_type'] ?? 'unknown'));
        $reason = trim((string)($payload['reason'] ?? ''));
        $reconciliationMode = trim((string)($payload['reconciliation_mode'] ?? ''));
        $previewSignature = trim((string)($payload['preview_signature'] ?? ''));
        $actorUserId = (int)($payload['actor_user_id'] ?? 0);
        $actorRole = trim((string)($payload['actor_role'] ?? ''));
        $sourcePage = trim((string)($payload['source_page'] ?? ''));

        $beforeJson = isset($payload['before_state']) ? json_encode($payload['before_state']) : null;
        $afterJson = isset($payload['after_state']) ? json_encode($payload['after_state']) : null;
        $impactJson = isset($payload['impact']) ? json_encode($payload['impact']) : null;

        if ($beforeJson === false) { $beforeJson = null; }
        if ($afterJson === false) { $afterJson = null; }
        if ($impactJson === false) { $impactJson = null; }

        $stmt = $conn->prepare("
            INSERT INTO module_batch_reconciliation_audit (
                module_batch_id,
                project_id,
                account_id,
                action_type,
                reason,
                reconciliation_mode,
                preview_signature,
                actor_user_id,
                actor_role,
                source_page,
                before_state_json,
                after_state_json,
                impact_json
            ) VALUES (
                ?,
                NULLIF(?, 0),
                NULLIF(?, 0),
                ?,
                ?,
                ?,
                ?,
                NULLIF(?, 0),
                ?,
                ?,
                ?,
                ?,
                ?
            )
        ");
        if (!$stmt) {
            throw new Exception('Failed preparing reconciliation audit insert: ' . $conn->error);
        }

        $stmt->bind_param(
            'iiissssisssss',
            $moduleBatchId,
            $projectId,
            $accountId,
            $actionType,
            $reason,
            $reconciliationMode,
            $previewSignature,
            $actorUserId,
            $actorRole,
            $sourcePage,
            $beforeJson,
            $afterJson,
            $impactJson
        );

        $ok = $stmt->execute();
        $stmt->close();
        return $ok;
    }
}
