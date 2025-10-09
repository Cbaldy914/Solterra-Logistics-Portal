-- ================================================================
-- Anticipated Delivery Schedule Migration
-- Purpose: Add high-level delivery planning capability
-- Date: October 7, 2025
-- ================================================================

-- Add configuration fields to projects table (if they don't exist)
-- Note: If these columns already exist, you'll get an error - that's OK, just skip this part
ALTER TABLE `projects` 
ADD COLUMN `modules_per_pallet` INT DEFAULT 30 COMMENT 'Standard modules per pallet for this project',
ADD COLUMN `pallets_per_truck` INT DEFAULT 20 COMMENT 'Standard pallets per truck for this project';

-- Main schedule configuration table
CREATE TABLE `anticipated_delivery_schedule` (
  `id` INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `project_id` INT(11) NOT NULL,
  
  -- Schedule Configuration
  `schedule_type` ENUM('quick', 'detailed') DEFAULT 'quick' COMMENT 'Quick = rate-based, Detailed = week-by-week',
  `delivery_start_date` DATE NOT NULL COMMENT 'When deliveries begin',
  
  -- Quick Mode Fields (rate-based delivery)
  `quick_rate_value` DECIMAL(10,3) NULL COMMENT 'e.g., 2.5 MW or 500 modules',
  `quick_rate_unit` ENUM('mw', 'modules', 'pallets', 'trucks') NULL COMMENT 'Unit of measurement',
  `quick_rate_frequency` ENUM('per_week', 'per_month') DEFAULT 'per_week' COMMENT 'Delivery frequency',
  
  -- Status & Metadata
  `is_active` TINYINT(1) DEFAULT 1 COMMENT 'Only one active schedule per project',
  `created_by` INT(11) NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `notes` TEXT COMMENT 'Admin notes about the schedule',
  
  FOREIGN KEY (`project_id`) REFERENCES `projects`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  INDEX `idx_project_active` (`project_id`, `is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- Detailed schedule breakdown (week-by-week entries)
CREATE TABLE `anticipated_delivery_schedule_details` (
  `id` INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `schedule_id` INT(11) NOT NULL,
  
  -- Delivery timing
  `delivery_week_ending` DATE NOT NULL COMMENT 'Sunday of the delivery week',
  `delivery_amount` DECIMAL(10,3) NOT NULL COMMENT 'Amount to be delivered this week',
  `delivery_unit` ENUM('mw', 'modules', 'pallets', 'trucks') NOT NULL COMMENT 'Unit of measurement',
  
  -- Optional metadata
  `notes` TEXT COMMENT 'Notes for this specific week',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  
  FOREIGN KEY (`schedule_id`) REFERENCES `anticipated_delivery_schedule`(`id`) ON DELETE CASCADE,
  INDEX `idx_schedule_date` (`schedule_id`, `delivery_week_ending`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- ================================================================
-- Migration Notes:
-- 1. Run this SQL in your database to add the new tables
-- 2. Existing projects will get default values (30 modules/pallet, 20 pallets/truck)
-- 3. Admins can customize these values per project in anticipated_deliveries.php
-- ================================================================

