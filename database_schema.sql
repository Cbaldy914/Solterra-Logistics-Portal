-- =====================================================
-- Solterra Logistics Portal - Database Schema
-- =====================================================
-- This file contains the complete database structure for the Solterra Logistics Portal
-- Generated from solterra_portal.sql (structure only, no data)
-- Total tables: 32
-- Last updated: 2025-06-30
--
-- Key Features:
-- - User management with role-based access control
-- - Project and delivery tracking system
-- - Inventory and pallet management
-- - Freight and warehouse cost estimation
-- - Invoice and accounts payable tracking
-- - Site management and safety protocols
-- =====================================================

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


CREATE TABLE `accounts` (
  `id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;
CREATE TABLE `accounts_payable` (
  `id` int(11) NOT NULL,
  `vendor_name` varchar(255) NOT NULL,
  `project_id` int(11) NOT NULL,
  `paid_date` date NOT NULL,
  `amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `notes` text DEFAULT NULL,
  `receipt_file` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;
CREATE TABLE `account_users` (
  `id` int(11) NOT NULL,
  `account_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `role` varchar(50) NOT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;
CREATE TABLE `customer_accounts` (
  `id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;
CREATE TABLE `customer_account_users` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `account_id` int(11) NOT NULL,
  `role` varchar(50) NOT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;
CREATE TABLE `deliveries` (
  `id` int(11) NOT NULL,
  `project_id` int(11) DEFAULT NULL,
  `supplier` varchar(255) NOT NULL,
  `origin_type` enum('manufacturer','warehouse','project') DEFAULT NULL,
  `origin_id` int(11) DEFAULT NULL,
  `wattage` int(11) NOT NULL,
  `status_of_delivery` varchar(50) NOT NULL,
  `quantity` int(11) NOT NULL,
  `bol_number` varchar(50) NOT NULL,
  `anticipated_delivery_date` date DEFAULT NULL,
  `warehouse_arrival_date` date DEFAULT NULL,
  `actual_delivery_date` date DEFAULT NULL,
  `proof_of_delivery` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `left_warehouse_date` date DEFAULT NULL,
  `freight_cost` decimal(10,2) DEFAULT 0.00,
  `accessorial_costs` decimal(10,2) DEFAULT 0.00,
  `customer_cost` decimal(10,2) DEFAULT 0.00,
  `miles` double DEFAULT NULL,
  `invoice_number` varchar(50) DEFAULT NULL,
  `invoice_id` int(11) DEFAULT NULL,
  `accessorial_costs_paid` decimal(10,2) NOT NULL DEFAULT 0.00,
  `pay_status` enum('Unpaid','Paid','Partial') NOT NULL DEFAULT 'Unpaid',
  `pay_date` date DEFAULT NULL,
  `accounts_payable_id` int(11) DEFAULT NULL,
  `warehouse_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;
CREATE TABLE `delivery_pallets` (
  `delivery_id` int(11) NOT NULL,
  `inventory_pallet_id` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `flash_test_data` (
  `id` int(11) NOT NULL,
  `project_id` int(11) NOT NULL,
  `module_id` varchar(100) NOT NULL,
  `flash_date` datetime NOT NULL,
  `flash_result` varchar(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `forecast_items` (
  `id` int(11) NOT NULL,
  `forecast_id` int(11) NOT NULL,
  `estimate_type` enum('warehouse','freight') NOT NULL,
  `estimate_id` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;
CREATE TABLE `forecast_projects` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `linked_project_id` int(11) DEFAULT NULL,
  `estimated_start_date` date DEFAULT NULL,
  `modules_data` text DEFAULT NULL,
  `image_path` varchar(255) DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  `size` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;
CREATE TABLE `freight_estimates` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `estimate_data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`estimate_data`)),
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;
CREATE TABLE `inventory_pallets` (
  `id` int(11) NOT NULL COMMENT 'Unique ID for each pallet',
  `pallet_identifier` varchar(255) DEFAULT NULL COMMENT 'Optional user-defined label, barcode, etc.',
  `unassigned_module_item_id` int(11) NOT NULL COMMENT 'FK to unassigned_module_items.id',
  `assigned_project_id` int(11) DEFAULT NULL COMMENT 'FK to projects.id, indicates the project this pallet is intended for',
  `wattage` int(11) NOT NULL COMMENT 'Wattage of modules on this pallet',
  `quantity` int(11) NOT NULL COMMENT 'Number of modules on this specific pallet',
  `current_warehouse_id` int(11) DEFAULT NULL COMMENT 'FK to warehouses.id, NULL if not in a warehouse',
  `current_project_id` int(11) DEFAULT NULL COMMENT 'FK to projects.id, NULL until delivered to project',
  `status` enum('At Manufacturer','In Transit to Warehouse','In Warehouse','Allocated to Project','In Transit to Project','Delivered to Project') NOT NULL DEFAULT 'At Manufacturer',
  `arrival_date` datetime DEFAULT NULL COMMENT 'When pallet first recorded in system (e.g., at first warehouse)',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `flash_test_data` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;
CREATE TABLE `manufacturers` (
  `id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `short_name` varchar(100) DEFAULT NULL,
  `contact_person` varchar(255) DEFAULT NULL,
  `phone` varchar(50) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `website` varchar(255) DEFAULT NULL,
  `street_address` varchar(255) DEFAULT NULL,
  `city` varchar(100) DEFAULT NULL,
  `state` varchar(50) DEFAULT NULL,
  `zip_code` varchar(20) DEFAULT NULL,
  `country` varchar(100) DEFAULT 'USA',
  `address` text DEFAULT NULL,
  `logo_url` varchar(500) DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;
CREATE TABLE `modules` (
  `id` int(11) NOT NULL,
  `account_id` int(11) NOT NULL,
  `vendor_name` varchar(255) NOT NULL,
  `initial_location` varchar(255) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `last_updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `project_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;
CREATE TABLE `overheads` (
  `id` int(11) NOT NULL,
  `overhead_amount` decimal(10,2) NOT NULL,
  `description` text DEFAULT NULL,
  `project_id` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `overhead_recurring` tinyint(1) NOT NULL DEFAULT 0,
  `overhead_frequency` varchar(50) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;
CREATE TABLE `projects` (
  `id` int(11) NOT NULL,
  `account_id` int(11) NOT NULL,
  `project_name` varchar(255) NOT NULL,
  `image_url` varchar(255) DEFAULT NULL,
  `estimated_completion_date` date DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `project_size` float NOT NULL DEFAULT 0,
  `warehouse_id` int(11) DEFAULT NULL,
  `project_address` varchar(255) DEFAULT NULL,
  `default_freight_cost` decimal(10,2) DEFAULT NULL,
  `admin_id` int(11) DEFAULT NULL,
  `forecasted_costs` text DEFAULT NULL,
  `solterra_fee` decimal(10,4) NOT NULL DEFAULT 0.0000,
  `street_address` varchar(255) DEFAULT NULL,
  `city` varchar(100) DEFAULT NULL,
  `state` varchar(50) DEFAULT NULL,
  `zip_code` varchar(20) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;
CREATE TABLE `project_invoices` (
  `id` int(11) NOT NULL,
  `project_id` int(11) NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `status` varchar(10) NOT NULL DEFAULT 'Open',
  `issued_date` date NOT NULL,
  `due_date` date NOT NULL,
  `invoice_file` varchar(255) NOT NULL,
  `uploaded_at` datetime DEFAULT current_timestamp(),
  `invoice_number` varchar(100) DEFAULT NULL,
  `deposit_credit` decimal(10,2) DEFAULT 0.00,
  `notes` text DEFAULT NULL,
  `bill_to` text DEFAULT NULL,
  `sow_text` text DEFAULT NULL,
  `msa_text` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;
CREATE TABLE `project_wattage_orders` (
  `id` int(11) NOT NULL,
  `project_id` int(11) NOT NULL,
  `wattage` varchar(255) NOT NULL,
  `total_order` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;
CREATE TABLE `sites` (
  `id` int(11) NOT NULL,
  `account_id` int(11) NOT NULL,
  `project_name` varchar(255) NOT NULL,
  `project_address` varchar(255) NOT NULL,
  `image_url` varchar(255) DEFAULT NULL,
  `standard_operating_hours` text DEFAULT NULL,
  `additional_notes` text DEFAULT NULL,
  `documentation_url` varchar(255) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `street` varchar(255) DEFAULT NULL,
  `city` varchar(100) DEFAULT NULL,
  `state` varchar(50) DEFAULT NULL,
  `zip` varchar(20) DEFAULT NULL,
  `phone1` varchar(50) DEFAULT NULL,
  `phone2` varchar(50) DEFAULT NULL,
  `timezone` varchar(50) DEFAULT NULL,
  `reference_numbers` varchar(255) DEFAULT NULL,
  `instructions` text DEFAULT NULL,
  `appointment_duration` int(11) NOT NULL DEFAULT 30,
  `driver_handout_url` text DEFAULT NULL,
  `project_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;
CREATE TABLE `site_module_info` (
  `id` int(11) NOT NULL,
  `site_id` int(11) NOT NULL,
  `supplier_name` varchar(255) DEFAULT NULL,
  `supplier_phone` varchar(50) DEFAULT NULL,
  `supplier_email` varchar(255) DEFAULT NULL,
  `supplier_street` varchar(255) DEFAULT NULL,
  `supplier_city` varchar(255) DEFAULT NULL,
  `supplier_state` varchar(255) DEFAULT NULL,
  `supplier_zip` varchar(50) DEFAULT NULL,
  `supplier_timezone` varchar(50) DEFAULT NULL,
  `module_wattages` text DEFAULT NULL,
  `modules_per_pallet` int(11) DEFAULT NULL,
  `pallets_per_truck` int(11) DEFAULT NULL,
  `modules_per_truck` int(11) DEFAULT NULL,
  `pallet_length_mm` int(11) DEFAULT NULL,
  `pallet_depth_mm` int(11) DEFAULT NULL,
  `pallet_double_stacked_height_mm` int(11) DEFAULT NULL,
  `pallet_total_weight_kg` int(11) DEFAULT NULL,
  `stacking_in_warehouse` text DEFAULT NULL,
  `stacking_during_transport` text DEFAULT NULL,
  `forklift_truck_long_side_mm` int(11) DEFAULT NULL,
  `forklift_truck_short_side_mm` int(11) DEFAULT NULL,
  `pallet_jack_long_side_mm` int(11) DEFAULT NULL,
  `pallet_jack_short_side_mm` int(11) DEFAULT NULL,
  `module_notes` text DEFAULT NULL,
  `module_docs_url` text DEFAULT NULL,
  `module_additional_notes` text DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;
CREATE TABLE `site_module_wattages` (
  `id` int(11) NOT NULL,
  `site_id` int(11) NOT NULL,
  `watt` int(11) NOT NULL,
  `quantity` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;
CREATE TABLE `site_operating_hours` (
  `id` int(11) NOT NULL,
  `site_id` int(11) NOT NULL,
  `day_of_week` int(11) NOT NULL COMMENT '0=Sunday, 1=Monday,...6=Saturday',
  `start_time` time NOT NULL,
  `end_time` time NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;
CREATE TABLE `site_safety` (
  `id` int(11) NOT NULL,
  `scheduling_id` int(11) NOT NULL,
  `bol_number` varchar(255) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `pictures` text DEFAULT NULL,
  `report_driver` enum('Yes','No') NOT NULL DEFAULT 'No',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `site_scheduling` (
  `id` int(11) NOT NULL,
  `site_id` int(11) NOT NULL,
  `account_id` int(11) NOT NULL,
  `start_time` datetime NOT NULL,
  `bol_number` varchar(255) NOT NULL,
  `wattage` text DEFAULT NULL,
  `pallet_quantity` int(11) NOT NULL,
  `modules_per_pallet` int(11) NOT NULL,
  `reference_numbers` varchar(255) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `arrival_time` datetime DEFAULT NULL,
  `departure_time` datetime DEFAULT NULL,
  `damages_qty_discrepancies` varchar(255) DEFAULT NULL,
  `additional_details` text DEFAULT NULL,
  `proof_of_delivery` varchar(255) DEFAULT NULL,
  `is_closed` tinyint(1) NOT NULL DEFAULT 0,
  `supplier` varchar(255) DEFAULT NULL,
  `status` varchar(50) DEFAULT NULL,
  `quantity` int(11) DEFAULT NULL,
  `owner_id` int(11) DEFAULT NULL,
  `bol_file` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;
CREATE TABLE `site_users` (
  `id` int(11) NOT NULL,
  `username` varchar(100) NOT NULL,
  `password` varchar(255) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `global_role` varchar(50) DEFAULT NULL,
  `verification_token` varchar(255) DEFAULT NULL,
  `is_verified` tinyint(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE `unassigned_module_items` (
  `id` int(11) NOT NULL,
  `unassigned_module_id` int(11) NOT NULL COMMENT 'FK to unassigned_modules.id',
  `wattage` int(11) NOT NULL,
  `quantity` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `last_updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;
CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `username` varchar(50) NOT NULL,
  `email` varchar(255) DEFAULT NULL,
  `password` varchar(255) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `role` varchar(20) DEFAULT 'user'
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;
CREATE TABLE `vendors` (
  `id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `contact_info` varchar(255) DEFAULT NULL,
  `committed_volume` int(11) NOT NULL DEFAULT 0,
  `commitment_start_date` date DEFAULT NULL,
  `commitment_end_date` date DEFAULT NULL,
  `module_cost` decimal(10,2) NOT NULL DEFAULT 0.00,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;
CREATE TABLE `warehouses` (
  `id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `address` varchar(255) NOT NULL,
  `image_url` varchar(255) DEFAULT NULL,
  `in_fee` decimal(10,2) NOT NULL,
  `out_fee` decimal(10,2) NOT NULL,
  `monthly_storage_fee` decimal(10,2) NOT NULL,
  `street_address` varchar(255) DEFAULT NULL,
  `city` varchar(100) DEFAULT NULL,
  `state` varchar(50) DEFAULT NULL,
  `zip_code` varchar(20) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;
CREATE TABLE `warehouse_estimates` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `estimate_data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`estimate_data`)),
  `created_at` datetime DEFAULT current_timestamp(),
  `app_type` varchar(20) NOT NULL DEFAULT 'calculator'
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;
CREATE TABLE `warehouse_quotes` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `estimate_data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`estimate_data`)),
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;
CREATE TABLE `warranty_claims` (
  `id` int(11) NOT NULL,
  `scheduling_id` int(11) NOT NULL,
  `bol_number` varchar(255) DEFAULT NULL,
  `modules_accepted` text DEFAULT NULL,
  `modules_rejected` text DEFAULT NULL,
  `quantity_discrepancy` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`quantity_discrepancy`)),
  `delivery_date` datetime DEFAULT NULL,
  `status` varchar(50) DEFAULT 'Pending',
  `notes` text DEFAULT NULL,
  `pictures` text DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `manufacturer_notes` text DEFAULT NULL,
  `new_delivery_date` date DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;
ALTER TABLE `accounts`
ALTER TABLE `accounts_payable`
ALTER TABLE `account_users`
ALTER TABLE `customer_accounts`
ALTER TABLE `customer_account_users`
ALTER TABLE `deliveries`
ALTER TABLE `delivery_pallets`
ALTER TABLE `flash_test_data`
ALTER TABLE `forecast_items`
ALTER TABLE `forecast_projects`
ALTER TABLE `freight_estimates`
ALTER TABLE `inventory_pallets`
ALTER TABLE `manufacturers`
ALTER TABLE `modules`
ALTER TABLE `overheads`
ALTER TABLE `projects`
ALTER TABLE `project_invoices`
ALTER TABLE `project_wattage_orders`
ALTER TABLE `sites`
ALTER TABLE `site_module_info`
ALTER TABLE `site_module_wattages`
ALTER TABLE `site_operating_hours`
ALTER TABLE `site_safety`
ALTER TABLE `site_scheduling`
ALTER TABLE `site_users`
ALTER TABLE `unassigned_module_items`
ALTER TABLE `users`
ALTER TABLE `vendors`
ALTER TABLE `warehouses`
ALTER TABLE `warehouse_estimates`
ALTER TABLE `warehouse_quotes`
ALTER TABLE `warranty_claims`
ALTER TABLE `accounts`
ALTER TABLE `accounts_payable`
ALTER TABLE `account_users`
ALTER TABLE `customer_accounts`
ALTER TABLE `customer_account_users`
ALTER TABLE `deliveries`
ALTER TABLE `flash_test_data`
ALTER TABLE `forecast_items`
ALTER TABLE `forecast_projects`
ALTER TABLE `freight_estimates`
ALTER TABLE `inventory_pallets`
ALTER TABLE `manufacturers`
ALTER TABLE `modules`
ALTER TABLE `overheads`
ALTER TABLE `projects`
ALTER TABLE `project_invoices`
ALTER TABLE `project_wattage_orders`
ALTER TABLE `sites`
ALTER TABLE `site_module_info`
ALTER TABLE `site_module_wattages`
ALTER TABLE `site_operating_hours`
ALTER TABLE `site_safety`
ALTER TABLE `site_scheduling`
ALTER TABLE `site_users`
ALTER TABLE `unassigned_module_items`
ALTER TABLE `users`
ALTER TABLE `vendors`
ALTER TABLE `warehouses`
ALTER TABLE `warehouse_estimates`
ALTER TABLE `warehouse_quotes`
ALTER TABLE `warranty_claims`
ALTER TABLE `accounts_payable`
ALTER TABLE `account_users`
ALTER TABLE `customer_account_users`
ALTER TABLE `deliveries`
ALTER TABLE `delivery_pallets`
ALTER TABLE `flash_test_data`
ALTER TABLE `forecast_items`
ALTER TABLE `forecast_projects`
ALTER TABLE `freight_estimates`
ALTER TABLE `inventory_pallets`
ALTER TABLE `modules`
ALTER TABLE `projects`
ALTER TABLE `project_invoices`
ALTER TABLE `project_wattage_orders`
ALTER TABLE `site_module_info`
ALTER TABLE `site_module_wattages`
ALTER TABLE `site_operating_hours`
ALTER TABLE `site_safety`
ALTER TABLE `unassigned_module_items`
ALTER TABLE `warehouse_estimates`
ALTER TABLE `warehouse_quotes`
ALTER TABLE `warranty_claims`


-- =====================================================
-- END OF SCHEMA DEFINITION
-- =====================================================
-- Note: This schema file contains table structures only.
-- Primary keys, foreign keys, and indexes are defined in the original database.
-- For complete database setup, you may need to add:
-- - ALTER TABLE statements for primary keys
-- - ALTER TABLE statements for foreign key constraints 
-- - CREATE INDEX statements for performance optimization
-- =====================================================

COMMIT;
