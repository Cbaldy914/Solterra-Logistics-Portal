-- Database Schema for Solterra Portal
-- Generated from: solterra_portal.sql
-- Contains only table structures, no data

-- --------------------------------------------------------

-- Table structure for table `accounts`
--

CREATE TABLE `accounts` (
`id` int(11) NOT NULL,
`name` varchar(255) NOT NULL,
`created_at` datetime DEFAULT current_timestamp(),
`updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;
-- --------------------------------------------------------

-- Table structure for table `accounts_payable`
--

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
-- --------------------------------------------------------

-- Table structure for table `account_users`
--

CREATE TABLE `account_users` (
`id` int(11) NOT NULL,
`account_id` int(11) NOT NULL,
`user_id` int(11) NOT NULL,
`role` varchar(50) NOT NULL,
`created_at` datetime DEFAULT current_timestamp(),
`updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;
-- --------------------------------------------------------

-- Table structure for table `customer_accounts`
--

CREATE TABLE `customer_accounts` (
`id` int(11) NOT NULL,
`name` varchar(255) NOT NULL,
`created_at` datetime DEFAULT current_timestamp(),
`updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;
-- --------------------------------------------------------

-- Table structure for table `customer_account_users`
--

CREATE TABLE `customer_account_users` (
`id` int(11) NOT NULL,
`user_id` int(11) NOT NULL,
`account_id` int(11) NOT NULL,
`role` varchar(50) NOT NULL,
`created_at` datetime DEFAULT current_timestamp(),
`updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;
-- --------------------------------------------------------

-- Table structure for table `deliveries`
--

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
`warehouse_id` int(11) DEFAULT NULL,
`scheduled` tinyint(1) DEFAULT 0 COMMENT 'Whether this delivery has been scheduled'
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;
-- --------------------------------------------------------

-- Table structure for table `delivery_pallets`
--

CREATE TABLE `delivery_pallets` (
`delivery_id` int(11) NOT NULL,
`inventory_pallet_id` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
-- --------------------------------------------------------

-- Table structure for table `flash_test_data`
--

CREATE TABLE `flash_test_data` (
`id` int(11) NOT NULL,
`project_id` int(11) NOT NULL,
`module_id` varchar(100) NOT NULL,
`flash_date` datetime NOT NULL,
`flash_result` varchar(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
-- --------------------------------------------------------

-- Table structure for table `forecast_items`
--

CREATE TABLE `forecast_items` (
`id` int(11) NOT NULL,
`forecast_id` int(11) NOT NULL,
`estimate_type` enum('warehouse','freight') NOT NULL,
`estimate_id` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;
-- --------------------------------------------------------

-- Table structure for table `forecast_projects`
--

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
-- --------------------------------------------------------

-- Table structure for table `freight_estimates`
--

CREATE TABLE `freight_estimates` (
`id` int(11) NOT NULL,
`user_id` int(11) NOT NULL,
`name` varchar(255) NOT NULL,
`estimate_data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`estimate_data`)),
`created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;
-- --------------------------------------------------------

-- Table structure for table `inventory_pallets`
--

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
`flash_test_data` varchar(255) DEFAULT NULL,
`manufacturer` varchar(255) DEFAULT NULL COMMENT 'Manufacturer name extracted from modules vendor_name',
`manufacturer_location_id` int(11) DEFAULT NULL COMMENT 'FK to manufacturer_locations.id for specific location'
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;
-- --------------------------------------------------------

-- Table structure for table `manufacturers`
--

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
-- --------------------------------------------------------

-- Table structure for table `manufacturer_locations`
--

CREATE TABLE `manufacturer_locations` (
`id` int(11) NOT NULL,
`manufacturer_id` int(11) NOT NULL,
`location_name` varchar(255) DEFAULT NULL,
`street_address` varchar(255) DEFAULT NULL,
`city` varchar(100) DEFAULT NULL,
`state` varchar(50) DEFAULT NULL,
`zip_code` varchar(20) DEFAULT NULL,
`country` varchar(100) DEFAULT 'USA',
`is_primary` tinyint(1) DEFAULT 0,
`is_active` tinyint(1) DEFAULT 1,
`notes` text DEFAULT NULL,
`created_at` timestamp NOT NULL DEFAULT current_timestamp(),
`updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;
-- --------------------------------------------------------

-- Table structure for table `modules`
--

CREATE TABLE `modules` (
`id` int(11) NOT NULL,
`account_id` int(11) NOT NULL,
`vendor_name` varchar(255) NOT NULL,
`initial_location` varchar(255) NOT NULL,
`created_at` timestamp NOT NULL DEFAULT current_timestamp(),
`last_updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
`project_id` int(11) DEFAULT NULL,
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
`module_additional_notes` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;
-- --------------------------------------------------------

-- Table structure for table `overheads`
--

CREATE TABLE `overheads` (
`id` int(11) NOT NULL,
`overhead_amount` decimal(10,2) NOT NULL,
`description` text DEFAULT NULL,
`project_id` int(11) DEFAULT NULL,
`created_at` datetime NOT NULL DEFAULT current_timestamp(),
`overhead_recurring` tinyint(1) NOT NULL DEFAULT 0,
`overhead_frequency` varchar(50) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;
-- --------------------------------------------------------

-- Table structure for table `projects`
--

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
`zip_code` varchar(20) DEFAULT NULL,
`phone1` varchar(50) DEFAULT NULL,
`phone2` varchar(50) DEFAULT NULL,
`timezone` varchar(50) DEFAULT NULL,
`reference_numbers` varchar(255) DEFAULT NULL,
`instructions` text DEFAULT NULL,
`documentation_url` text DEFAULT NULL,
`driver_handout_url` text DEFAULT NULL,
`standard_operating_hours` text DEFAULT NULL,
`additional_notes` text DEFAULT NULL,
`appointment_duration` int(11) NOT NULL DEFAULT 30
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;
-- --------------------------------------------------------

-- Table structure for table `project_invoices`
--

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
-- --------------------------------------------------------

-- Table structure for table `project_wattage_orders`
--

CREATE TABLE `project_wattage_orders` (
`id` int(11) NOT NULL,
`project_id` int(11) NOT NULL,
`wattage` varchar(255) NOT NULL,
`total_order` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;
-- --------------------------------------------------------

-- Table structure for table `sites`
--

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
-- --------------------------------------------------------

-- Table structure for table `site_module_info`
--

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
-- --------------------------------------------------------

-- Table structure for table `site_module_wattages`
--

CREATE TABLE `site_module_wattages` (
`id` int(11) NOT NULL,
`site_id` int(11) DEFAULT NULL,
`watt` int(11) NOT NULL,
`quantity` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;
-- --------------------------------------------------------

-- Table structure for table `site_operating_hours`
--

CREATE TABLE `site_operating_hours` (
`id` int(11) NOT NULL,
`site_id` int(11) DEFAULT NULL,
`project_id` int(11) DEFAULT NULL,
`day_of_week` int(11) NOT NULL COMMENT '0=Sunday, 1=Monday,...6=Saturday',
`start_time` time NOT NULL,
`end_time` time NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;
-- --------------------------------------------------------

-- Table structure for table `site_safety`
--

CREATE TABLE `site_safety` (
`id` int(11) NOT NULL,
`scheduling_id` int(11) NOT NULL,
`project_id` int(11) DEFAULT NULL,
`bol_number` varchar(255) DEFAULT NULL,
`notes` text DEFAULT NULL,
`pictures` text DEFAULT NULL,
`report_driver` enum('Yes','No') NOT NULL DEFAULT 'No',
`created_at` datetime NOT NULL DEFAULT current_timestamp(),
`updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
-- --------------------------------------------------------

-- Table structure for table `site_scheduling`
--

CREATE TABLE `site_scheduling` (
`id` int(11) NOT NULL,
`site_id` int(11) DEFAULT NULL,
`project_id` int(11) DEFAULT NULL,
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
`bol_file` varchar(255) DEFAULT NULL,
`delivery_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;
-- --------------------------------------------------------

-- Table structure for table `site_users`
--

CREATE TABLE `site_users` (
`id` int(11) NOT NULL,
`username` varchar(100) NOT NULL,
`password` varchar(255) NOT NULL,
`created_at` timestamp NOT NULL DEFAULT current_timestamp(),
`global_role` varchar(50) DEFAULT NULL,
`verification_token` varchar(255) DEFAULT NULL,
`is_verified` tinyint(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
-- --------------------------------------------------------

-- Table structure for table `sunny_memory`
--

CREATE TABLE `sunny_memory` (
`id` int(11) NOT NULL,
`user_id` int(11) NOT NULL,
`account_id` int(11) DEFAULT NULL,
`memory_type` enum('preference','context','issue','note') NOT NULL DEFAULT 'note',
`category` varchar(100) DEFAULT NULL COMMENT 'project, delivery, warehouse, etc.',
`entity_id` int(11) DEFAULT NULL COMMENT 'Related project_id, delivery_id, etc.',
`title` varchar(255) NOT NULL,
`content` text NOT NULL,
`importance` tinyint(1) NOT NULL DEFAULT 1 COMMENT '1=low, 2=medium, 3=high',
`created_at` datetime NOT NULL DEFAULT current_timestamp(),
`updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
-- --------------------------------------------------------

-- Table structure for table `unassigned_module_items`
--

CREATE TABLE `unassigned_module_items` (
`id` int(11) NOT NULL,
`unassigned_module_id` int(11) NOT NULL COMMENT 'FK to unassigned_modules.id',
`wattage` int(11) NOT NULL,
`quantity` int(11) NOT NULL,
`created_at` timestamp NOT NULL DEFAULT current_timestamp(),
`last_updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;
-- --------------------------------------------------------

-- Table structure for table `users`
--

CREATE TABLE `users` (
`id` int(11) NOT NULL,
`username` varchar(50) NOT NULL,
`email` varchar(255) DEFAULT NULL,
`password` varchar(255) NOT NULL,
`created_at` timestamp NOT NULL DEFAULT current_timestamp(),
`role` varchar(20) DEFAULT 'user'
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;
-- --------------------------------------------------------

-- Table structure for table `vendors`
--

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
-- --------------------------------------------------------

-- Table structure for table `warehouses`
--

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
-- --------------------------------------------------------

-- Table structure for table `warehouse_estimates`
--

CREATE TABLE `warehouse_estimates` (
`id` int(11) NOT NULL,
`user_id` int(11) NOT NULL,
`name` varchar(255) NOT NULL,
`estimate_data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`estimate_data`)),
`created_at` datetime DEFAULT current_timestamp(),
`app_type` varchar(20) NOT NULL DEFAULT 'calculator'
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;
-- --------------------------------------------------------

-- Table structure for table `warehouse_quotes`
--

CREATE TABLE `warehouse_quotes` (
`id` int(11) NOT NULL,
`user_id` int(11) NOT NULL,
`name` varchar(255) NOT NULL,
`estimate_data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`estimate_data`)),
`created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;
-- --------------------------------------------------------

-- Table structure for table `warranty_claims`
--

CREATE TABLE `warranty_claims` (
`id` int(11) NOT NULL,
`scheduling_id` int(11) NOT NULL,
`bol_number` varchar(255) DEFAULT NULL,
`delivery_date` datetime DEFAULT NULL,
`status` varchar(50) DEFAULT 'Pending',
`notes` text DEFAULT NULL,
`pictures` text DEFAULT NULL,
`created_at` datetime DEFAULT current_timestamp(),
`updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
`manufacturer_notes` text DEFAULT NULL,
`new_delivery_date` date DEFAULT NULL,
`pallet_id` int(11) DEFAULT NULL,
`issue_type` enum('damaged','quantity_discrepancy','both') DEFAULT NULL,
`expected_quantity` int(11) DEFAULT NULL,
`actual_quantity` int(11) DEFAULT NULL,
`damaged_quantity` int(11) DEFAULT NULL,
`accepted_quantity` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- Indexes for dumped tables

-- Indexes for table `accounts`

-- Indexes for table `accounts_payable`

-- Indexes for table `account_users`

-- Indexes for table `customer_accounts`

-- Indexes for table `customer_account_users`

-- Indexes for table `deliveries`

-- Indexes for table `delivery_pallets`

-- Indexes for table `flash_test_data`

-- Indexes for table `forecast_items`

-- Indexes for table `forecast_projects`

-- Indexes for table `freight_estimates`

-- Indexes for table `inventory_pallets`

-- Indexes for table `manufacturers`

-- Indexes for table `manufacturer_locations`

-- Indexes for table `modules`

-- Indexes for table `overheads`

-- Indexes for table `projects`

-- Indexes for table `project_invoices`

-- Indexes for table `project_wattage_orders`

-- Indexes for table `sites`

-- Indexes for table `site_module_info`

-- Indexes for table `site_module_wattages`

-- Indexes for table `site_operating_hours`

-- Indexes for table `site_safety`

-- Indexes for table `site_scheduling`

-- Indexes for table `site_users`

-- Indexes for table `sunny_memory`

-- Indexes for table `unassigned_module_items`

-- Indexes for table `users`

-- Indexes for table `vendors`

-- Indexes for table `warehouses`

-- Indexes for table `warehouse_estimates`

-- Indexes for table `warehouse_quotes`

-- Indexes for table `warranty_claims`

-- AUTO_INCREMENT for dumped tables

-- AUTO_INCREMENT for table `accounts`

-- AUTO_INCREMENT for table `accounts_payable`

-- AUTO_INCREMENT for table `account_users`

-- AUTO_INCREMENT for table `customer_accounts`

-- AUTO_INCREMENT for table `customer_account_users`

-- AUTO_INCREMENT for table `deliveries`

-- AUTO_INCREMENT for table `flash_test_data`

-- AUTO_INCREMENT for table `forecast_items`

-- AUTO_INCREMENT for table `forecast_projects`

-- AUTO_INCREMENT for table `freight_estimates`

-- AUTO_INCREMENT for table `inventory_pallets`

-- AUTO_INCREMENT for table `manufacturers`

-- AUTO_INCREMENT for table `manufacturer_locations`

-- AUTO_INCREMENT for table `modules`

-- AUTO_INCREMENT for table `overheads`

-- AUTO_INCREMENT for table `projects`

-- AUTO_INCREMENT for table `project_invoices`

-- AUTO_INCREMENT for table `project_wattage_orders`

-- AUTO_INCREMENT for table `sites`

-- AUTO_INCREMENT for table `site_module_info`

-- AUTO_INCREMENT for table `site_module_wattages`

-- AUTO_INCREMENT for table `site_operating_hours`

-- AUTO_INCREMENT for table `site_safety`

-- AUTO_INCREMENT for table `site_scheduling`

-- AUTO_INCREMENT for table `site_users`

-- AUTO_INCREMENT for table `sunny_memory`

-- AUTO_INCREMENT for table `unassigned_module_items`

-- AUTO_INCREMENT for table `users`

-- AUTO_INCREMENT for table `vendors`

-- AUTO_INCREMENT for table `warehouses`

-- AUTO_INCREMENT for table `warehouse_estimates`

-- AUTO_INCREMENT for table `warehouse_quotes`

-- AUTO_INCREMENT for table `warranty_claims`

-- Constraints for dumped tables

-- Constraints for table `accounts_payable`

-- Constraints for table `account_users`

-- Constraints for table `customer_account_users`

-- Constraints for table `deliveries`

-- Constraints for table `delivery_pallets`

-- Constraints for table `flash_test_data`

-- Constraints for table `forecast_items`

-- Constraints for table `forecast_projects`

-- Constraints for table `freight_estimates`

-- Constraints for table `inventory_pallets`

-- Constraints for table `manufacturer_locations`

-- Constraints for table `modules`

-- Constraints for table `projects`

-- Constraints for table `project_invoices`

-- Constraints for table `project_wattage_orders`

-- Constraints for table `site_module_info`

-- Constraints for table `site_module_wattages`

-- Constraints for table `site_operating_hours`

-- Constraints for table `site_safety`

-- Constraints for table `site_scheduling`

-- Constraints for table `sunny_memory`

-- Constraints for table `unassigned_module_items`

-- Constraints for table `warehouse_estimates`

-- Constraints for table `warehouse_quotes`

-- Constraints for table `warranty_claims`
