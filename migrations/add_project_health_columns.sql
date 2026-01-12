-- Migration: Add manual project health override columns
-- Date: 2026-01-09
-- Description: Adds columns to projects table for admin manual health status override

-- Add manual health status columns to projects table
ALTER TABLE `projects`
ADD COLUMN `manual_health_status` ENUM('on_track', 'at_risk', 'behind') DEFAULT NULL
    COMMENT 'Manual override for project health status (NULL = auto-calculated)',
ADD COLUMN `manual_health_reason` TEXT DEFAULT NULL
    COMMENT 'Required explanation when admin sets manual health status',
ADD COLUMN `manual_health_set_by` INT(11) DEFAULT NULL
    COMMENT 'User ID who set the manual health status',
ADD COLUMN `manual_health_set_at` DATETIME DEFAULT NULL
    COMMENT 'When the manual health status was set';

-- Add foreign key constraint for manual_health_set_by
ALTER TABLE `projects`
ADD CONSTRAINT `fk_manual_health_set_by`
    FOREIGN KEY (`manual_health_set_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;
