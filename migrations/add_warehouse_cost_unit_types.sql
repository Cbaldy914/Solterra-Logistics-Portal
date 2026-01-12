-- Migration: Add unit type support for warehouse cost items
-- Date: 2026-01-12
-- Description: Extends warehouse_cost_items to support multiple unit types
--              (per_pallet, per_truck, per_sqft, flat) and related parameters

-- Modify unit_type to be an enum with all supported types
ALTER TABLE `warehouse_cost_items`
  MODIFY COLUMN `unit_type` ENUM('per_pallet', 'per_truck', 'per_sqft', 'flat') NOT NULL DEFAULT 'per_pallet';

-- Add pallets_per_truck for per_truck calculations
ALTER TABLE `warehouse_cost_items`
  ADD COLUMN IF NOT EXISTS `pallets_per_truck` INT(11) DEFAULT 26
  COMMENT 'For per_truck unit type: how many pallets per truck';

-- Add sqft_per_pallet for per_sqft calculations
ALTER TABLE `warehouse_cost_items`
  ADD COLUMN IF NOT EXISTS `sqft_per_pallet` DECIMAL(10,2) DEFAULT 13.33
  COMMENT 'For per_sqft unit type: square footage per pallet';

-- Add display_order for controlling the order fees appear
ALTER TABLE `warehouse_cost_items`
  ADD COLUMN IF NOT EXISTS `display_order` INT(11) DEFAULT 0
  COMMENT 'Order in which to display this cost item';

-- Note: Existing data is preserved - all current records default to per_pallet
