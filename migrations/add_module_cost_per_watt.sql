-- Migration: Add cost_per_watt column to modules table
-- Date: 2026-01-12
-- Description: Adds optional cost per watt field for module batches to enable cost tracking and reporting

-- Add cost_per_watt column to modules table
ALTER TABLE `modules`
ADD COLUMN `cost_per_watt` DECIMAL(10,6) DEFAULT NULL
    COMMENT 'Module cost in price per watt (optional)';
