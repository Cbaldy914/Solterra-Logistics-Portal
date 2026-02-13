-- =====================================================
-- Module Reconciliation Phase 4
-- - Data hardening for unassigned_module_items
-- - Audit trail for structural module batch edits
-- =====================================================

-- =====================================================
-- TABLE: module_batch_reconciliation_audit
-- Stores before/after snapshots for structural batch edits
-- =====================================================
CREATE TABLE IF NOT EXISTS module_batch_reconciliation_audit (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    module_batch_id INT NOT NULL,
    project_id INT NULL,
    account_id INT NULL,
    action_type VARCHAR(64) NOT NULL,
    reason VARCHAR(255) NULL,
    reconciliation_mode VARCHAR(32) NULL,
    preview_signature CHAR(64) NULL,
    actor_user_id INT NULL,
    actor_role VARCHAR(32) NULL,
    source_page VARCHAR(128) NULL,
    before_state_json LONGTEXT NULL,
    after_state_json LONGTEXT NULL,
    impact_json LONGTEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    INDEX idx_mbra_batch_created (module_batch_id, created_at),
    INDEX idx_mbra_project_created (project_id, created_at),
    INDEX idx_mbra_actor_created (actor_user_id, created_at),
    INDEX idx_mbra_action_created (action_type, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- Backfill / Deduplicate duplicate (unassigned_module_id, wattage) rows
-- =====================================================
SET @umi_has_domestic_pct = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'unassigned_module_items'
      AND COLUMN_NAME = 'domestic_content_pct'
);

SET @sql_add_domestic_pct = IF(
    @umi_has_domestic_pct = 0,
    'ALTER TABLE unassigned_module_items ADD COLUMN domestic_content_pct DECIMAL(5,2) NULL AFTER quantity',
    'SELECT "Column domestic_content_pct already exists on unassigned_module_items"'
);

PREPARE stmt_add_domestic_pct FROM @sql_add_domestic_pct;
EXECUTE stmt_add_domestic_pct;
DEALLOCATE PREPARE stmt_add_domestic_pct;

DROP TEMPORARY TABLE IF EXISTS tmp_umi_canonical;
CREATE TEMPORARY TABLE tmp_umi_canonical AS
SELECT
    umi.unassigned_module_id,
    umi.wattage,
    MIN(umi.id) AS canonical_id,
    SUM(umi.quantity) AS merged_quantity,
    CASE
        WHEN SUM(CASE WHEN umi.domestic_content_pct IS NOT NULL THEN umi.quantity ELSE 0 END) > 0
            THEN ROUND(
                SUM(
                    CASE
                        WHEN umi.domestic_content_pct IS NOT NULL
                            THEN (umi.quantity * umi.domestic_content_pct)
                        ELSE 0
                    END
                )
                /
                SUM(CASE WHEN umi.domestic_content_pct IS NOT NULL THEN umi.quantity ELSE 0 END),
                2
            )
        ELSE NULL
    END AS merged_domestic_content_pct
FROM unassigned_module_items umi
GROUP BY umi.unassigned_module_id, umi.wattage
HAVING COUNT(*) > 1;

DROP TEMPORARY TABLE IF EXISTS tmp_umi_duplicate_map;
CREATE TEMPORARY TABLE tmp_umi_duplicate_map AS
SELECT
    umi.id AS duplicate_id,
    c.canonical_id
FROM unassigned_module_items umi
JOIN tmp_umi_canonical c
    ON c.unassigned_module_id = umi.unassigned_module_id
   AND c.wattage = umi.wattage
WHERE umi.id <> c.canonical_id;

-- Re-point pallet foreign keys to canonical module item rows.
UPDATE inventory_pallets ip
JOIN tmp_umi_duplicate_map dm ON dm.duplicate_id = ip.unassigned_module_item_id
SET ip.unassigned_module_item_id = dm.canonical_id;

-- Merge quantity/domestic values into canonical rows.
UPDATE unassigned_module_items umi
JOIN tmp_umi_canonical c ON c.canonical_id = umi.id
SET
    umi.quantity = c.merged_quantity,
    umi.domestic_content_pct = c.merged_domestic_content_pct;

-- Remove duplicate rows after pallets are re-pointed.
DELETE umi
FROM unassigned_module_items umi
JOIN tmp_umi_duplicate_map dm ON dm.duplicate_id = umi.id;

DROP TEMPORARY TABLE IF EXISTS tmp_umi_duplicate_map;
DROP TEMPORARY TABLE IF EXISTS tmp_umi_canonical;

-- =====================================================
-- Add unique key to prevent future duplicates
-- =====================================================
SET @umi_unique_exists = (
    SELECT COUNT(*)
    FROM (
        SELECT INDEX_NAME
        FROM INFORMATION_SCHEMA.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'unassigned_module_items'
        GROUP BY INDEX_NAME
        HAVING
            MIN(NON_UNIQUE) = 0
            AND COUNT(*) = 2
            AND SUM(CASE WHEN COLUMN_NAME = 'unassigned_module_id' THEN 1 ELSE 0 END) = 1
            AND SUM(CASE WHEN COLUMN_NAME = 'wattage' THEN 1 ELSE 0 END) = 1
    ) existing_unique_keys
);

SET @sql_add_umi_unique = IF(
    @umi_unique_exists = 0,
    'ALTER TABLE unassigned_module_items ADD UNIQUE KEY uniq_unassigned_module_wattage (unassigned_module_id, wattage)',
    'SELECT "Unique key uniq_unassigned_module_wattage already exists"'
);

PREPARE stmt_add_umi_unique FROM @sql_add_umi_unique;
EXECUTE stmt_add_umi_unique;
DEALLOCATE PREPARE stmt_add_umi_unique;
