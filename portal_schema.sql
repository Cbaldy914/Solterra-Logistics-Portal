-- =====================================================
-- Solterra Logistics Portal - Database Schema Summary
-- Generated: January 19, 2026
-- Purpose: Quick reference for database structure
-- =====================================================

-- =====================================================
-- CORE ACCOUNT & USER TABLES
-- =====================================================

-- Customer accounts (companies using the portal)
CREATE TABLE customer_accounts (
  id int(11) PRIMARY KEY AUTO_INCREMENT,
  name varchar(255) NOT NULL,
  created_at datetime DEFAULT CURRENT_TIMESTAMP,
  updated_at datetime ON UPDATE CURRENT_TIMESTAMP
);

-- Users of the portal
CREATE TABLE users (
  id int(11) PRIMARY KEY AUTO_INCREMENT,
  username varchar(50) UNIQUE NOT NULL,
  email varchar(100) UNIQUE NOT NULL,
  password_hash varchar(255) NOT NULL,
  role enum('user','admin','global_admin','customer_admin','DDPm','carrier') NOT NULL,
  created_at datetime DEFAULT CURRENT_TIMESTAMP,
  last_login datetime,
  is_active tinyint(1) DEFAULT 1
);

-- Links users to customer accounts with roles
CREATE TABLE customer_account_users (
  id int(11) PRIMARY KEY AUTO_INCREMENT,
  user_id int(11) NOT NULL,           -- FK to users.id
  account_id int(11) NOT NULL,        -- FK to customer_accounts.id
  role varchar(50) NOT NULL,          -- admin, user, customer_admin, DDPm, carrier
  created_at datetime DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id),
  FOREIGN KEY (account_id) REFERENCES customer_accounts(id)
);

-- =====================================================
-- PROJECT TABLES
-- =====================================================

-- Solar projects being tracked
CREATE TABLE projects (
  id int(11) PRIMARY KEY AUTO_INCREMENT,
  account_id int(11) NOT NULL,        -- FK to customer_accounts.id
  project_name varchar(255) NOT NULL,
  project_address varchar(255),
  street_address varchar(255),
  city varchar(100),
  state varchar(50),
  zip_code varchar(20),
  image_url varchar(255),
  project_size float DEFAULT 0,       -- MW size
  estimated_completion_date date,
  warehouse_id int(11),               -- FK to warehouses.id
  default_freight_cost decimal(10,2),
  solterra_fee decimal(10,4) DEFAULT 0,
  status enum('active','closed') DEFAULT 'active',
  -- Site contact info
  site_contact_name varchar(255),
  site_contact_email varchar(255),
  site_contact_phone varchar(50),
  phone1 varchar(50),
  phone2 varchar(50),
  timezone varchar(50),
  -- Health tracking
  manual_health_status enum('on_track','at_risk','behind'),
  manual_health_reason text,
  created_at timestamp DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (account_id) REFERENCES customer_accounts(id)
);

-- Archived/closed projects
CREATE TABLE archived_projects (
  id int(11) PRIMARY KEY AUTO_INCREMENT,
  project_id int(11) NOT NULL,
  account_id int(11),
  project_name varchar(255) NOT NULL,
  archive_path varchar(500) NOT NULL,
  archive_filename varchar(255) NOT NULL,
  file_size_bytes bigint DEFAULT 0,
  closed_by int(11) NOT NULL,
  closed_at datetime DEFAULT CURRENT_TIMESTAMP,
  summary_text text,
  delivery_percent decimal(5,2) DEFAULT 0,
  total_modules int(11) DEFAULT 0,
  delivered_modules int(11) DEFAULT 0,
  -- Cost metrics
  total_module_cost decimal(12,2) DEFAULT 0,
  total_freight_cost decimal(12,2) DEFAULT 0,
  total_warehousing_cost decimal(12,2) DEFAULT 0,
  -- Sustainability metrics
  total_miles decimal(12,2) DEFAULT 0,
  total_fuel_gallons decimal(12,2) DEFAULT 0,
  total_co2_kg decimal(12,2) DEFAULT 0
);

-- =====================================================
-- MODULE TABLES
-- =====================================================

-- Module batches (groups of modules from a manufacturer)
CREATE TABLE modules (
  id int(11) PRIMARY KEY AUTO_INCREMENT,
  account_id int(11) NOT NULL,
  project_id int(11),                 -- FK to projects.id
  vendor_name varchar(255) NOT NULL,  -- Manufacturer name
  initial_location varchar(255) NOT NULL,
  cost_per_watt decimal(10,6),        -- Price per watt
  -- Pallet configuration
  modules_per_pallet int(11),
  pallets_per_truck int(11),
  modules_per_truck int(11),
  -- Physical dimensions
  pallet_length_mm int(11),
  pallet_depth_mm int(11),
  pallet_double_stacked_height_mm int(11),
  pallet_total_weight_kg int(11),
  -- Notes
  module_notes text,
  module_docs_url text,
  data_source enum('manual','schedule_import') DEFAULT 'manual',
  created_at timestamp DEFAULT CURRENT_TIMESTAMP,
  last_updated_at timestamp ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (project_id) REFERENCES projects(id)
);

-- Individual module items (wattage/quantity for a batch)
CREATE TABLE unassigned_module_items (
  id int(11) PRIMARY KEY AUTO_INCREMENT,
  unassigned_module_id int(11) NOT NULL,  -- FK to modules.id
  wattage int(11) NOT NULL,
  quantity int(11) NOT NULL,
  created_at timestamp DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (unassigned_module_id) REFERENCES modules(id)
);

-- Payment milestones for module batches
CREATE TABLE module_batch_milestones (
  id int(11) PRIMARY KEY AUTO_INCREMENT,
  module_id int(11) NOT NULL,         -- FK to modules.id
  milestone_name varchar(100) NOT NULL,
  trigger_event enum('po_execution','customs_cleared','shipping','project_delivery') NOT NULL,
  percentage decimal(5,2) NOT NULL,   -- 0.00 to 100.00
  display_order int(11) DEFAULT 1,
  is_active tinyint(1) DEFAULT 1,
  created_at timestamp DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (module_id) REFERENCES modules(id)
);

-- =====================================================
-- DELIVERY TABLES
-- =====================================================

-- Actual deliveries
CREATE TABLE deliveries (
  id int(11) PRIMARY KEY AUTO_INCREMENT,
  project_id int(11),                 -- FK to projects.id
  supplier varchar(255) NOT NULL,     -- Manufacturer/vendor name
  wattage int(11) NOT NULL,
  quantity int(11) NOT NULL,
  status_of_delivery varchar(50) NOT NULL,
  bol_number varchar(50) NOT NULL,    -- Bill of Lading
  -- Dates
  anticipated_delivery_date date,
  warehouse_arrival_date date,
  left_warehouse_date date,
  actual_delivery_date date,
  -- Origin tracking
  origin_type enum('manufacturer','warehouse','project'),
  origin_id int(11),
  warehouse_id int(11),               -- FK to warehouses.id
  -- Costs
  freight_cost decimal(10,2) DEFAULT 0,
  accessorial_costs decimal(10,2) DEFAULT 0,
  customer_cost decimal(10,2) DEFAULT 0,
  miles double,
  -- Payment tracking
  pay_status enum('Unpaid','Paid','Partial') DEFAULT 'Unpaid',
  pay_date date,
  invoice_number varchar(50),
  invoice_id int(11),
  -- Overseas shipment fields
  is_overseas_shipment tinyint(1) DEFAULT 0,
  master_bol varchar(100),
  house_bol varchar(100),
  container_number varchar(50),
  port_of_entry_id int(11),           -- FK to warehouses.id (port)
  origin_port_id int(11),
  customs_cleared_date date,
  -- Documents
  proof_of_delivery varchar(255),
  scheduled tinyint(1) DEFAULT 0,
  data_source enum('manual','schedule_import') DEFAULT 'manual',
  created_at timestamp DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (project_id) REFERENCES projects(id)
);

-- Pallets linked to deliveries
CREATE TABLE delivery_pallets (
  id int(11) PRIMARY KEY AUTO_INCREMENT,
  delivery_id int(11) NOT NULL,       -- FK to deliveries.id
  pallet_id int(11) NOT NULL,         -- FK to inventory_pallets.id
  created_at timestamp DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (delivery_id) REFERENCES deliveries(id)
);

-- Milestone payment instances for deliveries
CREATE TABLE delivery_milestone_instances (
  id int(11) PRIMARY KEY AUTO_INCREMENT,
  delivery_id int(11) NOT NULL,
  milestone_id int(11) NOT NULL,      -- FK to module_batch_milestones.id
  trigger_event varchar(50) NOT NULL,
  milestone_name varchar(100) NOT NULL,
  percentage decimal(5,2) NOT NULL,
  total_wattage bigint NOT NULL,
  cost_per_watt decimal(10,6) NOT NULL,
  payment_amount decimal(12,2) NOT NULL,
  is_triggered tinyint(1) DEFAULT 0,
  triggered_at datetime,
  created_at timestamp DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (delivery_id) REFERENCES deliveries(id)
);

-- =====================================================
-- DELIVERY PROJECTION TABLES (Cost Planning)
-- =====================================================

-- Delivery projections (cost planning scenarios)
CREATE TABLE delivery_projections (
  id int(11) PRIMARY KEY AUTO_INCREMENT,
  project_id int(11) NOT NULL,
  projection_name varchar(100) DEFAULT 'Default Projection',
  is_primary tinyint(1) DEFAULT 0,    -- Main projection for project
  is_template tinyint(1) DEFAULT 0,   -- Save as reusable template
  template_name varchar(100),
  status enum('draft','active','archived') DEFAULT 'draft',
  notes text,
  created_by int(11),                 -- FK to users.id
  created_at timestamp DEFAULT CURRENT_TIMESTAMP,
  updated_at timestamp ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (project_id) REFERENCES projects(id)
);

-- Module allocations within a projection
CREATE TABLE projection_module_allocations (
  id int(11) PRIMARY KEY AUTO_INCREMENT,
  projection_id int(11) NOT NULL,     -- FK to delivery_projections.id
  module_id int(11) NOT NULL,         -- FK to modules.id or projection_modules.id
  wattage int(11) NOT NULL,
  quantity int(11) NOT NULL,
  pallets int(11),
  is_projection_module tinyint(1) NOT NULL DEFAULT 0,  -- 1 = module_id refers to projection_modules
  created_at timestamp DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (projection_id) REFERENCES delivery_projections(id)
);

-- Projection-only modules (manual entries that don't pollute the real modules table)
CREATE TABLE projection_modules (
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

-- Projection-only module items
CREATE TABLE projection_module_items (
  id int(11) PRIMARY KEY AUTO_INCREMENT,
  projection_module_id int(11) NOT NULL,
  wattage int(11) NOT NULL,
  quantity int(11) NOT NULL,
  created_at timestamp DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (projection_module_id) REFERENCES projection_modules(id) ON DELETE CASCADE
);

-- Projection-only module milestones
CREATE TABLE projection_module_milestones (
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

-- Stops in a delivery journey (origin, warehouses, destination)
CREATE TABLE projection_stops (
  id int(11) PRIMARY KEY AUTO_INCREMENT,
  projection_id int(11) NOT NULL,     -- FK to delivery_projections.id
  stop_order int(11) DEFAULT 0,
  stop_type enum('origin','warehouse','port','customs','destination') NOT NULL,
  location_name varchar(255) NOT NULL,
  location_address varchar(500),
  latitude decimal(10,8),
  longitude decimal(11,8),
  warehouse_id int(11),               -- FK to warehouses.id
  is_customs_clearance tinyint(1) DEFAULT 0,
  estimated_arrival_date date,
  estimated_departure_date date,
  notes text,
  created_at timestamp DEFAULT CURRENT_TIMESTAMP,
  updated_at timestamp ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (projection_id) REFERENCES delivery_projections(id)
);

-- Fees at each stop (warehousing, customs, etc.)
CREATE TABLE projection_stop_fees (
  id int(11) PRIMARY KEY AUTO_INCREMENT,
  stop_id int(11) NOT NULL,           -- FK to projection_stops.id
  fee_type enum('receiving','storage','outbound','customs','handling','other') NOT NULL,
  fee_name varchar(100) NOT NULL,
  rate decimal(10,2) NOT NULL,
  rate_unit enum('per_pallet','per_module','per_truck','per_day','flat') DEFAULT 'per_pallet',
  quantity int(11),
  duration_days int(11),
  estimated_cost decimal(12,2) DEFAULT 0,
  created_at timestamp DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (stop_id) REFERENCES projection_stops(id)
);

-- Shipping legs between stops
CREATE TABLE projection_legs (
  id int(11) PRIMARY KEY AUTO_INCREMENT,
  projection_id int(11) NOT NULL,     -- FK to delivery_projections.id
  from_stop_id int(11) NOT NULL,      -- FK to projection_stops.id
  to_stop_id int(11) NOT NULL,        -- FK to projection_stops.id
  leg_order int(11) DEFAULT 0,
  transport_mode enum('ocean','truck','rail','air') DEFAULT 'truck',
  start_date date,
  end_date date,
  delivery_rate decimal(5,2),
  delivery_rate_unit enum('per_day','per_week','per_month') DEFAULT 'per_week',
  trucks_required int(11),
  freight_cost_per_truck decimal(10,2),
  accessorial_cost_per_truck decimal(10,2),
  total_freight_cost decimal(12,2),
  triggers_milestone enum('shipping','customs_cleared','project_delivery'),
  notes text,
  created_at timestamp DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (projection_id) REFERENCES delivery_projections(id)
);

-- Cached cost summary for projections
CREATE TABLE projection_cost_summary (
  id int(11) PRIMARY KEY AUTO_INCREMENT,
  projection_id int(11) NOT NULL,
  module_contract_value decimal(12,2) DEFAULT 0,
  total_milestone_payments decimal(12,2) DEFAULT 0,
  total_freight_cost decimal(12,2) DEFAULT 0,
  total_warehousing_cost decimal(12,2) DEFAULT 0,
  total_customs_cost decimal(12,2) DEFAULT 0,
  total_other_cost decimal(12,2) DEFAULT 0,
  grand_total decimal(12,2) DEFAULT 0,
  last_calculated timestamp ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (projection_id) REFERENCES delivery_projections(id)
);

-- =====================================================
-- WAREHOUSE & INVENTORY TABLES
-- =====================================================

-- Warehouses and ports
CREATE TABLE warehouses (
  id int(11) PRIMARY KEY AUTO_INCREMENT,
  name varchar(255) NOT NULL,
  code varchar(50),
  street_address varchar(255) NOT NULL,
  city varchar(100),
  state varchar(50),
  zip varchar(20),
  country varchar(100) DEFAULT 'USA',
  latitude decimal(10,8),
  longitude decimal(11,8),
  is_port tinyint(1) DEFAULT 0,       -- Port of entry
  is_active tinyint(1) DEFAULT 1,
  contact_name varchar(255),
  contact_phone varchar(50),
  contact_email varchar(255),
  -- Operating hours
  hours_monday varchar(50),
  hours_tuesday varchar(50),
  hours_wednesday varchar(50),
  hours_thursday varchar(50),
  hours_friday varchar(50),
  hours_saturday varchar(50),
  hours_sunday varchar(50),
  notes text,
  created_at timestamp DEFAULT CURRENT_TIMESTAMP
);

-- Pallets in inventory
CREATE TABLE inventory_pallets (
  id int(11) PRIMARY KEY AUTO_INCREMENT,
  project_id int(11),                 -- FK to projects.id
  module_batch_id int(11),            -- FK to modules.id
  warehouse_id int(11),               -- FK to warehouses.id
  pallet_number varchar(50),
  wattage int(11),
  quantity int(11) DEFAULT 0,
  status enum('in_transit','in_warehouse','delivered','damaged') DEFAULT 'in_warehouse',
  received_date date,
  created_at timestamp DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (project_id) REFERENCES projects(id),
  FOREIGN KEY (warehouse_id) REFERENCES warehouses(id)
);

-- Warehouse cost items
CREATE TABLE warehouse_cost_items (
  id int(11) PRIMARY KEY AUTO_INCREMENT,
  warehouse_id int(11) NOT NULL,
  cost_type enum('entry_fee','exit_fee','monthly_storage','handling','other') NOT NULL,
  description varchar(255) NOT NULL,
  rate decimal(10,2) NOT NULL,
  rate_unit enum('per_pallet','per_module','per_truck','per_month','flat') DEFAULT 'per_pallet',
  is_active tinyint(1) DEFAULT 1,
  FOREIGN KEY (warehouse_id) REFERENCES warehouses(id)
);

-- =====================================================
-- MANUFACTURER TABLES
-- =====================================================

-- Manufacturers
CREATE TABLE manufacturers (
  id int(11) PRIMARY KEY AUTO_INCREMENT,
  name varchar(255) NOT NULL,
  website varchar(255),
  headquarters_country varchar(100),
  is_active tinyint(1) DEFAULT 1,
  notes text,
  created_at timestamp DEFAULT CURRENT_TIMESTAMP
);

-- Manufacturer locations (factories)
CREATE TABLE manufacturer_locations (
  id int(11) PRIMARY KEY AUTO_INCREMENT,
  manufacturer_id int(11) NOT NULL,
  name varchar(255),
  street_address varchar(255),
  city varchar(100),
  state varchar(50),
  zip_code varchar(20),
  country varchar(100) DEFAULT 'USA',
  is_primary tinyint(1) DEFAULT 0,
  is_active tinyint(1) DEFAULT 1,
  FOREIGN KEY (manufacturer_id) REFERENCES manufacturers(id)
);

-- =====================================================
-- SCHEDULING TABLES
-- =====================================================

-- Anticipated delivery schedules
CREATE TABLE anticipated_delivery_schedule (
  id int(11) PRIMARY KEY AUTO_INCREMENT,
  project_id int(11) NOT NULL,
  schedule_type enum('quick','detailed') DEFAULT 'quick',
  delivery_start_date date NOT NULL,
  quick_rate_value decimal(10,3),     -- e.g., 2.5 MW
  quick_rate_unit enum('mw','modules','pallets','trucks'),
  quick_rate_frequency enum('per_week','per_month') DEFAULT 'per_week',
  is_active tinyint(1) DEFAULT 1,
  created_by int(11) NOT NULL,
  notes text,
  created_at timestamp DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (project_id) REFERENCES projects(id)
);

-- Detailed weekly delivery amounts
CREATE TABLE anticipated_delivery_schedule_details (
  id int(11) PRIMARY KEY AUTO_INCREMENT,
  schedule_id int(11) NOT NULL,
  delivery_week_ending date NOT NULL,
  delivery_amount decimal(10,3) NOT NULL,
  delivery_unit enum('mw','modules','pallets','trucks') NOT NULL,
  notes text,
  created_at timestamp DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (schedule_id) REFERENCES anticipated_delivery_schedule(id)
);

-- Schedule uploads (CSV imports)
CREATE TABLE schedule_uploads (
  id int(11) PRIMARY KEY AUTO_INCREMENT,
  project_id int(11) NOT NULL,
  filename varchar(255) NOT NULL,
  original_filename varchar(255),
  uploaded_by int(11) NOT NULL,
  row_count int(11) DEFAULT 0,
  status enum('pending','processing','completed','failed') DEFAULT 'pending',
  error_message text,
  created_at timestamp DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (project_id) REFERENCES projects(id)
);

-- =====================================================
-- NOTIFICATION TABLES
-- =====================================================

CREATE TABLE notifications (
  id int(10) UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  user_id int(11) NOT NULL,
  type varchar(64) NOT NULL,
  title varchar(190) NOT NULL,
  message text,
  link varchar(255),
  read_at datetime,
  created_at datetime DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id)
);

CREATE TABLE notification_settings (
  id int(11) PRIMARY KEY AUTO_INCREMENT,
  user_id int(11) NOT NULL,
  notification_type varchar(64) NOT NULL,
  email_enabled tinyint(1) DEFAULT 1,
  push_enabled tinyint(1) DEFAULT 1,
  UNIQUE KEY (user_id, notification_type),
  FOREIGN KEY (user_id) REFERENCES users(id)
);

-- =====================================================
-- FINANCIAL TABLES
-- =====================================================

-- Accounts payable
CREATE TABLE accounts_payable (
  id int(11) PRIMARY KEY AUTO_INCREMENT,
  vendor_name varchar(255) NOT NULL,
  project_id int(11) NOT NULL,
  paid_date date NOT NULL,
  amount decimal(10,2) DEFAULT 0,
  notes text,
  receipt_file varchar(255),
  created_at datetime DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (project_id) REFERENCES projects(id)
);

-- Project invoices
CREATE TABLE project_invoices (
  id int(11) PRIMARY KEY AUTO_INCREMENT,
  project_id int(11) NOT NULL,
  invoice_number varchar(50),
  invoice_date date,
  due_date date,
  amount decimal(12,2) DEFAULT 0,
  status enum('draft','sent','paid','overdue') DEFAULT 'draft',
  notes text,
  created_at timestamp DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (project_id) REFERENCES projects(id)
);

-- =====================================================
-- VENDOR/CARRIER TABLES
-- =====================================================

CREATE TABLE vendors (
  id int(11) PRIMARY KEY AUTO_INCREMENT,
  name varchar(255) NOT NULL,
  contact_email varchar(255),
  contact_phone varchar(50),
  is_active tinyint(1) DEFAULT 1,
  created_at timestamp DEFAULT CURRENT_TIMESTAMP
);

-- =====================================================
-- WARRANTY & CLAIMS TABLES
-- =====================================================

CREATE TABLE warranty_claims (
  id int(11) PRIMARY KEY AUTO_INCREMENT,
  project_id int(11),
  delivery_id int(11),
  claim_number varchar(50),
  claim_type enum('damage','defect','shortage','other'),
  status enum('open','in_progress','resolved','closed') DEFAULT 'open',
  description text,
  module_count int(11) DEFAULT 0,
  reported_by int(11),
  reported_date datetime DEFAULT CURRENT_TIMESTAMP,
  resolved_date datetime,
  FOREIGN KEY (project_id) REFERENCES projects(id)
);

-- =====================================================
-- AI ASSISTANT (SUNNY) TABLES
-- =====================================================

CREATE TABLE sunny_conversations (
  id int(11) PRIMARY KEY AUTO_INCREMENT,
  user_id int(11) NOT NULL,
  title varchar(255),
  is_active tinyint(1) DEFAULT 1,
  created_at datetime DEFAULT CURRENT_TIMESTAMP,
  updated_at datetime ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id)
);

CREATE TABLE sunny_messages (
  id int(11) PRIMARY KEY AUTO_INCREMENT,
  conversation_id int(11) NOT NULL,
  role enum('user','assistant','system') NOT NULL,
  content text NOT NULL,
  created_at datetime DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (conversation_id) REFERENCES sunny_conversations(id)
);

CREATE TABLE sunny_memory (
  id int(11) PRIMARY KEY AUTO_INCREMENT,
  user_id int(11),
  memory_key varchar(100) NOT NULL,
  memory_value text NOT NULL,
  created_at datetime DEFAULT CURRENT_TIMESTAMP,
  updated_at datetime ON UPDATE CURRENT_TIMESTAMP
);

-- =====================================================
-- VIEWS
-- =====================================================

-- Projection summary view
CREATE VIEW v_projection_summary AS
SELECT
  dp.id,
  dp.project_id,
  dp.projection_name,
  dp.is_primary,
  dp.status,
  p.project_name,
  p.project_address,
  COUNT(DISTINCT pma.id) as module_count,
  COUNT(DISTINCT ps.id) as stop_count,
  COUNT(DISTINCT pl.id) as leg_count,
  pcs.grand_total
FROM delivery_projections dp
JOIN projects p ON p.id = dp.project_id
LEFT JOIN projection_module_allocations pma ON pma.projection_id = dp.id
LEFT JOIN projection_stops ps ON ps.projection_id = dp.id
LEFT JOIN projection_legs pl ON pl.projection_id = dp.id
LEFT JOIN projection_cost_summary pcs ON pcs.projection_id = dp.id
GROUP BY dp.id;

-- Project projection status view
CREATE VIEW v_project_projection_status AS
SELECT
  p.id as project_id,
  p.project_name,
  CASE WHEN dp.id IS NOT NULL THEN 1 ELSE 0 END as has_projection,
  dp.id as primary_projection_id,
  dp.projection_name,
  dp.status as projection_status
FROM projects p
LEFT JOIN delivery_projections dp ON dp.project_id = p.id AND dp.is_primary = 1;

-- =====================================================
-- KEY RELATIONSHIPS SUMMARY
-- =====================================================
-- customer_accounts -> projects -> modules -> unassigned_module_items
-- customer_accounts -> customer_account_users -> users
-- projects -> deliveries -> delivery_pallets -> inventory_pallets
-- projects -> delivery_projections -> projection_stops -> projection_stop_fees
-- projects -> delivery_projections -> projection_legs
-- projects -> delivery_projections -> projection_module_allocations -> modules (real)
-- projects -> delivery_projections -> projection_module_allocations -> projection_modules (projection-only)
-- delivery_projections -> projection_modules -> projection_module_items
-- delivery_projections -> projection_modules -> projection_module_milestones
-- warehouses -> inventory_pallets
-- warehouses -> warehouse_cost_items
-- manufacturers -> manufacturer_locations
-- modules -> module_batch_milestones
