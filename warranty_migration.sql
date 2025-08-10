-- Warranty Claims Enhancement Migration
-- This migration updates the warranty_claims table and creates supporting tables
-- for the new two-page warranty workflow

-- Step 1: Alter warranty_claims table
ALTER TABLE `warranty_claims`
    DROP COLUMN `manufacturer_notes`,
    DROP COLUMN `new_delivery_date`,
    ADD COLUMN `responsible_party` ENUM('Manufacturer','EPC','Carrier','Other') NOT NULL DEFAULT 'Manufacturer',
    ADD COLUMN `resolution_type` ENUM('Credit','Replacement','No-charge','Monitoring') NULL,
    ADD COLUMN `credit_amount` DECIMAL(12,2) NULL,
    ADD COLUMN `replacement_tracking` VARCHAR(100) NULL,
    ADD COLUMN `proof_of_completion_path` VARCHAR(255) NULL,
    ADD COLUMN `public_notes` LONGTEXT NULL,
    ADD COLUMN `internal_notes` LONGTEXT NULL,
    ADD COLUMN `last_public_update_at` DATETIME NULL,
    MODIFY COLUMN `status` ENUM(
        'Draft',
        'Submitted',
        'In Review',
        'Pending Manufacturer',
        'Approved - Credit',
        'Approved - Replacement',
        'Replacement Shipped',
        'Closed',
        'Rejected'
    ) NOT NULL DEFAULT 'Draft';

-- Step 2: Create warranty claim events table for timeline tracking
CREATE TABLE `warranty_claim_events` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `claim_id` INT NOT NULL,
    `event_ts` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `user_id` INT NOT NULL,
    `event_text` TEXT NOT NULL,
    `is_public` TINYINT(1) NOT NULL DEFAULT 1,
    KEY `idx_claim_events_claim` (`claim_id`),
    KEY `idx_claim_events_timestamp` (`event_ts`),
    KEY `idx_claim_events_public` (`is_public`),
    FOREIGN KEY (`claim_id`) REFERENCES `warranty_claims`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Step 3: Create warranty claim replacements table
CREATE TABLE `warranty_claim_replacements` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `claim_id` INT NOT NULL,
    `pallet_id` INT NOT NULL,
    KEY `idx_claim_replacements_claim` (`claim_id`),
    KEY `idx_claim_replacements_pallet` (`pallet_id`),
    FOREIGN KEY (`claim_id`) REFERENCES `warranty_claims`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`pallet_id`) REFERENCES `inventory_pallets`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Step 4: Update existing warranty claims to have proper status values
-- Map old status values to new enum values
UPDATE `warranty_claims` 
SET `status` = CASE 
    WHEN LOWER(`status`) LIKE '%pending%' THEN 'Submitted'
    WHEN LOWER(`status`) LIKE '%resolved%' THEN 'Closed'
    WHEN LOWER(`status`) LIKE '%reject%' THEN 'Rejected'
    WHEN LOWER(`status`) LIKE '%progress%' THEN 'In Review'
    ELSE 'Draft'
END
WHERE `status` NOT IN ('Draft','Submitted','In Review','Pending Manufacturer','Approved - Credit','Approved - Replacement','Replacement Shipped','Closed','Rejected');

-- Step 5: Create initial events for existing warranty claims
INSERT INTO `warranty_claim_events` (`claim_id`, `user_id`, `event_text`, `is_public`)
SELECT 
    w.`id`,
    1, -- Default to user_id 1, adjust as needed
    CONCAT('Warranty claim created for ', 
           COALESCE(ip.pallet_identifier, CONCAT('Pallet ID: ', w.pallet_id)), 
           ' - ', w.issue_type),
    1
FROM `warranty_claims` w
LEFT JOIN `inventory_pallets` ip ON w.pallet_id = ip.id
WHERE w.`created_at` IS NOT NULL;
