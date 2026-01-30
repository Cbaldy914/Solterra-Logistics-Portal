-- Migration: Separate projection modules from actual modules
-- Projection manual entry modules now save to dedicated tables
-- instead of polluting the real modules/unassigned_module_items tables.

-- Projection-only modules (mirrors modules table structure)
CREATE TABLE IF NOT EXISTS projection_modules (
  id int(11) PRIMARY KEY AUTO_INCREMENT,
  account_id int(11) NOT NULL,
  projection_id int(11),               -- FK to delivery_projections.id
  vendor_name varchar(255) NOT NULL,
  initial_location varchar(255) NOT NULL,
  cost_per_watt decimal(10,6),
  modules_per_pallet int(11),
  pallets_per_truck int(11),
  modules_per_truck int(11),
  module_notes text,
  created_at timestamp DEFAULT CURRENT_TIMESTAMP,
  last_updated_at timestamp ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (projection_id) REFERENCES delivery_projections(id) ON DELETE CASCADE
);

-- Projection-only module items (mirrors unassigned_module_items)
CREATE TABLE IF NOT EXISTS projection_module_items (
  id int(11) PRIMARY KEY AUTO_INCREMENT,
  projection_module_id int(11) NOT NULL,
  wattage int(11) NOT NULL,
  quantity int(11) NOT NULL,
  created_at timestamp DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (projection_module_id) REFERENCES projection_modules(id) ON DELETE CASCADE
);

-- Projection-only module milestones (mirrors module_batch_milestones)
CREATE TABLE IF NOT EXISTS projection_module_milestones (
  id int(11) PRIMARY KEY AUTO_INCREMENT,
  projection_module_id int(11) NOT NULL,
  milestone_name varchar(100) NOT NULL,
  trigger_event enum('po_execution','shipping','customs_cleared','project_delivery') NOT NULL,
  percentage decimal(5,2) NOT NULL DEFAULT 0.00,
  display_order int(11) DEFAULT 0,
  is_active tinyint(1) DEFAULT 1,
  created_at timestamp DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (projection_module_id) REFERENCES projection_modules(id) ON DELETE CASCADE
);

-- Add flag to projection_module_allocations to track source table
ALTER TABLE projection_module_allocations
  ADD COLUMN is_projection_module TINYINT(1) NOT NULL DEFAULT 0 AFTER pallets;
