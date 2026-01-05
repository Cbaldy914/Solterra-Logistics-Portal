-- =====================================================
-- Schedule Upload Feature - Database Schema Migration
-- =====================================================
-- This migration adds support for:
-- 1. Manufacturer column mappings (for flexible Excel/CSV import)
-- 2. Schedule upload history tracking
-- 3. Manufacturer pallet ID linking on inventory_pallets
-- =====================================================

-- =====================================================
-- TABLE: manufacturer_column_mappings
-- Stores per-manufacturer column mapping configurations
-- for importing shipping schedules from Excel/CSV files
-- =====================================================
CREATE TABLE IF NOT EXISTS manufacturer_column_mappings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    manufacturer_id INT NOT NULL,
    account_id INT NOT NULL,
    mapping_name VARCHAR(100) DEFAULT 'Default Mapping',

    -- JSON structure for column mappings:
    -- {
    --   "bol_number": "BOL",           -- Maps system field to user's column name
    --   "container_number": "Container #",
    --   "pallet_id": "Pallet ID",
    --   "wattage": "Watts",
    --   "quantity": "Qty",
    --   "estimated_delivery": "Est. Delivery",
    --   "actual_delivery": "Actual Delivery",
    --   "status": "Status",
    --   "destination_type": "Destination Type",  -- 'project' or 'warehouse'
    --   "destination_name": "Destination"
    -- }
    column_mappings JSON NOT NULL,

    -- Metadata
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by INT,

    -- Foreign keys
    FOREIGN KEY (manufacturer_id) REFERENCES manufacturers(id) ON DELETE CASCADE,
    FOREIGN KEY (account_id) REFERENCES customer_accounts(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,

    -- Each manufacturer+account combo can have one mapping
    UNIQUE KEY unique_manufacturer_account (manufacturer_id, account_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =====================================================
-- TABLE: schedule_uploads
-- Tracks upload history for audit and statistics
-- =====================================================
CREATE TABLE IF NOT EXISTS schedule_uploads (
    id INT AUTO_INCREMENT PRIMARY KEY,
    account_id INT NOT NULL,
    project_id INT,
    manufacturer_id INT NOT NULL,

    -- File information
    file_name VARCHAR(255),
    original_file_name VARCHAR(255),
    file_path VARCHAR(500),

    -- Upload metadata
    upload_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    uploaded_by INT NOT NULL,

    -- Statistics from the import
    pallets_created INT DEFAULT 0,
    pallets_updated INT DEFAULT 0,
    deliveries_created INT DEFAULT 0,
    deliveries_updated INT DEFAULT 0,
    rows_processed INT DEFAULT 0,
    rows_skipped INT DEFAULT 0,

    -- Warnings/errors as JSON array
    -- [{"row": 5, "message": "Wattage 580W outside project tolerance"}, ...]
    warnings JSON,

    -- Import status
    status ENUM('pending', 'processing', 'completed', 'failed') DEFAULT 'completed',
    error_message TEXT,

    -- Foreign keys
    FOREIGN KEY (account_id) REFERENCES customer_accounts(id) ON DELETE CASCADE,
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE SET NULL,
    FOREIGN KEY (manufacturer_id) REFERENCES manufacturers(id) ON DELETE CASCADE,
    FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE CASCADE,

    -- Indexes
    INDEX idx_account_date (account_id, upload_date),
    INDEX idx_project_date (project_id, upload_date),
    INDEX idx_manufacturer (manufacturer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =====================================================
-- ALTER TABLE: inventory_pallets
-- Add manufacturer_pallet_id for linking to real manufacturer data
-- =====================================================

-- Add manufacturer_pallet_id column if it doesn't exist
SET @column_exists = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'inventory_pallets'
    AND COLUMN_NAME = 'manufacturer_pallet_id'
);

SET @sql = IF(@column_exists = 0,
    'ALTER TABLE inventory_pallets ADD COLUMN manufacturer_pallet_id VARCHAR(100) AFTER pallet_identifier',
    'SELECT "Column manufacturer_pallet_id already exists"'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add schedule_upload_id column if it doesn't exist
SET @column_exists2 = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'inventory_pallets'
    AND COLUMN_NAME = 'schedule_upload_id'
);

SET @sql2 = IF(@column_exists2 = 0,
    'ALTER TABLE inventory_pallets ADD COLUMN schedule_upload_id INT AFTER manufacturer_pallet_id',
    'SELECT "Column schedule_upload_id already exists"'
);
PREPARE stmt2 FROM @sql2;
EXECUTE stmt2;
DEALLOCATE PREPARE stmt2;

-- Add index for manufacturer_pallet_id lookups (for matching on re-upload)
-- Check if index exists first
SET @index_exists = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'inventory_pallets'
    AND INDEX_NAME = 'idx_manufacturer_pallet_project'
);

SET @sql3 = IF(@index_exists = 0,
    'ALTER TABLE inventory_pallets ADD INDEX idx_manufacturer_pallet_project (manufacturer_pallet_id, assigned_project_id)',
    'SELECT "Index idx_manufacturer_pallet_project already exists"'
);
PREPARE stmt3 FROM @sql3;
EXECUTE stmt3;
DEALLOCATE PREPARE stmt3;


-- =====================================================
-- ALTER TABLE: project_wattage_orders
-- Add tolerance support for bin class variations
-- =====================================================

-- Add tolerance_watts column if it doesn't exist
SET @column_exists3 = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'project_wattage_orders'
    AND COLUMN_NAME = 'tolerance_watts'
);

SET @sql4 = IF(@column_exists3 = 0,
    'ALTER TABLE project_wattage_orders ADD COLUMN tolerance_watts INT DEFAULT 10 AFTER wattage',
    'SELECT "Column tolerance_watts already exists"'
);
PREPARE stmt4 FROM @sql4;
EXECUTE stmt4;
DEALLOCATE PREPARE stmt4;


-- =====================================================
-- ALTER TABLE: deliveries
-- Add schedule_upload_id for tracking source of delivery
-- =====================================================

SET @column_exists4 = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'deliveries'
    AND COLUMN_NAME = 'schedule_upload_id'
);

SET @sql5 = IF(@column_exists4 = 0,
    'ALTER TABLE deliveries ADD COLUMN schedule_upload_id INT AFTER id',
    'SELECT "Column schedule_upload_id already exists in deliveries"'
);
PREPARE stmt5 FROM @sql5;
EXECUTE stmt5;
DEALLOCATE PREPARE stmt5;

-- Add source tracking column
SET @column_exists5 = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'deliveries'
    AND COLUMN_NAME = 'data_source'
);

SET @sql6 = IF(@column_exists5 = 0,
    'ALTER TABLE deliveries ADD COLUMN data_source ENUM("manual", "schedule_import") DEFAULT "manual" AFTER schedule_upload_id',
    'SELECT "Column data_source already exists in deliveries"'
);
PREPARE stmt6 FROM @sql6;
EXECUTE stmt6;
DEALLOCATE PREPARE stmt6;


-- =====================================================
-- ALTER TABLE: modules
-- Add data_source tracking for module batches
-- =====================================================

SET @column_exists6 = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'modules'
    AND COLUMN_NAME = 'data_source'
);

SET @sql7 = IF(@column_exists6 = 0,
    'ALTER TABLE modules ADD COLUMN data_source ENUM("manual", "schedule_import") DEFAULT "manual"',
    'SELECT "Column data_source already exists in modules"'
);
PREPARE stmt7 FROM @sql7;
EXECUTE stmt7;
DEALLOCATE PREPARE stmt7;


-- =====================================================
-- Done! Summary of changes:
-- =====================================================
-- NEW TABLES:
--   - manufacturer_column_mappings: Store column mapping configurations
--   - schedule_uploads: Track upload history and statistics
--
-- MODIFIED TABLES:
--   - inventory_pallets: Added manufacturer_pallet_id, schedule_upload_id
--   - project_wattage_orders: Added tolerance_watts
--   - deliveries: Added schedule_upload_id, data_source
--   - modules: Added data_source
-- =====================================================
