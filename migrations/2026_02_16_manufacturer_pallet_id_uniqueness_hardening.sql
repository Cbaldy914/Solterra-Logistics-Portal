-- Hardening for manufacturer pallet ID uniqueness checks.
-- Enforces uniqueness within assigned project scope when data is already clean.

SET @dup_count_nonnull_scope = (
    SELECT COUNT(*)
    FROM (
        SELECT assigned_project_id, manufacturer_pallet_id
        FROM inventory_pallets
        WHERE assigned_project_id IS NOT NULL
          AND manufacturer_pallet_id IS NOT NULL
          AND manufacturer_pallet_id <> ''
        GROUP BY assigned_project_id, manufacturer_pallet_id
        HAVING COUNT(*) > 1
    ) d
);

SET @uniq_idx_exists = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'inventory_pallets'
      AND INDEX_NAME = 'uniq_pallet_mfr_project_scope'
);

SET @sql = IF(
    @uniq_idx_exists > 0,
    'SELECT "Unique index uniq_pallet_mfr_project_scope already exists"',
    IF(
        @dup_count_nonnull_scope = 0,
        'ALTER TABLE inventory_pallets ADD UNIQUE KEY uniq_pallet_mfr_project_scope (assigned_project_id, manufacturer_pallet_id)',
        'SELECT "Skipped creating uniq_pallet_mfr_project_scope because duplicate manufacturer_pallet_id values exist in assigned project scope"'
    )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
