-- phpMyAdmin SQL Dump
-- Host: localhost:3306
-- Generation Time: Aug 06, 2025 at 10:53 AM
-- Server version: 10.6.22-MariaDB-cll-lve
SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";
/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
-- Database: `solterra_portal`
DELIMITER $$
DELIMITER ;
-- --------------------------------------------------------
-- Table structure for table `accounts`
--

CREATE TABLE `accounts` (
  `id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
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

--
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

--
-- --------------------------------------------------------
-- Table structure for table `customer_accounts`
--

CREATE TABLE `customer_accounts` (
  `id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
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

--
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
  `scheduled` tinyint(1) DEFAULT 0 COMMENT 'Whether this delivery has been scheduled',
  `master_bol` varchar(100) DEFAULT NULL COMMENT 'Master Bill of Lading for ocean shipments',
  `house_bol` varchar(100) DEFAULT NULL COMMENT 'House Bill of Lading for ocean shipments',
  `container_number` varchar(50) DEFAULT NULL COMMENT 'Container number for ocean shipments - REQUIRED for overseas',
  `port_of_entry_id` int(11) DEFAULT NULL COMMENT 'FK to warehouses.id where is_port=1',
  `customs_cleared_date` date DEFAULT NULL COMMENT 'Date customs was cleared',
  `is_overseas_shipment` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Whether this is an overseas shipment',
  `origin_port_id` int(11) DEFAULT NULL COMMENT 'FK to warehouses.id where is_port=1 - departure port for overseas shipments'
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- --------------------------------------------------------
-- Table structure for table `delivery_pallets`
--

CREATE TABLE `delivery_pallets` (
  `delivery_id` int(11) NOT NULL,
  `inventory_pallet_id` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
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

--
-- Table structure for table `forecast_items`
--

CREATE TABLE `forecast_items` (
  `id` int(11) NOT NULL,
  `forecast_id` int(11) NOT NULL,
  `estimate_type` enum('warehouse','freight') NOT NULL,
  `estimate_id` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
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

--
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

--
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
  `status` enum('At Manufacturer','On Water','Cleared Customs','In Transit to Warehouse','In Warehouse','Allocated to Project','In Transit to Project','Delivered to Project') NOT NULL DEFAULT 'At Manufacturer',
  `arrival_date` datetime DEFAULT NULL COMMENT 'When pallet first recorded in system (e.g., at first warehouse)',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `flash_test_data` varchar(255) DEFAULT NULL,
  `manufacturer` varchar(255) DEFAULT NULL COMMENT 'Manufacturer name extracted from modules vendor_name',
  `manufacturer_location_id` int(11) DEFAULT NULL COMMENT 'FK to manufacturer_locations.id for specific location'
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
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

--
DELIMITER $$
DELIMITER ;
DELIMITER $$
DELIMITER ;
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

--
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

--
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

--
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

--
DELIMITER $$
DELIMITER ;
DELIMITER $$
DELIMITER ;
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

--
-- --------------------------------------------------------
-- Table structure for table `project_wattage_orders`
--

CREATE TABLE `project_wattage_orders` (
  `id` int(11) NOT NULL,
  `project_id` int(11) NOT NULL,
  `wattage` varchar(255) NOT NULL,
  `total_order` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
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

--
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

--
-- Table structure for table `site_module_wattages`
--

CREATE TABLE `site_module_wattages` (
  `id` int(11) NOT NULL,
  `site_id` int(11) DEFAULT NULL,
  `watt` int(11) NOT NULL,
  `quantity` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------

--
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

--
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

--
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

--
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

--
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

--
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

--
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

--
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

--
-- Table structure for table `warehouses`
--

CREATE TABLE `warehouses` (
  `id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `address` varchar(255) NOT NULL,
  `image_url` varchar(255) DEFAULT NULL,
  `street_address` varchar(255) DEFAULT NULL,
  `city` varchar(100) DEFAULT NULL,
  `state` varchar(50) DEFAULT NULL,
  `zip_code` varchar(20) DEFAULT NULL,
  `country` varchar(100) DEFAULT 'USA',
  `is_port` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Whether this warehouse also functions as a port of entry'
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
DELIMITER $$
DELIMITER ;
DELIMITER $$
DELIMITER ;
-- --------------------------------------------------------
-- Table structure for table `warehouse_cost_items`
--

CREATE TABLE `warehouse_cost_items` (
  `id` int(11) NOT NULL,
  `warehouse_id` int(11) NOT NULL,
  `label` varchar(255) NOT NULL COMMENT 'Description of the cost (e.g., Entry Fee, Monthly Storage, Exit Fee)',
  `trigger_event` enum('entry','monthly','exit','customs_clearance','drayage','other') NOT NULL COMMENT 'When this cost is applied',
  `amount` decimal(10,2) NOT NULL COMMENT 'Cost amount',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `unit_type` varchar(50) DEFAULT 'per_pallet' COMMENT 'per_pallet, per_shipment, per_container, fixed_fee, per_day, etc.',
  `trigger_description` varchar(255) DEFAULT NULL COMMENT 'Custom description of when this cost applies',
  `is_predefined` tinyint(1) DEFAULT 0 COMMENT 'Whether this is a system predefined cost or custom'
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
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

--
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

--
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

--
-- Indexes for dumped tables
--

--
-- Indexes for table `accounts`
--
ALTER TABLE `accounts`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `accounts_payable`
--
ALTER TABLE `accounts_payable`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_ap_project` (`project_id`);

--
-- Indexes for table `account_users`
--
ALTER TABLE `account_users`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_account_id` (`account_id`),
  ADD KEY `fk_user_id` (`user_id`);

--
-- Indexes for table `customer_accounts`
--
ALTER TABLE `customer_accounts`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `customer_account_users`
--
ALTER TABLE `customer_account_users`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_cau_user` (`user_id`),
  ADD KEY `fk_cau_account` (`account_id`);

--
-- Indexes for table `deliveries`
--
ALTER TABLE `deliveries`
  ADD PRIMARY KEY (`id`),
  ADD KEY `project_id` (`project_id`),
  ADD KEY `fk_delivery_ap` (`accounts_payable_id`),
  ADD KEY `fk_deliveries_warehouse` (`warehouse_id`),
  ADD KEY `idx_deliveries_origin` (`origin_type`,`origin_id`),
  ADD KEY `fk_deliveries_port_of_entry` (`port_of_entry_id`),
  ADD KEY `idx_deliveries_overseas` (`is_overseas_shipment`),
  ADD KEY `idx_deliveries_master_bol` (`master_bol`),
  ADD KEY `idx_deliveries_container` (`container_number`),
  ADD KEY `fk_deliveries_origin_port` (`origin_port_id`);

--
-- Indexes for table `delivery_pallets`
--
ALTER TABLE `delivery_pallets`
  ADD PRIMARY KEY (`delivery_id`,`inventory_pallet_id`),
  ADD KEY `fk_dp_pallet` (`inventory_pallet_id`);

--
-- Indexes for table `flash_test_data`
--
ALTER TABLE `flash_test_data`
  ADD PRIMARY KEY (`id`),
  ADD KEY `project_id` (`project_id`);

--
-- Indexes for table `forecast_items`
--
ALTER TABLE `forecast_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `forecast_id` (`forecast_id`);

--
-- Indexes for table `forecast_projects`
--
ALTER TABLE `forecast_projects`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `linked_project_id` (`linked_project_id`);

--
-- Indexes for table `freight_estimates`
--
ALTER TABLE `freight_estimates`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `inventory_pallets`
--
ALTER TABLE `inventory_pallets`
  ADD PRIMARY KEY (`id`),
  ADD KEY `unassigned_module_item_id` (`unassigned_module_item_id`),
  ADD KEY `current_warehouse_id` (`current_warehouse_id`),
  ADD KEY `current_project_id` (`current_project_id`),
  ADD KEY `fk_pallet_assigned_project` (`assigned_project_id`),
  ADD KEY `idx_manufacturer_location` (`manufacturer_location_id`),
  ADD KEY `idx_inventory_pallets_status` (`status`);

--
-- Indexes for table `manufacturers`
--
ALTER TABLE `manufacturers`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_name` (`name`),
  ADD KEY `idx_short_name` (`short_name`),
  ADD KEY `idx_city` (`city`),
  ADD KEY `idx_state` (`state`),
  ADD KEY `idx_is_active` (`is_active`);

--
-- Indexes for table `manufacturer_locations`
--
ALTER TABLE `manufacturer_locations`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_manufacturer_id` (`manufacturer_id`),
  ADD KEY `idx_is_primary` (`is_primary`);

--
-- Indexes for table `modules`
--
ALTER TABLE `modules`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_unassigned_modules_project` (`project_id`);

--
-- Indexes for table `overheads`
--
ALTER TABLE `overheads`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `projects`
--
ALTER TABLE `projects`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_admin` (`admin_id`);

--
-- Indexes for table `project_invoices`
--
ALTER TABLE `project_invoices`
  ADD PRIMARY KEY (`id`),
  ADD KEY `project_id` (`project_id`);

--
-- Indexes for table `project_wattage_orders`
--
ALTER TABLE `project_wattage_orders`
  ADD PRIMARY KEY (`id`),
  ADD KEY `project_id` (`project_id`);

--
-- Indexes for table `sites`
--
ALTER TABLE `sites`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `site_module_info`
--
ALTER TABLE `site_module_info`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_site_id` (`site_id`);

--
-- Indexes for table `site_module_wattages`
--
ALTER TABLE `site_module_wattages`
  ADD PRIMARY KEY (`id`),
  ADD KEY `site_id` (`site_id`);

--
-- Indexes for table `site_operating_hours`
--
ALTER TABLE `site_operating_hours`
  ADD PRIMARY KEY (`id`),
  ADD KEY `site_id` (`site_id`),
  ADD KEY `fk_site_operating_hours_proj_2024` (`project_id`);

--
-- Indexes for table `site_safety`
--
ALTER TABLE `site_safety`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_site_safety_scheduling` (`scheduling_id`),
  ADD KEY `fk_site_safety_proj_2024` (`project_id`);

--
-- Indexes for table `site_scheduling`
--
ALTER TABLE `site_scheduling`
  ADD PRIMARY KEY (`id`),
  ADD KEY `delivery_id` (`delivery_id`),
  ADD KEY `fk_site_scheduling_proj_2024` (`project_id`);

--
-- Indexes for table `site_users`
--
ALTER TABLE `site_users`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `sunny_memory`
--
ALTER TABLE `sunny_memory`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user_account` (`user_id`,`account_id`),
  ADD KEY `idx_category_entity` (`category`,`entity_id`);

--
-- Indexes for table `unassigned_module_items`
--
ALTER TABLE `unassigned_module_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_unassigned_module_items_module` (`unassigned_module_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`);

--
-- Indexes for table `vendors`
--
ALTER TABLE `vendors`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `warehouses`
--
ALTER TABLE `warehouses`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_warehouses_port` (`is_port`);

--
-- Indexes for table `warehouse_cost_items`
--
ALTER TABLE `warehouse_cost_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_warehouse_id` (`warehouse_id`),
  ADD KEY `idx_trigger_event` (`trigger_event`);

--
-- Indexes for table `warehouse_estimates`
--
ALTER TABLE `warehouse_estimates`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `warehouse_quotes`
--
ALTER TABLE `warehouse_quotes`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `warranty_claims`
--
ALTER TABLE `warranty_claims`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_warranty_scheduling` (`scheduling_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `accounts`
--
ALTER TABLE `accounts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- AUTO_INCREMENT for table `accounts_payable`
--
ALTER TABLE `accounts_payable`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `account_users`
--
ALTER TABLE `account_users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=23;

--
-- AUTO_INCREMENT for table `customer_accounts`
--
ALTER TABLE `customer_accounts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `customer_account_users`
--
ALTER TABLE `customer_account_users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `deliveries`
--
ALTER TABLE `deliveries`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1860;

--
-- AUTO_INCREMENT for table `flash_test_data`
--
ALTER TABLE `flash_test_data`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `forecast_items`
--
ALTER TABLE `forecast_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=54;

--
-- AUTO_INCREMENT for table `forecast_projects`
--
ALTER TABLE `forecast_projects`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- AUTO_INCREMENT for table `freight_estimates`
--
ALTER TABLE `freight_estimates`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=21;

--
-- AUTO_INCREMENT for table `inventory_pallets`
--
ALTER TABLE `inventory_pallets`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT COMMENT 'Unique ID for each pallet', AUTO_INCREMENT=91289;

--
-- AUTO_INCREMENT for table `manufacturers`
--
ALTER TABLE `manufacturers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `manufacturer_locations`
--
ALTER TABLE `manufacturer_locations`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `modules`
--
ALTER TABLE `modules`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=104;

--
-- AUTO_INCREMENT for table `overheads`
--
ALTER TABLE `overheads`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=14;

--
-- AUTO_INCREMENT for table `projects`
--
ALTER TABLE `projects`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=120;

--
-- AUTO_INCREMENT for table `project_invoices`
--
ALTER TABLE `project_invoices`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=41;

--
-- AUTO_INCREMENT for table `project_wattage_orders`
--
ALTER TABLE `project_wattage_orders`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=171;

--
-- AUTO_INCREMENT for table `sites`
--
ALTER TABLE `sites`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=21;

--
-- AUTO_INCREMENT for table `site_module_info`
--
ALTER TABLE `site_module_info`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `site_module_wattages`
--
ALTER TABLE `site_module_wattages`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT for table `site_operating_hours`
--
ALTER TABLE `site_operating_hours`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=596;

--
-- AUTO_INCREMENT for table `site_safety`
--
ALTER TABLE `site_safety`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `site_scheduling`
--
ALTER TABLE `site_scheduling`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=193;

--
-- AUTO_INCREMENT for table `site_users`
--
ALTER TABLE `site_users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=28;

--
-- AUTO_INCREMENT for table `sunny_memory`
--
ALTER TABLE `sunny_memory`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `unassigned_module_items`
--
ALTER TABLE `unassigned_module_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=175;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT for table `vendors`
--
ALTER TABLE `vendors`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `warehouses`
--
ALTER TABLE `warehouses`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- AUTO_INCREMENT for table `warehouse_cost_items`
--
ALTER TABLE `warehouse_cost_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT for table `warehouse_estimates`
--
ALTER TABLE `warehouse_estimates`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=36;

--
-- AUTO_INCREMENT for table `warehouse_quotes`
--
ALTER TABLE `warehouse_quotes`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT for table `warranty_claims`
--
ALTER TABLE `warranty_claims`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=65;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `accounts_payable`
--
ALTER TABLE `accounts_payable`
  ADD CONSTRAINT `fk_ap_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `account_users`
--
ALTER TABLE `account_users`
  ADD CONSTRAINT `fk_account_id` FOREIGN KEY (`account_id`) REFERENCES `accounts` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_user_id` FOREIGN KEY (`user_id`) REFERENCES `site_users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `customer_account_users`
--
ALTER TABLE `customer_account_users`
  ADD CONSTRAINT `fk_cau_account` FOREIGN KEY (`account_id`) REFERENCES `customer_accounts` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_cau_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `deliveries`
--
ALTER TABLE `deliveries`
  ADD CONSTRAINT `deliveries_ibfk_1` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_deliveries_origin_port` FOREIGN KEY (`origin_port_id`) REFERENCES `warehouses` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_deliveries_port_of_entry` FOREIGN KEY (`port_of_entry_id`) REFERENCES `warehouses` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_deliveries_warehouse` FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_delivery_ap` FOREIGN KEY (`accounts_payable_id`) REFERENCES `accounts_payable` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Constraints for table `delivery_pallets`
--
ALTER TABLE `delivery_pallets`
  ADD CONSTRAINT `fk_dp_delivery` FOREIGN KEY (`delivery_id`) REFERENCES `deliveries` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_dp_pallet` FOREIGN KEY (`inventory_pallet_id`) REFERENCES `inventory_pallets` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `flash_test_data`
--
ALTER TABLE `flash_test_data`
  ADD CONSTRAINT `flash_test_data_ibfk_1` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `forecast_items`
--
ALTER TABLE `forecast_items`
  ADD CONSTRAINT `forecast_items_ibfk_1` FOREIGN KEY (`forecast_id`) REFERENCES `forecast_projects` (`id`);

--
-- Constraints for table `forecast_projects`
--
ALTER TABLE `forecast_projects`
  ADD CONSTRAINT `forecast_projects_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `forecast_projects_ibfk_2` FOREIGN KEY (`linked_project_id`) REFERENCES `projects` (`id`);

--
-- Constraints for table `freight_estimates`
--
ALTER TABLE `freight_estimates`
  ADD CONSTRAINT `freight_estimates_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`);

--
-- Constraints for table `inventory_pallets`
--
ALTER TABLE `inventory_pallets`
  ADD CONSTRAINT `fk_inventory_pallets_manufacturer_location` FOREIGN KEY (`manufacturer_location_id`) REFERENCES `manufacturer_locations` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_pallet_assigned_project` FOREIGN KEY (`assigned_project_id`) REFERENCES `projects` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `inventory_pallets_ibfk_1` FOREIGN KEY (`unassigned_module_item_id`) REFERENCES `unassigned_module_items` (`id`),
  ADD CONSTRAINT `inventory_pallets_ibfk_2` FOREIGN KEY (`current_warehouse_id`) REFERENCES `warehouses` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `inventory_pallets_ibfk_3` FOREIGN KEY (`current_project_id`) REFERENCES `projects` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `manufacturer_locations`
--
ALTER TABLE `manufacturer_locations`
  ADD CONSTRAINT `manufacturer_locations_ibfk_1` FOREIGN KEY (`manufacturer_id`) REFERENCES `manufacturers` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `modules`
--
ALTER TABLE `modules`
  ADD CONSTRAINT `fk_unassigned_modules_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Constraints for table `projects`
--
ALTER TABLE `projects`
  ADD CONSTRAINT `fk_admin` FOREIGN KEY (`admin_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `project_invoices`
--
ALTER TABLE `project_invoices`
  ADD CONSTRAINT `project_invoices_ibfk_1` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `project_wattage_orders`
--
ALTER TABLE `project_wattage_orders`
  ADD CONSTRAINT `project_wattage_orders_ibfk_1` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `site_module_info`
--
ALTER TABLE `site_module_info`
  ADD CONSTRAINT `fk_site_module_info_site` FOREIGN KEY (`site_id`) REFERENCES `sites` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `site_module_wattages`
--
ALTER TABLE `site_module_wattages`
  ADD CONSTRAINT `fk_wattages_site` FOREIGN KEY (`site_id`) REFERENCES `sites` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `site_operating_hours`
--
ALTER TABLE `site_operating_hours`
  ADD CONSTRAINT `fk_site_operating_hours_proj_2024` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `site_safety`
--
ALTER TABLE `site_safety`
  ADD CONSTRAINT `fk_site_safety_proj_2024` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_site_safety_scheduling` FOREIGN KEY (`scheduling_id`) REFERENCES `site_scheduling` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `site_scheduling`
--
ALTER TABLE `site_scheduling`
  ADD CONSTRAINT `fk_site_scheduling_proj_2024` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `sunny_memory`
--
ALTER TABLE `sunny_memory`
  ADD CONSTRAINT `sunny_memory_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `unassigned_module_items`
--
ALTER TABLE `unassigned_module_items`
  ADD CONSTRAINT `fk_unassigned_module_items_module` FOREIGN KEY (`unassigned_module_id`) REFERENCES `modules` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `warehouse_cost_items`
--
ALTER TABLE `warehouse_cost_items`
  ADD CONSTRAINT `fk_warehouse_cost_items_warehouse` FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `warehouse_estimates`
--
ALTER TABLE `warehouse_estimates`
  ADD CONSTRAINT `warehouse_estimates_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`);

--
-- Constraints for table `warehouse_quotes`
--
ALTER TABLE `warehouse_quotes`
  ADD CONSTRAINT `warehouse_quotes_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`);

--
-- Constraints for table `warranty_claims`
--
ALTER TABLE `warranty_claims`
  ADD CONSTRAINT `fk_warranty_scheduling` FOREIGN KEY (`scheduling_id`) REFERENCES `site_scheduling` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
