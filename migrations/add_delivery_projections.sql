-- =====================================================
-- Delivery Projections Schema (Phase 3)
-- Solterra Logistics Portal
-- Created: 2026-01-19
-- =====================================================

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";

-- =====================================================
-- 1. DELIVERY PROJECTIONS
-- Master projection record for a project
-- =====================================================

CREATE TABLE `delivery_projections` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `project_id` int(11) NOT NULL,
  `projection_name` varchar(100) NOT NULL DEFAULT 'Default Projection',
  `is_primary` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Primary projection used for forecasts',
  `is_template` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Saved as reusable template',
  `template_name` varchar(100) DEFAULT NULL COMMENT 'Template name if is_template=1',
  `status` enum('draft','active','archived') NOT NULL DEFAULT 'draft',
  `notes` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp DEFAULT current_timestamp(),
  `updated_at` timestamp DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_projection_project` (`project_id`),
  KEY `idx_projection_primary` (`project_id`, `is_primary`),
  KEY `idx_projection_template` (`is_template`),
  KEY `idx_projection_created_by` (`created_by`),
  CONSTRAINT `fk_projection_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_projection_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- =====================================================
-- 2. PROJECTION MODULE ALLOCATIONS
-- Which modules/quantities are included in this projection
-- Supports split shipments
-- =====================================================

CREATE TABLE `projection_module_allocations` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `projection_id` int(11) NOT NULL,
  `module_id` int(11) NOT NULL COMMENT 'FK to modules.id (module batch)',
  `wattage` int(11) NOT NULL COMMENT 'Specific wattage being allocated',
  `quantity` int(11) NOT NULL COMMENT 'Number of modules allocated',
  `pallets` int(11) DEFAULT NULL COMMENT 'Calculated or manual pallet count',
  `created_at` timestamp DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_allocation_projection` (`projection_id`),
  KEY `idx_allocation_module` (`module_id`),
  CONSTRAINT `fk_allocation_projection` FOREIGN KEY (`projection_id`) REFERENCES `delivery_projections` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_allocation_module` FOREIGN KEY (`module_id`) REFERENCES `modules` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- =====================================================
-- 3. PROJECTION STOPS
-- Each stop in the journey (origin, warehouses, destination)
-- =====================================================

CREATE TABLE `projection_stops` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `projection_id` int(11) NOT NULL,
  `stop_order` int(11) NOT NULL DEFAULT 0,
  `stop_type` enum('origin','warehouse','port','customs','destination') NOT NULL,
  `location_name` varchar(255) NOT NULL,
  `location_address` varchar(500) DEFAULT NULL,
  `latitude` decimal(10,8) DEFAULT NULL,
  `longitude` decimal(11,8) DEFAULT NULL,
  `warehouse_id` int(11) DEFAULT NULL COMMENT 'FK to warehouses if existing warehouse',
  `is_customs_clearance` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Triggers customs_cleared milestone',
  `estimated_arrival_date` date DEFAULT NULL,
  `estimated_departure_date` date DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp DEFAULT current_timestamp(),
  `updated_at` timestamp DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_stop_projection` (`projection_id`),
  KEY `idx_stop_order` (`projection_id`, `stop_order`),
  KEY `idx_stop_warehouse` (`warehouse_id`),
  CONSTRAINT `fk_stop_projection` FOREIGN KEY (`projection_id`) REFERENCES `delivery_projections` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_stop_warehouse` FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- =====================================================
-- 4. PROJECTION STOP FEES
-- Fees/costs at each stop (warehousing, handling, customs)
-- =====================================================

CREATE TABLE `projection_stop_fees` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `stop_id` int(11) NOT NULL,
  `fee_type` enum('receiving','storage','outbound','customs','handling','other') NOT NULL,
  `fee_name` varchar(100) NOT NULL,
  `rate` decimal(10,2) NOT NULL,
  `rate_unit` enum('per_pallet','per_module','per_truck','per_day','flat') NOT NULL DEFAULT 'per_pallet',
  `quantity` int(11) DEFAULT NULL COMMENT 'If null, calculated from modules',
  `duration_days` int(11) DEFAULT NULL COMMENT 'For storage fees',
  `estimated_cost` decimal(12,2) NOT NULL DEFAULT 0,
  `created_at` timestamp DEFAULT current_timestamp(),
  `updated_at` timestamp DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_fee_stop` (`stop_id`),
  KEY `idx_fee_type` (`fee_type`),
  CONSTRAINT `fk_fee_stop` FOREIGN KEY (`stop_id`) REFERENCES `projection_stops` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- =====================================================
-- 5. PROJECTION LEGS
-- Shipping/delivery legs between stops
-- =====================================================

CREATE TABLE `projection_legs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `projection_id` int(11) NOT NULL,
  `from_stop_id` int(11) NOT NULL,
  `to_stop_id` int(11) NOT NULL,
  `leg_order` int(11) NOT NULL DEFAULT 0,
  `transport_mode` enum('ocean','truck','rail','air') NOT NULL DEFAULT 'truck',
  `start_date` date DEFAULT NULL,
  `end_date` date DEFAULT NULL,
  `delivery_rate` decimal(5,2) DEFAULT NULL COMMENT 'e.g., trucks per week',
  `delivery_rate_unit` enum('per_day','per_week','per_month') DEFAULT 'per_week',
  `trucks_required` int(11) DEFAULT NULL,
  `freight_cost_per_truck` decimal(10,2) DEFAULT NULL,
  `accessorial_cost_per_truck` decimal(10,2) DEFAULT NULL,
  `total_freight_cost` decimal(12,2) DEFAULT NULL,
  `triggers_milestone` enum('shipping','customs_cleared','project_delivery') DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp DEFAULT current_timestamp(),
  `updated_at` timestamp DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_leg_projection` (`projection_id`),
  KEY `idx_leg_order` (`projection_id`, `leg_order`),
  KEY `idx_leg_from_stop` (`from_stop_id`),
  KEY `idx_leg_to_stop` (`to_stop_id`),
  CONSTRAINT `fk_leg_projection` FOREIGN KEY (`projection_id`) REFERENCES `delivery_projections` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_leg_from_stop` FOREIGN KEY (`from_stop_id`) REFERENCES `projection_stops` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_leg_to_stop` FOREIGN KEY (`to_stop_id`) REFERENCES `projection_stops` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- =====================================================
-- 6. PROJECTION COST SUMMARY
-- Cached cost summary for quick retrieval
-- =====================================================

CREATE TABLE `projection_cost_summary` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `projection_id` int(11) NOT NULL,
  `module_contract_value` decimal(12,2) NOT NULL DEFAULT 0,
  `total_milestone_payments` decimal(12,2) NOT NULL DEFAULT 0,
  `total_freight_cost` decimal(12,2) NOT NULL DEFAULT 0,
  `total_warehousing_cost` decimal(12,2) NOT NULL DEFAULT 0,
  `total_customs_cost` decimal(12,2) NOT NULL DEFAULT 0,
  `total_other_cost` decimal(12,2) NOT NULL DEFAULT 0,
  `grand_total` decimal(12,2) NOT NULL DEFAULT 0,
  `last_calculated` timestamp DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_cost_summary_projection` (`projection_id`),
  CONSTRAINT `fk_summary_projection` FOREIGN KEY (`projection_id`) REFERENCES `delivery_projections` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- =====================================================
-- VIEW: Projection Summary with Costs
-- =====================================================

CREATE OR REPLACE VIEW `v_projection_summary` AS
SELECT
  dp.id AS projection_id,
  dp.project_id,
  dp.projection_name,
  dp.is_primary,
  dp.is_template,
  dp.status,
  p.project_name,
  p.project_address,
  (SELECT COUNT(*) FROM projection_stops ps WHERE ps.projection_id = dp.id) AS stop_count,
  (SELECT COUNT(*) FROM projection_legs pl WHERE pl.projection_id = dp.id) AS leg_count,
  (SELECT SUM(pma.quantity) FROM projection_module_allocations pma WHERE pma.projection_id = dp.id) AS total_modules,
  COALESCE(pcs.grand_total, 0) AS grand_total,
  COALESCE(pcs.total_freight_cost, 0) AS total_freight_cost,
  COALESCE(pcs.total_warehousing_cost, 0) AS total_warehousing_cost,
  dp.created_by,
  dp.created_at,
  dp.updated_at
FROM delivery_projections dp
LEFT JOIN projects p ON p.id = dp.project_id
LEFT JOIN projection_cost_summary pcs ON pcs.projection_id = dp.id;

-- =====================================================
-- VIEW: Project Projection Status
-- For portfolio view to show which projects have projections
-- =====================================================

CREATE OR REPLACE VIEW `v_project_projection_status` AS
SELECT
  p.id AS project_id,
  p.project_name,
  p.project_address,
  (SELECT COUNT(*) FROM delivery_projections dp WHERE dp.project_id = p.id) AS projection_count,
  (SELECT dp.id FROM delivery_projections dp WHERE dp.project_id = p.id AND dp.is_primary = 1 LIMIT 1) AS primary_projection_id,
  (SELECT dp.status FROM delivery_projections dp WHERE dp.project_id = p.id AND dp.is_primary = 1 LIMIT 1) AS primary_projection_status,
  CASE
    WHEN EXISTS(SELECT 1 FROM delivery_projections dp WHERE dp.project_id = p.id) THEN 'has_projection'
    ELSE 'no_projection'
  END AS projection_availability
FROM projects p
WHERE p.status != 'archived';

COMMIT;

-- =====================================================
-- END OF MIGRATION
-- =====================================================
