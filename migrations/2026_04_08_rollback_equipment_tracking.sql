-- Rollback: Equipment Tracking Schema
-- Prepared: 2026-04-08
-- Reverses: 2026_03_30_add_equipment_delivery_links.sql
--           2026_03_30_add_equipment_tracking.sql
--
-- ⚠️  IMPORTANT: If any equipment data has been inserted into these tables,
--     this script will DELETE it permanently. Verify tables are empty first:
--
--     SELECT 'delivery_equipment_units' AS tbl, COUNT(*) AS cnt FROM delivery_equipment_units
--     UNION ALL SELECT 'delivery_equipment_items', COUNT(*) FROM delivery_equipment_items
--     UNION ALL SELECT 'equipment_status_history', COUNT(*) FROM equipment_status_history
--     UNION ALL SELECT 'equipment_milestone_instances', COUNT(*) FROM equipment_milestone_instances
--     UNION ALL SELECT 'equipment_item_milestones', COUNT(*) FROM equipment_item_milestones
--     UNION ALL SELECT 'project_equipment_units', COUNT(*) FROM project_equipment_units
--     UNION ALL SELECT 'project_equipment_items', COUNT(*) FROM project_equipment_items
--     UNION ALL SELECT 'equipment_types', COUNT(*) FROM equipment_types;

START TRANSACTION;

-- -------------------------------------------------------
-- 1. Drop delivery-equipment link tables (migration 2)
-- -------------------------------------------------------

DROP TABLE IF EXISTS `delivery_equipment_units`;
DROP TABLE IF EXISTS `delivery_equipment_items`;

-- -------------------------------------------------------
-- 2. Remove equipment columns from project_documents
--    (drop foreign keys first, then indexes, then columns)
-- -------------------------------------------------------

ALTER TABLE `project_documents`
  DROP FOREIGN KEY `fk_project_documents_equipment_milestone`,
  DROP FOREIGN KEY `fk_project_documents_equipment_unit`,
  DROP FOREIGN KEY `fk_project_documents_equipment_item`;

ALTER TABLE `project_documents`
  DROP INDEX `fk_project_documents_equipment_milestone`,
  DROP INDEX `fk_project_documents_equipment_unit`,
  DROP INDEX `fk_project_documents_equipment_item`;

ALTER TABLE `project_documents`
  DROP COLUMN `equipment_milestone_id`,
  DROP COLUMN `equipment_unit_id`,
  DROP COLUMN `equipment_item_id`;

-- -------------------------------------------------------
-- 3. Drop equipment tables (dependency order)
-- -------------------------------------------------------

DROP TABLE IF EXISTS `equipment_status_history`;
DROP TABLE IF EXISTS `equipment_milestone_instances`;
DROP TABLE IF EXISTS `equipment_item_milestones`;
DROP TABLE IF EXISTS `project_equipment_units`;
DROP TABLE IF EXISTS `project_equipment_items`;
DROP TABLE IF EXISTS `equipment_types`;

COMMIT;
