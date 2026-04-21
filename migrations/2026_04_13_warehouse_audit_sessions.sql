-- =====================================================
-- Warehouse Audit Feature
-- Prepared: 2026-04-13
--
-- Adds six new tables for mobile-first warehouse pallet-by-pallet audit sessions:
--   1. warehouse_audit_sessions         - session header
--   2. warehouse_audit_scans            - individual scan/manual entries
--   3. warehouse_audit_scan_photos      - join to project_documents for photo evidence
--   4. warehouse_audit_equipment_checks - non-pallet equipment verification (inverters)
--   5. warehouse_audit_session_events   - append-only session audit log
--   6. warehouse_audit_offline_queue_log - IndexedDB replay diagnostics
--
-- Also alters:
--   - inventory_pallets: makes unassigned_module_item_id nullable (allows
--     warehouse-only pallet commits without a module batch), adds
--     created_from_audit_scan_id back-pointer with FK to warehouse_audit_scans
--   - project_documents: adds warehouse_audit_session_id flexible link column
--
-- Does NOT touch any shelved equipment-tracking tables.
--
-- NOTE on transactionality: MySQL / MariaDB IMPLICITLY commit every DDL
-- statement (CREATE TABLE, ALTER TABLE, etc.). A `START TRANSACTION` wrapper
-- here would be cosmetic and cannot roll back schema changes mid-migration.
-- This file is therefore written to be IDEMPOTENT — every CREATE uses
-- IF NOT EXISTS, every ALTER is guarded by an INFORMATION_SCHEMA check.
-- If the migration partially fails, you can safely re-run the whole file
-- after fixing the offending statement. A verification block at the bottom
-- queries the expected objects and reports any that are still missing.
-- =====================================================

-- =====================================================
-- TABLE: warehouse_audit_sessions
-- =====================================================
CREATE TABLE IF NOT EXISTS warehouse_audit_sessions (
    id INT(11) NOT NULL AUTO_INCREMENT,
    account_id INT(11) NOT NULL,
    warehouse_id INT(11) NOT NULL,
    project_id INT(11) NULL,
    manufacturer_id INT(11) NULL,
    created_by_user_id INT(11) NOT NULL,
    customer_name VARCHAR(255) NULL,
    service_description TEXT NULL,
    service_fee_usd DECIMAL(10,2) NULL,
    session_code CHAR(12) NOT NULL,
    status ENUM('draft','in_progress','review','committed','completed','cancelled') NOT NULL DEFAULT 'draft',
    expected_pallet_count INT(11) NULL,
    expected_wattages_json TEXT NULL COMMENT 'JSON array, e.g. [690,695,700]',
    expected_modules_per_pallet INT(11) NULL,
    expected_equipment_json TEXT NULL COMMENT 'JSON array of {type, manufacturer, quantity} objects',
    discrepancy_notes TEXT NULL,
    ai_vision_enabled TINYINT(1) NOT NULL DEFAULT 1,
    ai_vision_model VARCHAR(64) NOT NULL DEFAULT 'gemini-2.5-flash',
    ai_vision_call_count INT(11) NOT NULL DEFAULT 0,
    ai_vision_call_cap INT(11) NOT NULL DEFAULT 2000,
    ai_vision_cost_estimate_usd DECIMAL(10,4) NOT NULL DEFAULT 0.0000,
    module_batch_id INT(11) NULL COMMENT 'Set on commit when project-scoped',
    committed_to_inventory_at DATETIME NULL,
    committed_by_user_id INT(11) NULL,
    started_at DATETIME NULL,
    completed_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_was_session_code (session_code),
    INDEX idx_was_account_status (account_id, status),
    INDEX idx_was_warehouse (warehouse_id),
    INDEX idx_was_project (project_id),
    INDEX idx_was_created_by (created_by_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- TABLE: warehouse_audit_scans
-- One row per physical scan or manual entry
-- =====================================================
CREATE TABLE IF NOT EXISTS warehouse_audit_scans (
    id INT(11) NOT NULL AUTO_INCREMENT,
    session_id INT(11) NOT NULL,
    sequence_number INT(11) NOT NULL,
    scan_method ENUM('barcode','qr','ai_vision','manual') NOT NULL,
    raw_value VARCHAR(500) NOT NULL COMMENT 'Exact text scanned or typed',
    normalized_pallet_id VARCHAR(255) NULL COMMENT 'Uppercased/trimmed for dedup',
    parsed_wattage INT(11) NULL,
    parsed_serial VARCHAR(255) NULL,
    equipment_code VARCHAR(255) NULL COMMENT 'Model/type designation from label',
    supplier VARCHAR(255) NULL,
    quantity INT(11) NOT NULL DEFAULT 0 COMMENT 'Modules on this pallet',
    expected_wattage_match TINYINT(1) NULL,
    is_discrepancy TINYINT(1) NOT NULL DEFAULT 0,
    discrepancy_codes VARCHAR(255) NULL COMMENT 'CSV of codes',
    discrepancy_notes TEXT NULL,
    notes TEXT NULL,
    ai_confidence DECIMAL(3,2) NULL COMMENT '0.00-1.00 for ai_vision scans',
    ai_raw_response TEXT NULL COMMENT 'Full Gemini JSON response for audit trail',
    client_uuid CHAR(36) NOT NULL COMMENT 'Phone-generated UUID for offline idempotency',
    client_captured_at DATETIME NULL COMMENT 'Phone clock at capture time',
    verified_by_user_id INT(11) NOT NULL,
    inventory_pallet_id INT(11) NULL COMMENT 'FK to inventory_pallets.id, set on commit',
    is_soft_deleted TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_was_scan_client_uuid (session_id, client_uuid),
    INDEX idx_was_scan_session_seq (session_id, sequence_number),
    INDEX idx_was_scan_session_discrepancy (session_id, is_discrepancy),
    INDEX idx_was_scan_normalized (normalized_pallet_id),
    INDEX idx_was_scan_pallet (inventory_pallet_id),
    CONSTRAINT fk_was_scan_session FOREIGN KEY (session_id)
        REFERENCES warehouse_audit_sessions (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- TABLE: warehouse_audit_scan_photos
-- Join between scans and project_documents-hosted photos
-- =====================================================
CREATE TABLE IF NOT EXISTS warehouse_audit_scan_photos (
    id INT(11) NOT NULL AUTO_INCREMENT,
    session_id INT(11) NOT NULL,
    scan_id INT(11) NULL COMMENT 'NULL for session-level context photos',
    equipment_check_id INT(11) NULL COMMENT 'Set when photo is for an equipment row',
    project_document_id INT(11) NULL COMMENT 'FK to project_documents.id',
    photo_role ENUM('label','pallet_overview','damage','context','equipment_tag','equipment_overview','signature') NOT NULL,
    caption VARCHAR(500) NULL,
    sort_order INT(11) NOT NULL DEFAULT 0,
    uploaded_by_user_id INT(11) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    INDEX idx_wasp_session_role (session_id, photo_role),
    INDEX idx_wasp_scan (scan_id),
    INDEX idx_wasp_equipment (equipment_check_id),
    INDEX idx_wasp_document (project_document_id),
    CONSTRAINT fk_wasp_session FOREIGN KEY (session_id)
        REFERENCES warehouse_audit_sessions (id) ON DELETE CASCADE,
    CONSTRAINT fk_wasp_scan FOREIGN KEY (scan_id)
        REFERENCES warehouse_audit_scans (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- TABLE: warehouse_audit_equipment_checks
-- Non-pallet equipment verification (e.g. inverters)
-- Deliberately minimal - does NOT reference any shelved equipment-tracking tables
-- =====================================================
CREATE TABLE IF NOT EXISTS warehouse_audit_equipment_checks (
    id INT(11) NOT NULL AUTO_INCREMENT,
    session_id INT(11) NOT NULL,
    equipment_type VARCHAR(100) NOT NULL COMMENT 'e.g. MV Inverter',
    manufacturer_name VARCHAR(255) NULL COMMENT 'e.g. Siemens',
    model_number VARCHAR(255) NULL,
    serial_number VARCHAR(255) NULL,
    asset_tag VARCHAR(255) NULL,
    expected_quantity INT(11) NOT NULL DEFAULT 1,
    verified_quantity INT(11) NOT NULL DEFAULT 0,
    status ENUM('not_verified','verified','missing','damaged','wrong_model') NOT NULL DEFAULT 'not_verified',
    notes TEXT NULL,
    verified_by_user_id INT(11) NULL,
    verified_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    INDEX idx_waec_session_status (session_id, status),
    CONSTRAINT fk_waec_session FOREIGN KEY (session_id)
        REFERENCES warehouse_audit_sessions (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- TABLE: warehouse_audit_session_events
-- Append-only audit log for session lifecycle
-- =====================================================
CREATE TABLE IF NOT EXISTS warehouse_audit_session_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    session_id INT(11) NOT NULL,
    actor_user_id INT(11) NULL,
    event_type VARCHAR(64) NOT NULL COMMENT 'started, paused, resumed, scan_added, scan_edited, scan_deleted, photo_uploaded, equipment_checked, ai_parse_called, committed_to_inventory, completed, pdf_generated',
    payload LONGTEXT NULL COMMENT 'JSON payload with event details',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    INDEX idx_wase_session_created (session_id, created_at),
    INDEX idx_wase_event_type (event_type),
    CONSTRAINT fk_wase_session FOREIGN KEY (session_id)
        REFERENCES warehouse_audit_sessions (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- TABLE: warehouse_audit_offline_queue_log
-- Visibility into IndexedDB replay attempts from the client
-- =====================================================
CREATE TABLE IF NOT EXISTS warehouse_audit_offline_queue_log (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    session_id INT(11) NOT NULL,
    client_uuid CHAR(36) NOT NULL,
    retry_count INT(11) NOT NULL DEFAULT 0,
    client_captured_at DATETIME NULL,
    ip_address VARCHAR(45) NULL,
    user_agent VARCHAR(255) NULL,
    result ENUM('accepted','duplicate','rejected') NOT NULL,
    result_detail VARCHAR(500) NULL,
    received_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    INDEX idx_waoql_session_uuid (session_id, client_uuid),
    INDEX idx_waoql_received (received_at),
    CONSTRAINT fk_waoql_session FOREIGN KEY (session_id)
        REFERENCES warehouse_audit_sessions (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- ALTER: inventory_pallets
--   1. Make unassigned_module_item_id nullable so warehouse-only audits
--      can insert pallets without forcing creation of a module batch
--      and item for sessions that have no project assignment.
--   2. Add created_from_audit_scan_id back-pointer to the audit scan
--      that generated the pallet row (for traceability).
--
-- Uses INFORMATION_SCHEMA guards so re-running the migration is safe.
-- =====================================================

SET @ip_umi_not_null := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'inventory_pallets'
      AND COLUMN_NAME = 'unassigned_module_item_id'
      AND IS_NULLABLE = 'NO'
);

SET @sql_umi := IF(
    @ip_umi_not_null > 0,
    'ALTER TABLE inventory_pallets MODIFY COLUMN unassigned_module_item_id INT(11) NULL COMMENT ''FK to unassigned_module_items.id (nullable for warehouse-only audit commits)''',
    'DO 0'
);
PREPARE stmt_umi FROM @sql_umi;
EXECUTE stmt_umi;
DEALLOCATE PREPARE stmt_umi;

SET @ip_has_audit_scan_col := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'inventory_pallets'
      AND COLUMN_NAME = 'created_from_audit_scan_id'
);

SET @sql_add_audit_col := IF(
    @ip_has_audit_scan_col = 0,
    'ALTER TABLE inventory_pallets ADD COLUMN created_from_audit_scan_id INT(11) NULL COMMENT ''FK to warehouse_audit_scans.id (audit trail back-pointer)'' AFTER customs_hold_cost_updated_at',
    'DO 0'
);
PREPARE stmt_add_audit FROM @sql_add_audit_col;
EXECUTE stmt_add_audit;
DEALLOCATE PREPARE stmt_add_audit;

SET @ip_has_audit_idx := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'inventory_pallets'
      AND INDEX_NAME = 'idx_ip_audit_scan'
);

SET @sql_add_audit_idx := IF(
    @ip_has_audit_idx = 0,
    'ALTER TABLE inventory_pallets ADD INDEX idx_ip_audit_scan (created_from_audit_scan_id)',
    'DO 0'
);
PREPARE stmt_audit_idx FROM @sql_add_audit_idx;
EXECUTE stmt_audit_idx;
DEALLOCATE PREPARE stmt_audit_idx;

-- =====================================================
-- ALTER: project_documents
-- Add warehouse_audit_session_id flexible link column +
-- index, mirroring the delivery_id / carrier_id pattern.
-- =====================================================

SET @pd_has_audit_col := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'project_documents'
      AND COLUMN_NAME = 'warehouse_audit_session_id'
);

SET @sql_pd_add := IF(
    @pd_has_audit_col = 0,
    'ALTER TABLE project_documents ADD COLUMN warehouse_audit_session_id INT(11) NULL COMMENT ''Links document to a warehouse audit session if applicable''',
    'DO 0'
);
PREPARE stmt_pd_add FROM @sql_pd_add;
EXECUTE stmt_pd_add;
DEALLOCATE PREPARE stmt_pd_add;

SET @pd_has_audit_idx := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'project_documents'
      AND INDEX_NAME = 'idx_pd_warehouse_audit_session'
);

SET @sql_pd_idx := IF(
    @pd_has_audit_idx = 0,
    'ALTER TABLE project_documents ADD INDEX idx_pd_warehouse_audit_session (warehouse_audit_session_id)',
    'DO 0'
);
PREPARE stmt_pd_idx FROM @sql_pd_idx;
EXECUTE stmt_pd_idx;
DEALLOCATE PREPARE stmt_pd_idx;

-- =====================================================
-- FK: inventory_pallets.created_from_audit_scan_id -> warehouse_audit_scans.id
--
-- Added in a separate guarded ALTER so re-running is safe. ON DELETE SET NULL
-- so deleting an audit scan row clears the back-pointer on any pallet that
-- was created from it, without cascading into inventory.
-- Must run AFTER both inventory_pallets.created_from_audit_scan_id exists
-- (added above) AND warehouse_audit_scans has been created (above).
-- =====================================================

SET @ip_has_audit_fk := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'inventory_pallets'
      AND CONSTRAINT_NAME = 'fk_ip_audit_scan'
      AND CONSTRAINT_TYPE = 'FOREIGN KEY'
);

SET @sql_ip_fk := IF(
    @ip_has_audit_fk = 0,
    'ALTER TABLE inventory_pallets ADD CONSTRAINT fk_ip_audit_scan FOREIGN KEY (created_from_audit_scan_id) REFERENCES warehouse_audit_scans (id) ON DELETE SET NULL',
    'DO 0'
);
PREPARE stmt_ip_fk FROM @sql_ip_fk;
EXECUTE stmt_ip_fk;
DEALLOCATE PREPARE stmt_ip_fk;

-- =====================================================
-- Post-migration verification
--
-- Runs automatically at the end of the file. Each SELECT returns a row
-- labeled 'OK' when the object exists and 'MISSING' when it doesn't, so a
-- `mysql < migrations/...sql` invocation's output is self-reporting.
-- The migration is considered successful only when every row reports 'OK'.
-- =====================================================

SELECT 'warehouse_audit_sessions'          AS object,
       IF(COUNT(*) = 1, 'OK', 'MISSING')    AS status
FROM INFORMATION_SCHEMA.TABLES
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'warehouse_audit_sessions'
UNION ALL SELECT 'warehouse_audit_scans',
       IF(COUNT(*) = 1, 'OK', 'MISSING')
FROM INFORMATION_SCHEMA.TABLES
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'warehouse_audit_scans'
UNION ALL SELECT 'warehouse_audit_scan_photos',
       IF(COUNT(*) = 1, 'OK', 'MISSING')
FROM INFORMATION_SCHEMA.TABLES
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'warehouse_audit_scan_photos'
UNION ALL SELECT 'warehouse_audit_equipment_checks',
       IF(COUNT(*) = 1, 'OK', 'MISSING')
FROM INFORMATION_SCHEMA.TABLES
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'warehouse_audit_equipment_checks'
UNION ALL SELECT 'warehouse_audit_session_events',
       IF(COUNT(*) = 1, 'OK', 'MISSING')
FROM INFORMATION_SCHEMA.TABLES
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'warehouse_audit_session_events'
UNION ALL SELECT 'warehouse_audit_offline_queue_log',
       IF(COUNT(*) = 1, 'OK', 'MISSING')
FROM INFORMATION_SCHEMA.TABLES
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'warehouse_audit_offline_queue_log'
UNION ALL SELECT 'inventory_pallets.unassigned_module_item_id nullable',
       IF(COUNT(*) = 1, 'OK', 'MISSING')
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'inventory_pallets'
  AND COLUMN_NAME = 'unassigned_module_item_id'
  AND IS_NULLABLE = 'YES'
UNION ALL SELECT 'inventory_pallets.created_from_audit_scan_id column',
       IF(COUNT(*) = 1, 'OK', 'MISSING')
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'inventory_pallets'
  AND COLUMN_NAME = 'created_from_audit_scan_id'
UNION ALL SELECT 'inventory_pallets.fk_ip_audit_scan',
       IF(COUNT(*) = 1, 'OK', 'MISSING')
FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'inventory_pallets'
  AND CONSTRAINT_NAME = 'fk_ip_audit_scan'
  AND CONSTRAINT_TYPE = 'FOREIGN KEY'
UNION ALL SELECT 'project_documents.warehouse_audit_session_id column',
       IF(COUNT(*) = 1, 'OK', 'MISSING')
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'project_documents'
  AND COLUMN_NAME = 'warehouse_audit_session_id';
