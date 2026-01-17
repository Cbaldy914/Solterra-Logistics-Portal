-- Migration: Add payment milestones tables
-- Date: 2026-01-16
-- Description: Adds optional payment milestone tracking for module batches.
--              Milestones are configured at the module batch level and triggered
--              when deliveries hit status changes (PO execution, customs cleared,
--              warehouse arrival, project delivery).

-- Table 1: module_batch_milestones (Configuration at module batch level)
CREATE TABLE IF NOT EXISTS `module_batch_milestones` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `module_id` int(11) NOT NULL COMMENT 'FK to modules.id (module batch)',
  `milestone_name` varchar(100) NOT NULL COMMENT 'e.g., PO Execution, Customs Clearance',
  `trigger_event` enum('po_execution','customs_cleared','warehouse_arrival','project_delivery') NOT NULL,
  `percentage` decimal(5,2) NOT NULL COMMENT 'Percentage of total value (0.00 - 100.00)',
  `display_order` int(11) DEFAULT 1 COMMENT 'Order for display purposes',
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_module_id` (`module_id`),
  KEY `idx_trigger_event` (`trigger_event`),
  CONSTRAINT `fk_milestones_module` FOREIGN KEY (`module_id`) REFERENCES `modules` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='Payment milestone configuration at module batch level';

-- Table 2: delivery_milestone_instances (Per-delivery triggered records)
CREATE TABLE IF NOT EXISTS `delivery_milestone_instances` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `delivery_id` int(11) NOT NULL COMMENT 'FK to deliveries.id',
  `milestone_id` int(11) NOT NULL COMMENT 'FK to module_batch_milestones.id',
  `triggered_at` datetime NOT NULL COMMENT 'When the milestone was triggered',
  `triggered_by_user_id` int(11) DEFAULT NULL COMMENT 'User who triggered the status change',
  `module_quantity` int(11) NOT NULL COMMENT 'Number of modules in delivery at trigger time',
  `wattage` int(11) NOT NULL COMMENT 'Module wattage',
  `cost_per_watt` decimal(10,6) DEFAULT NULL COMMENT 'Snapshot of cost_per_watt at trigger time',
  `milestone_percentage` decimal(5,2) NOT NULL COMMENT 'Snapshot of percentage at trigger time',
  `payment_amount` decimal(12,2) NOT NULL COMMENT 'Calculated: cost_per_watt * wattage * quantity * percentage',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_delivery_milestone` (`delivery_id`, `milestone_id`) COMMENT 'Prevent duplicate triggers',
  KEY `idx_delivery_id` (`delivery_id`),
  KEY `idx_milestone_id` (`milestone_id`),
  KEY `idx_triggered_at` (`triggered_at`),
  CONSTRAINT `fk_instance_delivery` FOREIGN KEY (`delivery_id`) REFERENCES `deliveries` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_instance_milestone` FOREIGN KEY (`milestone_id`) REFERENCES `module_batch_milestones` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='Records of payment milestones triggered per delivery';
