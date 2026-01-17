-- Migration: Update payment milestones triggers
-- Date: 2026-01-17
-- Description:
--   1. Removes 'warehouse_arrival' trigger, adds 'shipping' trigger
--   2. Adds support for batch-level milestones (PO execution triggers at batch creation)
--   3. Makes delivery_id nullable and adds module_batch_id for batch-level milestones

-- Step 1: Update the trigger_event enum to include 'shipping' and remove 'warehouse_arrival'
ALTER TABLE `module_batch_milestones`
MODIFY COLUMN `trigger_event` enum('po_execution','customs_cleared','shipping','project_delivery') NOT NULL;

-- Step 2: Update any existing 'warehouse_arrival' milestones to 'shipping' (if any exist)
UPDATE `module_batch_milestones` SET `trigger_event` = 'shipping' WHERE `trigger_event` = 'warehouse_arrival';

-- Step 3: Make delivery_id nullable for batch-level milestones
ALTER TABLE `delivery_milestone_instances`
MODIFY COLUMN `delivery_id` int(11) DEFAULT NULL COMMENT 'FK to deliveries.id (NULL for batch-level milestones)';

-- Step 4: Add module_batch_id column for batch-level milestones
ALTER TABLE `delivery_milestone_instances`
ADD COLUMN `module_batch_id` int(11) DEFAULT NULL COMMENT 'FK to modules.id for batch-level milestones (e.g., PO execution)' AFTER `delivery_id`;

-- Step 5: Add index for module_batch_id
ALTER TABLE `delivery_milestone_instances`
ADD KEY `idx_module_batch_id` (`module_batch_id`);

-- Step 6: Add foreign key for module_batch_id
ALTER TABLE `delivery_milestone_instances`
ADD CONSTRAINT `fk_instance_module_batch` FOREIGN KEY (`module_batch_id`) REFERENCES `modules` (`id`) ON DELETE CASCADE;

-- Step 7: Drop the unique constraint on delivery_id + milestone_id (since delivery_id can now be null)
ALTER TABLE `delivery_milestone_instances`
DROP INDEX `uq_delivery_milestone`;

-- Step 8: Add new unique constraint that handles both delivery-level and batch-level milestones
ALTER TABLE `delivery_milestone_instances`
ADD UNIQUE KEY `uq_milestone_instance` (`delivery_id`, `milestone_id`, `module_batch_id`);
