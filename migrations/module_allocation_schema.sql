-- =====================================================
-- Module Allocation / Project Planning Schema
-- Solterra Logistics Portal
-- Created: 2025-12-06
-- =====================================================

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";

-- =====================================================
-- 1. PLANNING SCENARIOS
-- =====================================================

CREATE TABLE `planning_scenarios` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `account_id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 0,
  `scenario_data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`scenario_data`)),
  `total_planned_mw` decimal(12,3) DEFAULT 0.000,
  `total_contracted_mw` decimal(12,3) DEFAULT 0.000,
  `total_allocated_mw` decimal(12,3) DEFAULT 0.000,
  `constraint_violations` int(11) DEFAULT 0,
  `last_optimized_at` datetime DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_planning_scenarios_account` (`account_id`),
  KEY `idx_planning_scenarios_active` (`account_id`, `is_active`),
  KEY `idx_planning_scenarios_created_by` (`created_by`),
  CONSTRAINT `fk_planning_scenarios_account` FOREIGN KEY (`account_id`) REFERENCES `customer_accounts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_planning_scenarios_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- =====================================================
-- 2. PLANNING MANUFACTURERS
-- =====================================================

CREATE TABLE `planning_manufacturers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `account_id` int(11) NOT NULL,
  `scenario_id` int(11) DEFAULT NULL,
  `name` varchar(255) NOT NULL,
  `short_name` varchar(100) DEFAULT NULL,
  `country` varchar(100) DEFAULT 'USA',
  `notes` text DEFAULT NULL,
  `is_approved` tinyint(1) NOT NULL DEFAULT 0,
  `approved_manufacturer_id` int(11) DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_planning_manufacturers_account` (`account_id`),
  KEY `idx_planning_manufacturers_scenario` (`scenario_id`),
  KEY `idx_planning_manufacturers_approved` (`is_approved`),
  CONSTRAINT `fk_planning_manufacturers_account` FOREIGN KEY (`account_id`) REFERENCES `customer_accounts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_planning_manufacturers_scenario` FOREIGN KEY (`scenario_id`) REFERENCES `planning_scenarios` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_planning_manufacturers_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_planning_manufacturers_real` FOREIGN KEY (`approved_manufacturer_id`) REFERENCES `manufacturers` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- =====================================================
-- 3. PLANNING FRAMEWORK CONTRACTS
-- =====================================================

CREATE TABLE `planning_framework_contracts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `account_id` int(11) NOT NULL,
  `scenario_id` int(11) DEFAULT NULL,
  `name` varchar(255) NOT NULL,
  `manufacturer_id` int(11) DEFAULT NULL,
  `planning_manufacturer_id` int(11) DEFAULT NULL,
  `equipment_type` varchar(50) NOT NULL DEFAULT 'PV',
  `module_type` varchar(255) DEFAULT NULL,
  `total_mw_dc` decimal(12,3) NOT NULL,
  `contract_start_date` date NOT NULL,
  `contract_end_date` date NOT NULL,
  `priority_score` tinyint(1) NOT NULL DEFAULT 3,
  `use_before_inventory` tinyint(1) NOT NULL DEFAULT 0,
  `regional_tags` varchar(500) DEFAULT NULL,
  `price_per_watt` decimal(10,4) DEFAULT NULL,
  `payment_trigger` enum('shipment','delivery','milestone','other') DEFAULT NULL,
  `payment_lag_days` int(11) DEFAULT NULL,
  `cash_forecast_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `notes` text DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_planning_contracts_account` (`account_id`),
  KEY `idx_planning_contracts_scenario` (`scenario_id`),
  KEY `idx_planning_contracts_manufacturer` (`manufacturer_id`),
  KEY `idx_planning_contracts_planning_manufacturer` (`planning_manufacturer_id`),
  KEY `idx_planning_contracts_dates` (`contract_start_date`, `contract_end_date`),
  KEY `idx_planning_contracts_equipment` (`equipment_type`),
  CONSTRAINT `fk_planning_contracts_account` FOREIGN KEY (`account_id`) REFERENCES `customer_accounts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_planning_contracts_scenario` FOREIGN KEY (`scenario_id`) REFERENCES `planning_scenarios` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_planning_contracts_manufacturer` FOREIGN KEY (`manufacturer_id`) REFERENCES `manufacturers` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_planning_contracts_planning_mfr` FOREIGN KEY (`planning_manufacturer_id`) REFERENCES `planning_manufacturers` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_planning_contracts_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- =====================================================
-- 4. CONTRACT PERIOD RULES
-- =====================================================

CREATE TABLE `planning_contract_period_rules` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `contract_id` int(11) NOT NULL,
  `period_start` date NOT NULL,
  `period_end` date NOT NULL,
  `min_mw` decimal(12,3) DEFAULT NULL,
  `max_mw` decimal(12,3) DEFAULT NULL,
  `is_min_hard` tinyint(1) NOT NULL DEFAULT 1,
  `is_max_hard` tinyint(1) NOT NULL DEFAULT 0,
  `notes` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_period_rules_contract` (`contract_id`),
  KEY `idx_period_rules_dates` (`period_start`, `period_end`),
  CONSTRAINT `fk_period_rules_contract` FOREIGN KEY (`contract_id`) REFERENCES `planning_framework_contracts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- =====================================================
-- 5. CONTRACT PUSH RULES
-- =====================================================

CREATE TABLE `planning_contract_push_rules` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `contract_id` int(11) NOT NULL,
  `from_period_start` date NOT NULL,
  `from_period_end` date NOT NULL,
  `to_period_start` date NOT NULL,
  `to_period_end` date NOT NULL,
  `max_mw_pushable` decimal(12,3) NOT NULL,
  `notes` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_push_rules_contract` (`contract_id`),
  CONSTRAINT `fk_push_rules_contract` FOREIGN KEY (`contract_id`) REFERENCES `planning_framework_contracts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- =====================================================
-- 6. PLANNING INVENTORY POOLS
-- =====================================================

CREATE TABLE `planning_inventory_pools` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `account_id` int(11) NOT NULL,
  `scenario_id` int(11) DEFAULT NULL,
  `name` varchar(255) NOT NULL,
  `location` varchar(255) DEFAULT NULL,
  `warehouse_id` int(11) DEFAULT NULL,
  `manufacturer_id` int(11) DEFAULT NULL,
  `planning_manufacturer_id` int(11) DEFAULT NULL,
  `module_type` varchar(255) DEFAULT NULL,
  `available_mw_dc` decimal(12,3) NOT NULL,
  `storage_cost_per_mw_month` decimal(10,2) DEFAULT NULL,
  `use_first` tinyint(1) NOT NULL DEFAULT 1,
  `priority_rank` int(11) DEFAULT 1,
  `notes` text DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_inventory_pools_account` (`account_id`),
  KEY `idx_inventory_pools_scenario` (`scenario_id`),
  KEY `idx_inventory_pools_warehouse` (`warehouse_id`),
  CONSTRAINT `fk_inventory_pools_account` FOREIGN KEY (`account_id`) REFERENCES `customer_accounts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_inventory_pools_scenario` FOREIGN KEY (`scenario_id`) REFERENCES `planning_scenarios` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_inventory_pools_warehouse` FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_inventory_pools_manufacturer` FOREIGN KEY (`manufacturer_id`) REFERENCES `manufacturers` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_inventory_pools_planning_mfr` FOREIGN KEY (`planning_manufacturer_id`) REFERENCES `planning_manufacturers` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_inventory_pools_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- =====================================================
-- 7. PLANNING PROJECTS
-- =====================================================

CREATE TABLE `planning_projects` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `account_id` int(11) NOT NULL,
  `scenario_id` int(11) DEFAULT NULL,
  `name` varchar(255) NOT NULL,
  `location_region` varchar(255) DEFAULT NULL,
  `location_address` varchar(500) DEFAULT NULL,
  `required_mw_dc` decimal(12,3) NOT NULL,
  `equipment_type` varchar(50) NOT NULL DEFAULT 'PV',
  `primary_delivery_start` date NOT NULL,
  `primary_delivery_end` date NOT NULL,
  `flex_earlier_start` date DEFAULT NULL,
  `flex_earlier_end` date DEFAULT NULL,
  `flex_earlier_max_pct` decimal(5,2) DEFAULT NULL,
  `flex_later_start` date DEFAULT NULL,
  `flex_later_end` date DEFAULT NULL,
  `flex_later_max_pct` decimal(5,2) DEFAULT NULL,
  `preferred_manufacturer_ids` varchar(500) DEFAULT NULL,
  `avoid_manufacturer_ids` varchar(500) DEFAULT NULL,
  `prefer_closest_factory` tinyint(1) NOT NULL DEFAULT 0,
  `use_inventory_first` tinyint(1) NOT NULL DEFAULT 1,
  `status` enum('draft','planned','ready','activated') NOT NULL DEFAULT 'draft',
  `activated_project_id` int(11) DEFAULT NULL,
  `activated_at` datetime DEFAULT NULL,
  `activated_by` int(11) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_planning_projects_account` (`account_id`),
  KEY `idx_planning_projects_scenario` (`scenario_id`),
  KEY `idx_planning_projects_status` (`status`),
  KEY `idx_planning_projects_delivery` (`primary_delivery_start`, `primary_delivery_end`),
  KEY `idx_planning_projects_activated` (`activated_project_id`),
  CONSTRAINT `fk_planning_projects_account` FOREIGN KEY (`account_id`) REFERENCES `customer_accounts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_planning_projects_scenario` FOREIGN KEY (`scenario_id`) REFERENCES `planning_scenarios` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_planning_projects_activated` FOREIGN KEY (`activated_project_id`) REFERENCES `projects` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_planning_projects_activated_by` FOREIGN KEY (`activated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_planning_projects_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- =====================================================
-- 8. PLANNING ALLOCATIONS
-- =====================================================

CREATE TABLE `planning_allocations` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `scenario_id` int(11) NOT NULL,
  `planning_project_id` int(11) DEFAULT NULL,
  `active_project_id` int(11) DEFAULT NULL,
  `period_start` date NOT NULL,
  `period_end` date NOT NULL,
  `source_type` enum('contract','inventory') NOT NULL,
  `contract_id` int(11) DEFAULT NULL,
  `inventory_pool_id` int(11) DEFAULT NULL,
  `allocated_mw_dc` decimal(12,3) NOT NULL,
  `is_locked` tinyint(1) NOT NULL DEFAULT 0,
  `locked_by` int(11) DEFAULT NULL,
  `locked_at` datetime DEFAULT NULL,
  `sunny_notes` text DEFAULT NULL,
  `user_notes` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_allocations_scenario` (`scenario_id`),
  KEY `idx_allocations_planning_project` (`planning_project_id`),
  KEY `idx_allocations_active_project` (`active_project_id`),
  KEY `idx_allocations_period` (`period_start`, `period_end`),
  KEY `idx_allocations_contract` (`contract_id`),
  KEY `idx_allocations_inventory` (`inventory_pool_id`),
  KEY `idx_allocations_locked` (`is_locked`),
  CONSTRAINT `fk_allocations_scenario` FOREIGN KEY (`scenario_id`) REFERENCES `planning_scenarios` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_allocations_planning_project` FOREIGN KEY (`planning_project_id`) REFERENCES `planning_projects` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_allocations_active_project` FOREIGN KEY (`active_project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_allocations_contract` FOREIGN KEY (`contract_id`) REFERENCES `planning_framework_contracts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_allocations_inventory` FOREIGN KEY (`inventory_pool_id`) REFERENCES `planning_inventory_pools` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_allocations_locked_by` FOREIGN KEY (`locked_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- =====================================================
-- 9. PLANNING RISK ALERTS
-- =====================================================

CREATE TABLE `planning_risk_alerts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `scenario_id` int(11) NOT NULL,
  `alert_type` enum('min_not_met','max_exceeded','portfolio_deficit','portfolio_surplus','storage_needed','contract_expiring','other') NOT NULL,
  `severity` enum('info','warning','critical') NOT NULL DEFAULT 'warning',
  `period_start` date DEFAULT NULL,
  `period_end` date DEFAULT NULL,
  `contract_id` int(11) DEFAULT NULL,
  `planning_project_id` int(11) DEFAULT NULL,
  `active_project_id` int(11) DEFAULT NULL,
  `message` text NOT NULL,
  `details` text DEFAULT NULL,
  `is_resolved` tinyint(1) NOT NULL DEFAULT 0,
  `resolved_at` datetime DEFAULT NULL,
  `resolved_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_risk_alerts_scenario` (`scenario_id`),
  KEY `idx_risk_alerts_type` (`alert_type`),
  KEY `idx_risk_alerts_severity` (`severity`),
  KEY `idx_risk_alerts_resolved` (`is_resolved`),
  CONSTRAINT `fk_risk_alerts_scenario` FOREIGN KEY (`scenario_id`) REFERENCES `planning_scenarios` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_risk_alerts_contract` FOREIGN KEY (`contract_id`) REFERENCES `planning_framework_contracts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_risk_alerts_planning_project` FOREIGN KEY (`planning_project_id`) REFERENCES `planning_projects` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_risk_alerts_active_project` FOREIGN KEY (`active_project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_risk_alerts_resolved_by` FOREIGN KEY (`resolved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- =====================================================
-- 10. PLANNING SUNNY NOTES
-- =====================================================

CREATE TABLE `planning_sunny_notes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `scenario_id` int(11) NOT NULL,
  `note_type` enum('optimization','user','system') NOT NULL DEFAULT 'user',
  `content` text NOT NULL,
  `linked_contract_id` int(11) DEFAULT NULL,
  `linked_planning_project_id` int(11) DEFAULT NULL,
  `linked_active_project_id` int(11) DEFAULT NULL,
  `linked_allocation_id` int(11) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_sunny_notes_scenario` (`scenario_id`),
  KEY `idx_sunny_notes_type` (`note_type`),
  KEY `idx_sunny_notes_created` (`created_at`),
  CONSTRAINT `fk_sunny_notes_scenario` FOREIGN KEY (`scenario_id`) REFERENCES `planning_scenarios` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_sunny_notes_contract` FOREIGN KEY (`linked_contract_id`) REFERENCES `planning_framework_contracts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_sunny_notes_planning_project` FOREIGN KEY (`linked_planning_project_id`) REFERENCES `planning_projects` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_sunny_notes_active_project` FOREIGN KEY (`linked_active_project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_sunny_notes_allocation` FOREIGN KEY (`linked_allocation_id`) REFERENCES `planning_allocations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_sunny_notes_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- =====================================================
-- 11. ACTIVATION REQUESTS
-- =====================================================

CREATE TABLE `planning_activation_requests` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `account_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `request_type` enum('project','scenario') NOT NULL,
  `planning_project_id` int(11) DEFAULT NULL,
  `scenario_id` int(11) DEFAULT NULL,
  `status` enum('pending','in_review','approved','rejected','cancelled') NOT NULL DEFAULT 'pending',
  `request_notes` text DEFAULT NULL,
  `admin_notes` text DEFAULT NULL,
  `reviewed_by` int(11) DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_activation_requests_account` (`account_id`),
  KEY `idx_activation_requests_user` (`user_id`),
  KEY `idx_activation_requests_status` (`status`),
  KEY `idx_activation_requests_type` (`request_type`),
  KEY `idx_activation_requests_project` (`planning_project_id`),
  KEY `idx_activation_requests_scenario` (`scenario_id`),
  CONSTRAINT `fk_activation_requests_account` FOREIGN KEY (`account_id`) REFERENCES `customer_accounts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_activation_requests_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_activation_requests_project` FOREIGN KEY (`planning_project_id`) REFERENCES `planning_projects` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_activation_requests_scenario` FOREIGN KEY (`scenario_id`) REFERENCES `planning_scenarios` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_activation_requests_reviewed_by` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- =====================================================
-- 12. ADD NOTIFICATION SETTINGS FOR NEW FEATURE
-- =====================================================

ALTER TABLE `notification_settings`
  ADD COLUMN `in_app_planning_activation_request` tinyint(1) NOT NULL DEFAULT 1 AFTER `email_warehouse_estimate_rated`,
  ADD COLUMN `in_app_planning_activation_approved` tinyint(1) NOT NULL DEFAULT 1 AFTER `in_app_planning_activation_request`,
  ADD COLUMN `email_planning_activation_request` tinyint(1) NOT NULL DEFAULT 0 AFTER `in_app_planning_activation_approved`,
  ADD COLUMN `email_planning_activation_approved` tinyint(1) NOT NULL DEFAULT 0 AFTER `email_planning_activation_request`;

COMMIT;

-- =====================================================
-- 13. VIEWS (Run separately if needed)
-- =====================================================

-- View: Planning Projects with Allocation Summary
CREATE OR REPLACE VIEW `v_planning_projects_summary` AS
SELECT
  pp.id,
  pp.account_id,
  pp.scenario_id,
  pp.name,
  pp.location_region,
  pp.required_mw_dc,
  pp.primary_delivery_start,
  pp.primary_delivery_end,
  pp.status,
  COALESCE(SUM(pa.allocated_mw_dc), 0) AS total_allocated_mw,
  pp.required_mw_dc - COALESCE(SUM(pa.allocated_mw_dc), 0) AS remaining_mw,
  CASE
    WHEN COALESCE(SUM(pa.allocated_mw_dc), 0) >= pp.required_mw_dc THEN 'fully_allocated'
    WHEN COALESCE(SUM(pa.allocated_mw_dc), 0) > 0 THEN 'partial'
    ELSE 'unallocated'
  END AS allocation_status,
  pp.created_at,
  pp.updated_at
FROM planning_projects pp
LEFT JOIN planning_allocations pa ON pa.planning_project_id = pp.id
GROUP BY pp.id;

-- View: Contract Utilization Summary
CREATE OR REPLACE VIEW `v_contract_utilization` AS
SELECT
  pfc.id AS contract_id,
  pfc.account_id,
  pfc.scenario_id,
  pfc.name AS contract_name,
  pfc.total_mw_dc,
  COALESCE(SUM(pa.allocated_mw_dc), 0) AS allocated_mw,
  pfc.total_mw_dc - COALESCE(SUM(pa.allocated_mw_dc), 0) AS remaining_mw,
  ROUND((COALESCE(SUM(pa.allocated_mw_dc), 0) / pfc.total_mw_dc) * 100, 2) AS utilization_pct,
  pfc.contract_start_date,
  pfc.contract_end_date,
  pfc.priority_score
FROM planning_framework_contracts pfc
LEFT JOIN planning_allocations pa ON pa.contract_id = pfc.id
GROUP BY pfc.id;

-- View: Portfolio Summary by Scenario
CREATE OR REPLACE VIEW `v_portfolio_summary` AS
SELECT
  ps.id AS scenario_id,
  ps.account_id,
  ps.name AS scenario_name,
  ps.is_active,
  COALESCE(SUM(DISTINCT pp.required_mw_dc), 0) AS total_planned_mw,
  COALESCE(SUM(DISTINCT pfc.total_mw_dc), 0) AS total_contracted_mw,
  COALESCE(alloc.total_allocated, 0) AS total_allocated_mw,
  COALESCE(SUM(DISTINCT pfc.total_mw_dc), 0) - COALESCE(SUM(DISTINCT pp.required_mw_dc), 0) AS surplus_deficit_mw,
  (SELECT COUNT(*) FROM planning_risk_alerts pra WHERE pra.scenario_id = ps.id AND pra.is_resolved = 0) AS open_alerts,
  ps.last_optimized_at,
  ps.created_at,
  ps.updated_at
FROM planning_scenarios ps
LEFT JOIN planning_projects pp ON pp.scenario_id = ps.id OR (pp.scenario_id IS NULL AND pp.account_id = ps.account_id)
LEFT JOIN planning_framework_contracts pfc ON pfc.scenario_id = ps.id OR (pfc.scenario_id IS NULL AND pfc.account_id = ps.account_id)
LEFT JOIN (
  SELECT scenario_id, SUM(allocated_mw_dc) AS total_allocated
  FROM planning_allocations
  GROUP BY scenario_id
) alloc ON alloc.scenario_id = ps.id
GROUP BY ps.id;

-- =====================================================
-- END OF MIGRATION
-- =====================================================
