-- =====================================================
-- Module Allocation Schema Update
-- Run AFTER the initial module_allocation_schema.sql
-- =====================================================

-- 1. Make scenario_id required on planning_manufacturers
ALTER TABLE `planning_manufacturers`
  MODIFY `scenario_id` int(11) NOT NULL,
  DROP FOREIGN KEY `fk_planning_manufacturers_scenario`;

ALTER TABLE `planning_manufacturers`
  ADD CONSTRAINT `fk_planning_manufacturers_scenario` FOREIGN KEY (`scenario_id`) REFERENCES `planning_scenarios` (`id`) ON DELETE CASCADE;

-- 2. Make scenario_id required on planning_framework_contracts
ALTER TABLE `planning_framework_contracts`
  MODIFY `scenario_id` int(11) NOT NULL,
  DROP FOREIGN KEY `fk_planning_contracts_scenario`;

ALTER TABLE `planning_framework_contracts`
  ADD CONSTRAINT `fk_planning_contracts_scenario` FOREIGN KEY (`scenario_id`) REFERENCES `planning_scenarios` (`id`) ON DELETE CASCADE;

-- 3. Make scenario_id required on planning_inventory_pools
ALTER TABLE `planning_inventory_pools`
  MODIFY `scenario_id` int(11) NOT NULL,
  DROP FOREIGN KEY `fk_inventory_pools_scenario`;

ALTER TABLE `planning_inventory_pools`
  ADD CONSTRAINT `fk_inventory_pools_scenario` FOREIGN KEY (`scenario_id`) REFERENCES `planning_scenarios` (`id`) ON DELETE CASCADE;

-- 4. Make scenario_id required on planning_projects
ALTER TABLE `planning_projects`
  MODIFY `scenario_id` int(11) NOT NULL,
  DROP FOREIGN KEY `fk_planning_projects_scenario`;

ALTER TABLE `planning_projects`
  ADD CONSTRAINT `fk_planning_projects_scenario` FOREIGN KEY (`scenario_id`) REFERENCES `planning_scenarios` (`id`) ON DELETE CASCADE;

-- 5. Add period_type to planning_contract_period_rules
ALTER TABLE `planning_contract_period_rules`
  ADD COLUMN `period_type` enum('quarter','month','custom') NOT NULL DEFAULT 'quarter' AFTER `contract_id`;

-- 6. Add period_type to planning_allocations
ALTER TABLE `planning_allocations`
  ADD COLUMN `period_type` enum('quarter','month','custom') NOT NULL DEFAULT 'quarter' AFTER `period_end`;

-- 7. Add period_type to planning_projects for delivery window display
ALTER TABLE `planning_projects`
  ADD COLUMN `delivery_period_type` enum('quarter','month','custom') NOT NULL DEFAULT 'quarter' AFTER `primary_delivery_end`;

-- Done!
