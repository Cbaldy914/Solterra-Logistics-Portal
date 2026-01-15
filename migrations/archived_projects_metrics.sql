-- Migration: Add comprehensive metrics columns to archived_projects table
-- Run this SQL in your database to store historical metrics when projects are closed

-- Cost Metrics
ALTER TABLE archived_projects
ADD COLUMN IF NOT EXISTS total_module_cost DECIMAL(12,2) DEFAULT 0 COMMENT 'Total cost of modules for the project',
ADD COLUMN IF NOT EXISTS total_freight_cost DECIMAL(12,2) DEFAULT 0 COMMENT 'Total freight/shipping costs',
ADD COLUMN IF NOT EXISTS total_warehousing_cost DECIMAL(12,2) DEFAULT 0 COMMENT 'Total warehousing costs (entry, exit, monthly)',
ADD COLUMN IF NOT EXISTS total_accessorial_cost DECIMAL(12,2) DEFAULT 0 COMMENT 'Total accessorial costs';

-- Sustainability Metrics
ALTER TABLE archived_projects
ADD COLUMN IF NOT EXISTS total_miles DECIMAL(12,2) DEFAULT 0 COMMENT 'Total miles driven for deliveries',
ADD COLUMN IF NOT EXISTS total_fuel_gallons DECIMAL(12,2) DEFAULT 0 COMMENT 'Estimated fuel consumption in gallons',
ADD COLUMN IF NOT EXISTS total_co2_kg DECIMAL(12,2) DEFAULT 0 COMMENT 'Estimated CO2 emissions in kg',
ADD COLUMN IF NOT EXISTS total_truckloads INT DEFAULT 0 COMMENT 'Total number of truckloads/deliveries';

-- Manufacturer/Module Metrics
ALTER TABLE archived_projects
ADD COLUMN IF NOT EXISTS primary_manufacturer VARCHAR(255) DEFAULT NULL COMMENT 'Manufacturer with most modules',
ADD COLUMN IF NOT EXISTS manufacturer_count INT DEFAULT 0 COMMENT 'Number of distinct manufacturers',
ADD COLUMN IF NOT EXISTS total_wattage BIGINT DEFAULT 0 COMMENT 'Total wattage in watts',
ADD COLUMN IF NOT EXISTS total_pallets INT DEFAULT 0 COMMENT 'Total number of pallets';

-- Delivery Performance Metrics
ALTER TABLE archived_projects
ADD COLUMN IF NOT EXISTS total_deliveries INT DEFAULT 0 COMMENT 'Total number of deliveries',
ADD COLUMN IF NOT EXISTS on_time_deliveries INT DEFAULT 0 COMMENT 'Deliveries that arrived on or before anticipated date',
ADD COLUMN IF NOT EXISTS late_deliveries INT DEFAULT 0 COMMENT 'Deliveries that arrived after anticipated date',
ADD COLUMN IF NOT EXISTS avg_days_late DECIMAL(5,1) DEFAULT NULL COMMENT 'Average days late for late deliveries',
ADD COLUMN IF NOT EXISTS project_completed_on_time TINYINT(1) DEFAULT NULL COMMENT '1 if all deliveries completed before project end date';

-- Damage/Warranty Metrics
ALTER TABLE archived_projects
ADD COLUMN IF NOT EXISTS damaged_modules_count INT DEFAULT 0 COMMENT 'Total modules reported as damaged',
ADD COLUMN IF NOT EXISTS warranty_claims_count INT DEFAULT 0 COMMENT 'Number of warranty claims filed';

-- Create indexes for common queries
CREATE INDEX IF NOT EXISTS idx_archived_primary_mfr ON archived_projects(primary_manufacturer);
CREATE INDEX IF NOT EXISTS idx_archived_total_cost ON archived_projects(total_module_cost);
