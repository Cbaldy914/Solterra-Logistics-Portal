-- Solterra Portal Schema Summary
-- Source: solterra_portal.sql
-- Generated: 2026-02-26 16:54:00 UTC
-- Contains: structure only (tables, indexes, auto-increment definitions, views, triggers, constraints).
-- Excludes: all INSERT data blocks.
-- Snapshot summary: 80 tables, 6 triggers, 5 views, constraints on 65 tables.

-- phpMyAdmin SQL Dump
-- version 5.2.2
-- https://www.phpmyadmin.net/
--
-- Host: localhost:3306
-- Generation Time: Feb 26, 2026 at 08:55 AM
-- Server version: 10.6.24-MariaDB-cll-lve
-- PHP Version: 8.3.30

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `solterra_portal`
--

DELIMITER $$
--
-- Procedures
--
$$

DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `accounts`
--

CREATE TABLE `accounts` (
  `id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
--


-- --------------------------------------------------------

--
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
--


-- --------------------------------------------------------

--
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
--


-- --------------------------------------------------------

--
-- Table structure for table `anticipated_delivery_schedule`
--

CREATE TABLE `anticipated_delivery_schedule` (
  `id` int(11) NOT NULL,
  `project_id` int(11) NOT NULL,
  `schedule_type` enum('quick','detailed') DEFAULT 'quick' COMMENT 'Quick = rate-based, Detailed = week-by-week',
  `delivery_start_date` date NOT NULL COMMENT 'When deliveries begin',
  `quick_rate_value` decimal(10,3) DEFAULT NULL COMMENT 'e.g., 2.5 MW or 500 modules',
  `quick_rate_unit` enum('mw','modules','pallets','trucks') DEFAULT NULL COMMENT 'Unit of measurement',
  `quick_rate_frequency` enum('per_week','per_month') DEFAULT 'per_week' COMMENT 'Delivery frequency',
  `is_active` tinyint(1) DEFAULT 1 COMMENT 'Only one active schedule per project',
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `notes` text DEFAULT NULL COMMENT 'Admin notes about the schedule'
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
--


-- --------------------------------------------------------

--
-- Table structure for table `anticipated_delivery_schedule_details`
--

CREATE TABLE `anticipated_delivery_schedule_details` (
  `id` int(11) NOT NULL,
  `schedule_id` int(11) NOT NULL,
  `delivery_week_ending` date NOT NULL COMMENT 'Sunday of the delivery week',
  `delivery_amount` decimal(10,3) NOT NULL COMMENT 'Amount to be delivered this week',
  `delivery_unit` enum('mw','modules','pallets','trucks') NOT NULL COMMENT 'Unit of measurement',
  `notes` text DEFAULT NULL COMMENT 'Notes for this specific week',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------

--
-- Table structure for table `archived_projects`
--

CREATE TABLE `archived_projects` (
  `id` int(11) NOT NULL,
  `project_id` int(11) NOT NULL,
  `account_id` int(11) DEFAULT NULL,
  `project_name` varchar(255) NOT NULL,
  `archive_path` varchar(500) NOT NULL,
  `archive_filename` varchar(255) NOT NULL,
  `file_size_bytes` bigint(20) DEFAULT 0,
  `closed_by` int(11) NOT NULL,
  `closed_at` datetime NOT NULL DEFAULT current_timestamp(),
  `summary_text` text DEFAULT NULL,
  `delivery_percent` decimal(5,2) DEFAULT 0.00,
  `total_modules` int(11) DEFAULT 0,
  `delivered_modules` int(11) DEFAULT 0,
  `notes` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `total_module_cost` decimal(12,2) DEFAULT 0.00 COMMENT 'Total cost of modules for the project',
  `total_freight_cost` decimal(12,2) DEFAULT 0.00 COMMENT 'Total freight/shipping costs',
  `total_warehousing_cost` decimal(12,2) DEFAULT 0.00 COMMENT 'Total warehousing costs (entry, exit, monthly)',
  `total_accessorial_cost` decimal(12,2) DEFAULT 0.00 COMMENT 'Total accessorial costs',
  `total_miles` decimal(12,2) DEFAULT 0.00 COMMENT 'Total miles driven for deliveries',
  `total_fuel_gallons` decimal(12,2) DEFAULT 0.00 COMMENT 'Estimated fuel consumption in gallons',
  `total_co2_kg` decimal(12,2) DEFAULT 0.00 COMMENT 'Estimated CO2 emissions in kg',
  `total_truckloads` int(11) DEFAULT 0 COMMENT 'Total number of truckloads/deliveries',
  `primary_manufacturer` varchar(255) DEFAULT NULL COMMENT 'Manufacturer with most modules',
  `manufacturer_count` int(11) DEFAULT 0 COMMENT 'Number of distinct manufacturers',
  `total_wattage` bigint(20) DEFAULT 0 COMMENT 'Total wattage in watts',
  `total_pallets` int(11) DEFAULT 0 COMMENT 'Total number of pallets',
  `total_deliveries` int(11) DEFAULT 0 COMMENT 'Total number of deliveries',
  `on_time_deliveries` int(11) DEFAULT 0 COMMENT 'Deliveries that arrived on or before anticipated date',
  `late_deliveries` int(11) DEFAULT 0 COMMENT 'Deliveries that arrived after anticipated date',
  `avg_days_late` decimal(5,1) DEFAULT NULL COMMENT 'Average days late for late deliveries',
  `project_completed_on_time` tinyint(1) DEFAULT NULL COMMENT '1 if all deliveries completed before project end date',
  `damaged_modules_count` int(11) DEFAULT 0 COMMENT 'Total modules reported as damaged',
  `warranty_claims_count` int(11) DEFAULT 0 COMMENT 'Number of warranty claims filed'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
--


-- --------------------------------------------------------

--
-- Table structure for table `container_tracking_positions`
--

CREATE TABLE `container_tracking_positions` (
  `id` int(11) NOT NULL,
  `container_number` varchar(64) NOT NULL,
  `project_id` int(11) NOT NULL,
  `latitude` decimal(10,7) DEFAULT NULL,
  `longitude` decimal(10,7) DEFAULT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
--


-- --------------------------------------------------------

--
-- Table structure for table `container_tracking_waypoints`
--

CREATE TABLE `container_tracking_waypoints` (
  `id` int(11) NOT NULL,
  `container_number` varchar(64) NOT NULL,
  `project_id` int(11) NOT NULL,
  `latitude` decimal(10,7) NOT NULL,
  `longitude` decimal(10,7) NOT NULL,
  `recorded_by` int(11) DEFAULT NULL,
  `recorded_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
--


-- --------------------------------------------------------

--
-- Table structure for table `customer_accounts`
--

CREATE TABLE `customer_accounts` (
  `id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
--


-- --------------------------------------------------------

--
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
--


-- --------------------------------------------------------

--
-- Table structure for table `deliveries`
--

CREATE TABLE `deliveries` (
  `id` int(11) NOT NULL,
  `schedule_upload_id` int(11) DEFAULT NULL,
  `data_source` enum('manual','schedule_import') DEFAULT 'manual',
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
  `customs_fee_override` decimal(10,2) DEFAULT NULL,
  `customs_fee_notes` varchar(255) DEFAULT NULL,
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
  `customs_hold_started_date` date DEFAULT NULL,
  `customs_hold_released_date` date DEFAULT NULL,
  `customs_hold_reason` varchar(255) DEFAULT NULL,
  `customs_hold_notes` text DEFAULT NULL,
  `is_overseas_shipment` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Whether this is an overseas shipment',
  `origin_port_id` int(11) DEFAULT NULL COMMENT 'FK to warehouses.id where is_port=1 - departure port for overseas shipments'
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
--


-- --------------------------------------------------------

--
-- Table structure for table `delivery_milestone_instances`
--

CREATE TABLE `delivery_milestone_instances` (
  `id` int(11) NOT NULL,
  `delivery_id` int(11) DEFAULT NULL COMMENT 'FK to deliveries.id (NULL for batch-level milestones)',
  `module_batch_id` int(11) DEFAULT NULL COMMENT 'FK to modules.id for batch-level milestones (e.g., PO execution)',
  `milestone_id` int(11) NOT NULL COMMENT 'FK to module_batch_milestones.id',
  `triggered_at` datetime NOT NULL COMMENT 'When the milestone was triggered',
  `triggered_by_user_id` int(11) DEFAULT NULL COMMENT 'User who triggered the status change',
  `module_quantity` int(11) NOT NULL COMMENT 'Number of modules in delivery at trigger time',
  `wattage` int(11) NOT NULL COMMENT 'Module wattage',
  `cost_per_watt` decimal(10,6) DEFAULT NULL COMMENT 'Snapshot of cost_per_watt at trigger time',
  `milestone_percentage` decimal(5,2) NOT NULL COMMENT 'Snapshot of percentage at trigger time',
  `payment_amount` decimal(12,2) NOT NULL COMMENT 'Calculated: cost_per_watt * wattage * quantity * percentage',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Records of payment milestones triggered per delivery';

--
--


-- --------------------------------------------------------

--
-- Table structure for table `delivery_pallets`
--

CREATE TABLE `delivery_pallets` (
  `delivery_id` int(11) NOT NULL,
  `inventory_pallet_id` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
--


-- --------------------------------------------------------

--
-- Table structure for table `delivery_projections`
--

CREATE TABLE `delivery_projections` (
  `id` int(11) NOT NULL,
  `project_id` int(11) DEFAULT NULL,
  `projection_name` varchar(100) NOT NULL DEFAULT 'Default Projection',
  `is_primary` tinyint(1) NOT NULL DEFAULT 0,
  `is_template` tinyint(1) NOT NULL DEFAULT 0,
  `is_general` tinyint(1) NOT NULL DEFAULT 0,
  `general_project_name` varchar(255) DEFAULT NULL,
  `general_project_address` varchar(500) DEFAULT NULL,
  `general_estimated_mw` decimal(10,2) DEFAULT NULL,
  `linked_project_id` int(11) DEFAULT NULL,
  `template_name` varchar(100) DEFAULT NULL,
  `status` enum('draft','active','archived') NOT NULL DEFAULT 'draft',
  `notes` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
--


-- --------------------------------------------------------

--
-- Table structure for table `delivery_status_events`
--

CREATE TABLE `delivery_status_events` (
  `id` int(11) NOT NULL,
  `delivery_id` int(11) NOT NULL,
  `from_status` varchar(50) DEFAULT NULL,
  `to_status` varchar(50) NOT NULL,
  `event_type` varchar(64) NOT NULL,
  `event_notes` text DEFAULT NULL,
  `changed_by` int(11) DEFAULT NULL,
  `changed_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
--


-- --------------------------------------------------------

--
-- Table structure for table `demo_requests`
--

CREATE TABLE `demo_requests` (
  `id` int(11) NOT NULL,
  `company_name` varchar(255) NOT NULL,
  `contact_name` varchar(255) NOT NULL,
  `email` varchar(255) NOT NULL,
  `phone` varchar(50) DEFAULT NULL,
  `project_location` varchar(255) DEFAULT NULL,
  `estimated_volume` varchar(100) DEFAULT NULL,
  `message` text DEFAULT NULL,
  `submitted_at` datetime DEFAULT current_timestamp(),
  `status` enum('new','contacted','converted','declined') DEFAULT 'new',
  `ip_address` varchar(45) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
--


-- --------------------------------------------------------

--
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
--


-- --------------------------------------------------------

--
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
--


-- --------------------------------------------------------

--
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
--


-- --------------------------------------------------------

--
-- Table structure for table `inventory_pallets`
--

CREATE TABLE `inventory_pallets` (
  `id` int(11) NOT NULL COMMENT 'Unique ID for each pallet',
  `pallet_identifier` varchar(255) DEFAULT NULL COMMENT 'Optional user-defined label, barcode, etc.',
  `manufacturer_pallet_id` varchar(100) DEFAULT NULL,
  `schedule_upload_id` int(11) DEFAULT NULL,
  `unassigned_module_item_id` int(11) NOT NULL COMMENT 'FK to unassigned_module_items.id',
  `assigned_project_id` int(11) DEFAULT NULL COMMENT 'FK to projects.id, indicates the project this pallet is intended for',
  `wattage` int(11) NOT NULL COMMENT 'Wattage of modules on this pallet',
  `quantity` int(11) NOT NULL COMMENT 'Number of modules on this specific pallet',
  `current_warehouse_id` int(11) DEFAULT NULL COMMENT 'FK to warehouses.id, NULL if not in a warehouse',
  `current_project_id` int(11) DEFAULT NULL COMMENT 'FK to projects.id, NULL until delivered to project',
  `status` enum('At Manufacturer','On Water','Cleared Customs','Customs Hold','In Transit to Warehouse','In Warehouse','Allocated to Project','In Transit to Project','Delivered to Project','Damaged') NOT NULL DEFAULT 'At Manufacturer',
  `arrival_date` datetime DEFAULT NULL COMMENT 'When pallet first recorded in system (e.g., at first warehouse)',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `flash_test_data` varchar(255) DEFAULT NULL,
  `manufacturer` varchar(255) DEFAULT NULL COMMENT 'Manufacturer name extracted from modules vendor_name',
  `manufacturer_location_id` int(11) DEFAULT NULL COMMENT 'FK to manufacturer_locations.id for specific location',
  `warehouse_cost` decimal(10,2) DEFAULT 0.00,
  `freight_cost` decimal(10,2) DEFAULT 0.00,
  `accessorial_cost` decimal(10,2) DEFAULT 0.00,
  `customs_hold_cost` decimal(10,2) NOT NULL DEFAULT 0.00,
  `customs_hold_cost_notes` varchar(255) DEFAULT NULL,
  `customs_hold_cost_updated_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
--


-- --------------------------------------------------------

--
-- Table structure for table `login_attempts`
--

CREATE TABLE `login_attempts` (
  `id` int(11) NOT NULL,
  `username` varchar(255) NOT NULL,
  `ip_address` varchar(45) NOT NULL,
  `user_agent` text DEFAULT NULL,
  `attempt_time` datetime DEFAULT current_timestamp(),
  `success` tinyint(1) NOT NULL,
  `failure_reason` varchar(100) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
--


-- --------------------------------------------------------

--
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
--


--
-- Triggers `manufacturers`
--
DELIMITER $$
CREATE TRIGGER `update_manufacturer_address_on_insert` BEFORE INSERT ON `manufacturers` FOR EACH ROW BEGIN
    IF NEW.street_address IS NOT NULL OR NEW.city IS NOT NULL OR NEW.state IS NOT NULL OR NEW.zip_code IS NOT NULL THEN
        SET NEW.address = CONCAT_WS(', ', 
            NULLIF(NEW.street_address, ''),
            NULLIF(NEW.city, ''),
            CASE 
                WHEN NEW.state IS NOT NULL AND NEW.zip_code IS NOT NULL 
                THEN CONCAT(NEW.state, ' ', NEW.zip_code)
                WHEN NEW.state IS NOT NULL 
                THEN NEW.state
                WHEN NEW.zip_code IS NOT NULL 
                THEN NEW.zip_code
                ELSE NULL
            END,
            NULLIF(NEW.country, '')
        );
    END IF;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `update_manufacturer_address_on_update` BEFORE UPDATE ON `manufacturers` FOR EACH ROW BEGIN
    IF NEW.street_address IS NOT NULL OR NEW.city IS NOT NULL OR NEW.state IS NOT NULL OR NEW.zip_code IS NOT NULL THEN
        SET NEW.address = CONCAT_WS(', ', 
            NULLIF(NEW.street_address, ''),
            NULLIF(NEW.city, ''),
            CASE 
                WHEN NEW.state IS NOT NULL AND NEW.zip_code IS NOT NULL 
                THEN CONCAT(NEW.state, ' ', NEW.zip_code)
                WHEN NEW.state IS NOT NULL 
                THEN NEW.state
                WHEN NEW.zip_code IS NOT NULL 
                THEN NEW.zip_code
                ELSE NULL
            END,
            NULLIF(NEW.country, '')
        );
    END IF;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `manufacturer_column_mappings`
--

CREATE TABLE `manufacturer_column_mappings` (
  `id` int(11) NOT NULL,
  `manufacturer_id` int(11) NOT NULL,
  `account_id` int(11) NOT NULL,
  `mapping_name` varchar(100) DEFAULT 'Default Mapping',
  `column_mappings` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`column_mappings`)),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `created_by` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
--


-- --------------------------------------------------------

--
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
--


-- --------------------------------------------------------

--
-- Table structure for table `manufacturer_location_requests`
--

CREATE TABLE `manufacturer_location_requests` (
  `id` int(11) NOT NULL,
  `manufacturer_id` int(11) NOT NULL,
  `account_id` int(11) NOT NULL,
  `requested_by` int(11) NOT NULL,
  `status` enum('pending','approved','rejected') DEFAULT 'pending',
  `location_name` varchar(255) NOT NULL,
  `street_address` varchar(255) DEFAULT NULL,
  `city` varchar(100) DEFAULT NULL,
  `state` varchar(50) DEFAULT NULL,
  `zip_code` varchar(20) DEFAULT NULL,
  `country` varchar(100) DEFAULT 'USA',
  `is_primary` tinyint(1) DEFAULT 0,
  `is_active` tinyint(1) DEFAULT 1,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `reviewed_by` int(11) DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `rejection_reason` text DEFAULT NULL,
  `approved_location_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------

--
-- Table structure for table `manufacturer_requests`
--

CREATE TABLE `manufacturer_requests` (
  `id` int(11) NOT NULL,
  `account_id` int(11) NOT NULL,
  `requested_by` int(11) NOT NULL,
  `status` enum('pending','approved','rejected') DEFAULT 'pending',
  `name` varchar(255) NOT NULL,
  `short_name` varchar(255) DEFAULT NULL,
  `contact_person` varchar(255) DEFAULT NULL,
  `phone` varchar(50) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `website` varchar(255) DEFAULT NULL,
  `street_address` varchar(255) DEFAULT NULL,
  `city` varchar(100) DEFAULT NULL,
  `state` varchar(50) DEFAULT NULL,
  `zip_code` varchar(20) DEFAULT NULL,
  `country` varchar(100) DEFAULT 'USA',
  `logo_url` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `reviewed_by` int(11) DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `rejection_reason` text DEFAULT NULL,
  `approved_manufacturer_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------

--
-- Table structure for table `modules`
--

CREATE TABLE `modules` (
  `id` int(11) NOT NULL,
  `batch_name` varchar(255) DEFAULT NULL,
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
  `module_additional_notes` text DEFAULT NULL,
  `data_source` enum('manual','schedule_import') DEFAULT 'manual',
  `cost_per_watt` decimal(10,6) DEFAULT NULL COMMENT 'Module cost in price per watt (optional)',
  `po_execution_date` date DEFAULT NULL COMMENT 'Date when the PO was executed for this module batch'
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
--


-- --------------------------------------------------------

--
-- Table structure for table `module_batch_milestones`
--

CREATE TABLE `module_batch_milestones` (
  `id` int(11) NOT NULL,
  `module_id` int(11) NOT NULL COMMENT 'FK to modules.id (module batch)',
  `milestone_name` varchar(100) NOT NULL COMMENT 'e.g., PO Execution, Customs Clearance',
  `trigger_event` enum('po_execution','customs_cleared','shipping','project_delivery') NOT NULL,
  `percentage` decimal(5,2) NOT NULL COMMENT 'Percentage of total value (0.00 - 100.00)',
  `display_order` int(11) DEFAULT 1 COMMENT 'Order for display purposes',
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='Payment milestone configuration at module batch level';

--
--


-- --------------------------------------------------------

--
-- Table structure for table `module_batch_reconciliation_audit`
--

CREATE TABLE `module_batch_reconciliation_audit` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `module_batch_id` int(11) NOT NULL,
  `project_id` int(11) DEFAULT NULL,
  `account_id` int(11) DEFAULT NULL,
  `action_type` varchar(64) NOT NULL,
  `reason` varchar(255) DEFAULT NULL,
  `reconciliation_mode` varchar(32) DEFAULT NULL,
  `preview_signature` char(64) DEFAULT NULL,
  `actor_user_id` int(11) DEFAULT NULL,
  `actor_role` varchar(32) DEFAULT NULL,
  `source_page` varchar(128) DEFAULT NULL,
  `before_state_json` longtext DEFAULT NULL,
  `after_state_json` longtext DEFAULT NULL,
  `impact_json` longtext DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
--


-- --------------------------------------------------------

--
-- Table structure for table `notifications`
--

CREATE TABLE `notifications` (
  `id` int(10) UNSIGNED NOT NULL,
  `user_id` int(11) NOT NULL,
  `type` varchar(64) NOT NULL,
  `title` varchar(190) NOT NULL,
  `message` text DEFAULT NULL,
  `link` varchar(255) DEFAULT NULL,
  `read_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
--


-- --------------------------------------------------------

--
-- Table structure for table `notification_settings`
--

CREATE TABLE `notification_settings` (
  `user_id` int(11) NOT NULL,
  `in_app_document_upload` tinyint(1) NOT NULL DEFAULT 1,
  `in_app_project_update` tinyint(1) NOT NULL DEFAULT 1,
  `in_app_delivery_status` tinyint(1) NOT NULL DEFAULT 1,
  `in_app_warranty_claim` tinyint(1) NOT NULL DEFAULT 1,
  `email_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `email_document_upload` tinyint(1) NOT NULL DEFAULT 0,
  `email_project_update` tinyint(1) NOT NULL DEFAULT 0,
  `email_delivery_status` tinyint(1) NOT NULL DEFAULT 0,
  `email_warranty_claim` tinyint(1) NOT NULL DEFAULT 0,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `in_app_freight_estimate_request` tinyint(1) NOT NULL DEFAULT 0,
  `in_app_freight_estimate_rated` tinyint(1) NOT NULL DEFAULT 0,
  `email_freight_estimate_request` tinyint(1) NOT NULL DEFAULT 0,
  `email_freight_estimate_rated` tinyint(1) NOT NULL DEFAULT 0,
  `in_app_warehouse_estimate_request` tinyint(1) NOT NULL DEFAULT 0,
  `in_app_warehouse_estimate_rated` tinyint(1) NOT NULL DEFAULT 0,
  `email_warehouse_estimate_request` tinyint(1) NOT NULL DEFAULT 0,
  `email_warehouse_estimate_rated` tinyint(1) NOT NULL DEFAULT 0,
  `in_app_planning_activation_request` tinyint(1) NOT NULL DEFAULT 1,
  `in_app_planning_activation_approved` tinyint(1) NOT NULL DEFAULT 1,
  `email_planning_activation_request` tinyint(1) NOT NULL DEFAULT 0,
  `email_planning_activation_approved` tinyint(1) NOT NULL DEFAULT 0,
  `in_app_manufacturer_request` tinyint(1) DEFAULT 1,
  `email_manufacturer_request` tinyint(1) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
--


-- --------------------------------------------------------

--
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
--


-- --------------------------------------------------------

--
-- Table structure for table `password_reset_tokens`
--

CREATE TABLE `password_reset_tokens` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `token` varchar(64) NOT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `expires_at` datetime NOT NULL,
  `used` tinyint(1) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
--


-- --------------------------------------------------------

--
-- Table structure for table `pending_requests`
--

CREATE TABLE `pending_requests` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `account_id` int(11) NOT NULL,
  `request_type` enum('project','module','manufacturer') NOT NULL,
  `request_data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`request_data`)),
  `status` enum('pending','approved','rejected') DEFAULT 'pending',
  `admin_notes` text DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `planning_activation_requests`
--

CREATE TABLE `planning_activation_requests` (
  `id` int(11) NOT NULL,
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
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `planning_allocations`
--

CREATE TABLE `planning_allocations` (
  `id` int(11) NOT NULL,
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
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
--


-- --------------------------------------------------------

--
-- Table structure for table `planning_contract_period_rules`
--

CREATE TABLE `planning_contract_period_rules` (
  `id` int(11) NOT NULL,
  `contract_id` int(11) NOT NULL,
  `period_start` date NOT NULL,
  `period_end` date NOT NULL,
  `min_mw` decimal(12,3) DEFAULT NULL,
  `max_mw` decimal(12,3) DEFAULT NULL,
  `is_min_hard` tinyint(1) NOT NULL DEFAULT 1,
  `is_max_hard` tinyint(1) NOT NULL DEFAULT 0,
  `notes` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `planning_contract_push_rules`
--

CREATE TABLE `planning_contract_push_rules` (
  `id` int(11) NOT NULL,
  `contract_id` int(11) NOT NULL,
  `from_period_start` date NOT NULL,
  `from_period_end` date NOT NULL,
  `to_period_start` date NOT NULL,
  `to_period_end` date NOT NULL,
  `max_mw_pushable` decimal(12,3) NOT NULL,
  `notes` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `planning_framework_contracts`
--

CREATE TABLE `planning_framework_contracts` (
  `id` int(11) NOT NULL,
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
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
--


-- --------------------------------------------------------

--
-- Table structure for table `planning_inventory_pools`
--

CREATE TABLE `planning_inventory_pools` (
  `id` int(11) NOT NULL,
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
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `planning_manufacturers`
--

CREATE TABLE `planning_manufacturers` (
  `id` int(11) NOT NULL,
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
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
--


-- --------------------------------------------------------

--
-- Table structure for table `planning_projects`
--

CREATE TABLE `planning_projects` (
  `id` int(11) NOT NULL,
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
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
--


-- --------------------------------------------------------

--
-- Table structure for table `planning_risk_alerts`
--

CREATE TABLE `planning_risk_alerts` (
  `id` int(11) NOT NULL,
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
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `planning_scenarios`
--

CREATE TABLE `planning_scenarios` (
  `id` int(11) NOT NULL,
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
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
--


-- --------------------------------------------------------

--
-- Table structure for table `planning_sunny_notes`
--

CREATE TABLE `planning_sunny_notes` (
  `id` int(11) NOT NULL,
  `scenario_id` int(11) NOT NULL,
  `note_type` enum('optimization','user','system') NOT NULL DEFAULT 'user',
  `content` text NOT NULL,
  `linked_contract_id` int(11) DEFAULT NULL,
  `linked_planning_project_id` int(11) DEFAULT NULL,
  `linked_active_project_id` int(11) DEFAULT NULL,
  `linked_allocation_id` int(11) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `projection_cost_summary`
--

CREATE TABLE `projection_cost_summary` (
  `id` int(11) NOT NULL,
  `projection_id` int(11) NOT NULL,
  `module_contract_value` decimal(12,2) NOT NULL DEFAULT 0.00,
  `total_milestone_payments` decimal(12,2) NOT NULL DEFAULT 0.00,
  `total_freight_cost` decimal(12,2) NOT NULL DEFAULT 0.00,
  `total_warehousing_cost` decimal(12,2) NOT NULL DEFAULT 0.00,
  `total_customs_cost` decimal(12,2) NOT NULL DEFAULT 0.00,
  `total_other_cost` decimal(12,2) NOT NULL DEFAULT 0.00,
  `grand_total` decimal(12,2) NOT NULL DEFAULT 0.00,
  `last_calculated` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
--


-- --------------------------------------------------------

--
-- Table structure for table `projection_legs`
--

CREATE TABLE `projection_legs` (
  `id` int(11) NOT NULL,
  `projection_id` int(11) NOT NULL,
  `from_stop_id` int(11) NOT NULL,
  `to_stop_id` int(11) NOT NULL,
  `leg_order` int(11) NOT NULL DEFAULT 0,
  `transport_mode` enum('ocean','truck','rail','air') NOT NULL DEFAULT 'truck',
  `start_date` date DEFAULT NULL,
  `end_date` date DEFAULT NULL,
  `delivery_rate` decimal(5,2) DEFAULT NULL,
  `delivery_rate_unit` enum('per_day','per_week','per_month') DEFAULT 'per_week',
  `trucks_required` int(11) DEFAULT NULL,
  `freight_cost_per_truck` decimal(10,2) DEFAULT NULL,
  `accessorial_cost_per_truck` decimal(10,2) DEFAULT NULL,
  `total_freight_cost` decimal(12,2) DEFAULT NULL,
  `triggers_milestone` enum('shipping','customs_cleared','project_delivery') DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
--


-- --------------------------------------------------------

--
-- Table structure for table `projection_modules`
--

CREATE TABLE `projection_modules` (
  `id` int(11) NOT NULL,
  `account_id` int(11) NOT NULL,
  `projection_id` int(11) DEFAULT NULL,
  `vendor_name` varchar(255) NOT NULL,
  `initial_location` varchar(255) NOT NULL,
  `cost_per_watt` decimal(10,6) DEFAULT NULL,
  `modules_per_pallet` int(11) DEFAULT NULL,
  `pallets_per_truck` int(11) DEFAULT NULL,
  `modules_per_truck` int(11) DEFAULT NULL,
  `module_notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `last_updated_at` timestamp NOT NULL DEFAULT '0000-00-00 00:00:00' ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
--


-- --------------------------------------------------------

--
-- Table structure for table `projection_module_allocations`
--

CREATE TABLE `projection_module_allocations` (
  `id` int(11) NOT NULL,
  `projection_id` int(11) NOT NULL,
  `module_id` int(11) NOT NULL,
  `wattage` int(11) NOT NULL,
  `quantity` int(11) NOT NULL,
  `pallets` int(11) DEFAULT NULL,
  `is_projection_module` tinyint(1) NOT NULL DEFAULT 0,
  `po_execution_date` date DEFAULT NULL COMMENT 'Date when the PO was executed for milestone calculations',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
--


-- --------------------------------------------------------

--
-- Table structure for table `projection_module_items`
--

CREATE TABLE `projection_module_items` (
  `id` int(11) NOT NULL,
  `projection_module_id` int(11) NOT NULL,
  `wattage` int(11) NOT NULL,
  `quantity` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
--


-- --------------------------------------------------------

--
-- Table structure for table `projection_module_milestones`
--

CREATE TABLE `projection_module_milestones` (
  `id` int(11) NOT NULL,
  `projection_module_id` int(11) NOT NULL,
  `milestone_name` varchar(100) NOT NULL,
  `trigger_event` enum('po_execution','shipping','customs_cleared','project_delivery') NOT NULL,
  `percentage` decimal(5,2) NOT NULL DEFAULT 0.00,
  `display_order` int(11) DEFAULT 0,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------

--
-- Table structure for table `projection_stops`
--

CREATE TABLE `projection_stops` (
  `id` int(11) NOT NULL,
  `projection_id` int(11) NOT NULL,
  `stop_order` int(11) NOT NULL DEFAULT 0,
  `stop_type` enum('origin','warehouse','port','customs','destination') NOT NULL,
  `location_name` varchar(255) NOT NULL,
  `location_address` varchar(500) DEFAULT NULL,
  `latitude` decimal(10,8) DEFAULT NULL,
  `longitude` decimal(11,8) DEFAULT NULL,
  `warehouse_id` int(11) DEFAULT NULL,
  `is_customs_clearance` tinyint(1) NOT NULL DEFAULT 0,
  `estimated_arrival_date` date DEFAULT NULL,
  `estimated_departure_date` date DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
--


-- --------------------------------------------------------

--
-- Table structure for table `projection_stop_fees`
--

CREATE TABLE `projection_stop_fees` (
  `id` int(11) NOT NULL,
  `stop_id` int(11) NOT NULL,
  `fee_type` enum('receiving','storage','outbound','customs','handling','other') NOT NULL,
  `fee_name` varchar(100) NOT NULL,
  `rate` decimal(10,2) NOT NULL,
  `rate_unit` enum('per_pallet','per_module','per_truck','per_day','flat') NOT NULL DEFAULT 'per_pallet',
  `quantity` int(11) DEFAULT NULL,
  `duration_days` int(11) DEFAULT NULL,
  `estimated_cost` decimal(12,2) NOT NULL DEFAULT 0.00,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
--


-- --------------------------------------------------------

--
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
  `site_contact_name` varchar(255) DEFAULT NULL,
  `site_contact_email` varchar(255) DEFAULT NULL,
  `site_contact_phone` varchar(50) DEFAULT NULL,
  `appointment_duration` int(11) NOT NULL DEFAULT 30,
  `status` enum('active','closed') DEFAULT 'active',
  `manual_health_status` enum('on_track','at_risk','behind') DEFAULT NULL COMMENT 'Manual override for project health status (NULL = auto-calculated)',
  `manual_health_reason` text DEFAULT NULL COMMENT 'Required explanation when admin sets manual health status',
  `manual_health_set_by` int(11) DEFAULT NULL COMMENT 'User ID who set the manual health status',
  `manual_health_set_at` datetime DEFAULT NULL COMMENT 'When the manual health status was set'
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
--


--
-- Triggers `projects`
--
DELIMITER $$
CREATE TRIGGER `update_project_address_on_insert` BEFORE INSERT ON `projects` FOR EACH ROW BEGIN
    IF NEW.street_address IS NOT NULL OR NEW.city IS NOT NULL OR NEW.state IS NOT NULL OR NEW.zip_code IS NOT NULL THEN
        SET NEW.project_address = CONCAT_WS(', ', 
            NULLIF(NEW.street_address, ''),
            NULLIF(NEW.city, ''),
            CASE 
                WHEN NEW.state IS NOT NULL AND NEW.zip_code IS NOT NULL 
                THEN CONCAT(NEW.state, ' ', NEW.zip_code)
                WHEN NEW.state IS NOT NULL 
                THEN NEW.state
                WHEN NEW.zip_code IS NOT NULL 
                THEN NEW.zip_code
                ELSE NULL
            END
        );
    END IF;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `update_project_address_on_update` BEFORE UPDATE ON `projects` FOR EACH ROW BEGIN
    IF NEW.street_address IS NOT NULL OR NEW.city IS NOT NULL OR NEW.state IS NOT NULL OR NEW.zip_code IS NOT NULL THEN
        SET NEW.project_address = CONCAT_WS(', ', 
            NULLIF(NEW.street_address, ''),
            NULLIF(NEW.city, ''),
            CASE 
                WHEN NEW.state IS NOT NULL AND NEW.zip_code IS NOT NULL 
                THEN CONCAT(NEW.state, ' ', NEW.zip_code)
                WHEN NEW.state IS NOT NULL 
                THEN NEW.state
                WHEN NEW.zip_code IS NOT NULL 
                THEN NEW.zip_code
                ELSE NULL
            END
        );
    END IF;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `project_documents`
--

CREATE TABLE `project_documents` (
  `id` int(11) NOT NULL,
  `project_id` int(11) DEFAULT NULL,
  `document_type` varchar(100) NOT NULL COMMENT 'invoices, pods, flash_test_data, bills_of_lading, warehousing, modules, delivery_packet, incident_reports, safe_harbor_evidence',
  `document_sub_type` varchar(100) DEFAULT NULL COMMENT 'Sub-category for better filtering (e.g., Warehouse PODs, Project PODs, Solterra Invoices, OEM Invoices)',
  `file_name` varchar(255) NOT NULL COMMENT 'Unique filename on server',
  `original_file_name` varchar(255) NOT NULL COMMENT 'Original filename from user',
  `file_path` varchar(500) NOT NULL COMMENT 'Full path to file',
  `file_size` bigint(20) NOT NULL COMMENT 'File size in bytes',
  `mime_type` varchar(100) NOT NULL COMMENT 'MIME type of file',
  `uploaded_by` int(11) NOT NULL COMMENT 'User ID who uploaded the file',
  `uploaded_at` datetime NOT NULL DEFAULT current_timestamp(),
  `description` text DEFAULT NULL COMMENT 'Optional description of the document',
  `is_active` tinyint(1) NOT NULL DEFAULT 1 COMMENT 'Soft delete flag',
  `delivery_id` int(11) DEFAULT NULL COMMENT 'Links to specific delivery if applicable',
  `warehouse_id` int(11) DEFAULT NULL COMMENT 'Links to specific warehouse if applicable',
  `pallet_id` int(11) DEFAULT NULL COMMENT 'Links to specific pallet if applicable',
  `module_id` int(11) DEFAULT NULL COMMENT 'Links to specific module if applicable',
  `entity_context` varchar(100) DEFAULT NULL COMMENT 'Additional context about what this document relates to',
  `project_invoice_id` int(11) DEFAULT NULL,
  `manufacturer_id` int(11) DEFAULT NULL,
  `is_safe_harbor` tinyint(1) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='General project documents not tied to specific entities';

--
--


-- --------------------------------------------------------

--
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
--


-- --------------------------------------------------------

--
-- Table structure for table `project_wattage_orders`
--

CREATE TABLE `project_wattage_orders` (
  `id` int(11) NOT NULL,
  `project_id` int(11) NOT NULL,
  `wattage` int(11) NOT NULL,
  `tolerance_watts` int(11) DEFAULT 10,
  `total_order` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
--


-- --------------------------------------------------------

--
-- Table structure for table `project_wattage_orders_backup_before_fix`
--

CREATE TABLE `project_wattage_orders_backup_before_fix` (
  `id` int(11) NOT NULL,
  `project_id` int(11) NOT NULL,
  `wattage` varchar(255) NOT NULL,
  `total_order` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
--


-- --------------------------------------------------------

--
-- Table structure for table `schedule_uploads`
--

CREATE TABLE `schedule_uploads` (
  `id` int(11) NOT NULL,
  `account_id` int(11) NOT NULL,
  `project_id` int(11) DEFAULT NULL,
  `manufacturer_id` int(11) NOT NULL,
  `file_name` varchar(255) DEFAULT NULL,
  `original_file_name` varchar(255) DEFAULT NULL,
  `file_path` varchar(500) DEFAULT NULL,
  `upload_date` timestamp NOT NULL DEFAULT current_timestamp(),
  `uploaded_by` int(11) NOT NULL,
  `pallets_created` int(11) DEFAULT 0,
  `pallets_updated` int(11) DEFAULT 0,
  `deliveries_created` int(11) DEFAULT 0,
  `deliveries_updated` int(11) DEFAULT 0,
  `rows_processed` int(11) DEFAULT 0,
  `rows_skipped` int(11) DEFAULT 0,
  `warnings` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`warnings`)),
  `status` enum('pending','processing','completed','failed') DEFAULT 'completed',
  `error_message` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
--


-- --------------------------------------------------------

--
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

--
--


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
--


-- --------------------------------------------------------

--
-- Table structure for table `sunny_conversations`
--

CREATE TABLE `sunny_conversations` (
  `id` int(10) UNSIGNED NOT NULL,
  `user_id` int(11) NOT NULL,
  `title` varchar(120) NOT NULL,
  `started_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `last_message_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `is_archived` tinyint(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
--


-- --------------------------------------------------------

--
-- Table structure for table `sunny_feedback`
--

CREATE TABLE `sunny_feedback` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `user_id` int(11) NOT NULL,
  `conversation_id` int(10) UNSIGNED DEFAULT NULL,
  `frontend_message_id` varchar(64) DEFAULT NULL,
  `server_message_id` bigint(20) UNSIGNED DEFAULT NULL,
  `rating` tinyint(4) NOT NULL,
  `comment` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
--


-- --------------------------------------------------------

--
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
--


-- --------------------------------------------------------

--
-- Table structure for table `sunny_messages`
--

CREATE TABLE `sunny_messages` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `conversation_id` int(10) UNSIGNED NOT NULL,
  `role` enum('user','assistant') NOT NULL,
  `content` mediumtext NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
--


-- --------------------------------------------------------

--
-- Table structure for table `sunny_quick_actions`
--

CREATE TABLE `sunny_quick_actions` (
  `id` int(10) UNSIGNED NOT NULL,
  `user_id` int(11) NOT NULL,
  `label` varchar(20) NOT NULL,
  `message` text NOT NULL,
  `position` tinyint(3) UNSIGNED NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `sunny_usage`
--

CREATE TABLE `sunny_usage` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `user_id` int(11) NOT NULL,
  `usage_date` date NOT NULL,
  `model` varchar(50) NOT NULL DEFAULT 'gemini-2.5-flash',
  `prompt_tokens` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `completion_tokens` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `request_count` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `estimated_cost_usd` decimal(10,6) NOT NULL DEFAULT 0.000000,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
--


-- --------------------------------------------------------

--
-- Table structure for table `unassigned_module_items`
--

CREATE TABLE `unassigned_module_items` (
  `id` int(11) NOT NULL,
  `unassigned_module_id` int(11) NOT NULL COMMENT 'FK to unassigned_modules.id',
  `wattage` int(11) NOT NULL,
  `quantity` int(11) NOT NULL,
  `domestic_content_pct` decimal(5,2) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `last_updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
--


-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `username` varchar(50) NOT NULL,
  `first_name` varchar(100) DEFAULT NULL,
  `last_name` varchar(100) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `password` varchar(255) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `role` varchar(20) DEFAULT 'user'
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
--


-- --------------------------------------------------------

--
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
-- --------------------------------------------------------

--
-- --------------------------------------------------------

--
-- --------------------------------------------------------

--
-- --------------------------------------------------------

--
-- --------------------------------------------------------

--
-- Table structure for table `warehouses`
--

CREATE TABLE `warehouses` (
  `id` int(11) NOT NULL,
  `account_id` int(11) DEFAULT NULL,
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
--


--
-- Triggers `warehouses`
--
DELIMITER $$
CREATE TRIGGER `update_warehouse_address_on_insert` BEFORE INSERT ON `warehouses` FOR EACH ROW BEGIN
    IF NEW.street_address IS NOT NULL OR NEW.city IS NOT NULL OR NEW.state IS NOT NULL OR NEW.zip_code IS NOT NULL THEN
        SET NEW.address = CONCAT_WS(', ', 
            NULLIF(NEW.street_address, ''),
            NULLIF(NEW.city, ''),
            CASE 
                WHEN NEW.state IS NOT NULL AND NEW.zip_code IS NOT NULL 
                THEN CONCAT(NEW.state, ' ', NEW.zip_code)
                WHEN NEW.state IS NOT NULL 
                THEN NEW.state
                WHEN NEW.zip_code IS NOT NULL 
                THEN NEW.zip_code
                ELSE NULL
            END
        );
    END IF;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `update_warehouse_address_on_update` BEFORE UPDATE ON `warehouses` FOR EACH ROW BEGIN
    IF NEW.street_address IS NOT NULL OR NEW.city IS NOT NULL OR NEW.state IS NOT NULL OR NEW.zip_code IS NOT NULL THEN
        SET NEW.address = CONCAT_WS(', ', 
            NULLIF(NEW.street_address, ''),
            NULLIF(NEW.city, ''),
            CASE 
                WHEN NEW.state IS NOT NULL AND NEW.zip_code IS NOT NULL 
                THEN CONCAT(NEW.state, ' ', NEW.zip_code)
                WHEN NEW.state IS NOT NULL 
                THEN NEW.state
                WHEN NEW.zip_code IS NOT NULL 
                THEN NEW.zip_code
                ELSE NULL
            END
        );
    END IF;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
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
  `unit_type` enum('per_pallet','per_truck','per_sqft','flat') NOT NULL DEFAULT 'per_pallet',
  `trigger_description` varchar(255) DEFAULT NULL COMMENT 'Custom description of when this cost applies',
  `is_predefined` tinyint(1) DEFAULT 0 COMMENT 'Whether this is a system predefined cost or custom',
  `pallets_per_truck` int(11) DEFAULT 26 COMMENT 'For per_truck unit type: how many pallets per truck',
  `sqft_per_pallet` decimal(10,2) DEFAULT 13.33 COMMENT 'For per_sqft unit type: square footage per pallet',
  `display_order` int(11) DEFAULT 0 COMMENT 'Order in which to display this cost item'
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
--


-- --------------------------------------------------------

--
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
--


-- --------------------------------------------------------

--
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
--


-- --------------------------------------------------------

--
-- Table structure for table `warranty_claims`
--

CREATE TABLE `warranty_claims` (
  `id` int(11) NOT NULL,
  `scheduling_id` int(11) NOT NULL,
  `bol_number` varchar(255) DEFAULT NULL,
  `delivery_date` datetime DEFAULT NULL,
  `status` enum('Draft','Submitted','In Review','Pending Manufacturer','Approved - Credit','Approved - Replacement','Replacement Shipped','Closed','Rejected') NOT NULL DEFAULT 'Draft',
  `notes` text DEFAULT NULL,
  `pictures` text DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `pallet_id` int(11) DEFAULT NULL,
  `issue_type` enum('damaged','quantity_discrepancy','both') DEFAULT NULL,
  `expected_quantity` int(11) DEFAULT NULL,
  `actual_quantity` int(11) DEFAULT NULL,
  `damaged_quantity` int(11) DEFAULT NULL,
  `accepted_quantity` int(11) DEFAULT NULL,
  `responsible_party` enum('Manufacturer','EPC','Carrier','Other') NOT NULL DEFAULT 'Manufacturer',
  `resolution_type` enum('Credit','Replacement','No-charge','Monitoring') DEFAULT NULL,
  `estimated_delivery_date` date DEFAULT NULL,
  `credit_amount` decimal(12,2) DEFAULT NULL,
  `replacement_tracking` varchar(100) DEFAULT NULL,
  `proof_of_completion_path` varchar(255) DEFAULT NULL,
  `public_notes` longtext DEFAULT NULL,
  `internal_notes` longtext DEFAULT NULL,
  `last_public_update_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
--


-- --------------------------------------------------------

--
-- Table structure for table `warranty_claim_events`
--

CREATE TABLE `warranty_claim_events` (
  `id` int(11) NOT NULL,
  `claim_id` int(11) NOT NULL,
  `event_ts` datetime NOT NULL DEFAULT current_timestamp(),
  `user_id` int(11) NOT NULL,
  `event_text` text NOT NULL,
  `is_public` tinyint(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
--


-- --------------------------------------------------------

--
-- Table structure for table `warranty_claim_replacements`
--

CREATE TABLE `warranty_claim_replacements` (
  `id` int(11) NOT NULL,
  `claim_id` int(11) NOT NULL,
  `pallet_id` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
--


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
-- Indexes for table `anticipated_delivery_schedule`
--
ALTER TABLE `anticipated_delivery_schedule`
  ADD PRIMARY KEY (`id`),
  ADD KEY `created_by` (`created_by`),
  ADD KEY `idx_project_active` (`project_id`,`is_active`);

--
-- Indexes for table `anticipated_delivery_schedule_details`
--
ALTER TABLE `anticipated_delivery_schedule_details`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_schedule_date` (`schedule_id`,`delivery_week_ending`);

--
-- Indexes for table `archived_projects`
--
ALTER TABLE `archived_projects`
  ADD PRIMARY KEY (`id`),
  ADD KEY `closed_by` (`closed_by`),
  ADD KEY `idx_archived_account` (`account_id`),
  ADD KEY `idx_archived_closed_at` (`closed_at`),
  ADD KEY `idx_archived_project` (`project_id`),
  ADD KEY `idx_archived_primary_mfr` (`primary_manufacturer`),
  ADD KEY `idx_archived_total_cost` (`total_module_cost`);

--
-- Indexes for table `container_tracking_positions`
--
ALTER TABLE `container_tracking_positions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_container_project` (`container_number`,`project_id`),
  ADD KEY `idx_project_id` (`project_id`),
  ADD KEY `idx_container_number` (`container_number`);

--
-- Indexes for table `container_tracking_waypoints`
--
ALTER TABLE `container_tracking_waypoints`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_container_project_time` (`container_number`,`project_id`,`recorded_at`),
  ADD KEY `idx_project_id` (`project_id`);

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
  ADD KEY `fk_deliveries_origin_port` (`origin_port_id`),
  ADD KEY `idx_deliveries_status_project` (`status_of_delivery`,`project_id`),
  ADD KEY `idx_deliveries_customs_hold_started` (`customs_hold_started_date`);

--
-- Indexes for table `delivery_milestone_instances`
--
ALTER TABLE `delivery_milestone_instances`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_milestone_instance` (`delivery_id`,`milestone_id`,`module_batch_id`),
  ADD KEY `idx_delivery_id` (`delivery_id`),
  ADD KEY `idx_milestone_id` (`milestone_id`),
  ADD KEY `idx_triggered_at` (`triggered_at`),
  ADD KEY `idx_module_batch_id` (`module_batch_id`);

--
-- Indexes for table `delivery_pallets`
--
ALTER TABLE `delivery_pallets`
  ADD PRIMARY KEY (`delivery_id`,`inventory_pallet_id`),
  ADD KEY `fk_dp_pallet` (`inventory_pallet_id`);

--
-- Indexes for table `delivery_projections`
--
ALTER TABLE `delivery_projections`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_projection_project` (`project_id`),
  ADD KEY `idx_projection_primary` (`project_id`,`is_primary`),
  ADD KEY `idx_projection_template` (`is_template`),
  ADD KEY `idx_projection_created_by` (`created_by`),
  ADD KEY `idx_projection_general` (`is_general`),
  ADD KEY `idx_projection_linked` (`linked_project_id`);

--
-- Indexes for table `delivery_status_events`
--
ALTER TABLE `delivery_status_events`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_delivery_status_events_delivery` (`delivery_id`),
  ADD KEY `idx_delivery_status_events_event` (`event_type`),
  ADD KEY `idx_delivery_status_events_changed_at` (`changed_at`);

--
-- Indexes for table `demo_requests`
--
ALTER TABLE `demo_requests`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_submitted_at` (`submitted_at`);

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
  ADD UNIQUE KEY `uniq_pallet_mfr_project_scope` (`assigned_project_id`,`manufacturer_pallet_id`),
  ADD KEY `unassigned_module_item_id` (`unassigned_module_item_id`),
  ADD KEY `current_warehouse_id` (`current_warehouse_id`),
  ADD KEY `current_project_id` (`current_project_id`),
  ADD KEY `idx_manufacturer_location` (`manufacturer_location_id`),
  ADD KEY `idx_inventory_pallets_status` (`status`),
  ADD KEY `idx_manufacturer_pallet_project` (`manufacturer_pallet_id`,`assigned_project_id`);

--
-- Indexes for table `login_attempts`
--
ALTER TABLE `login_attempts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_username` (`username`),
  ADD KEY `idx_attempt_time` (`attempt_time`),
  ADD KEY `idx_success` (`success`);

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
-- Indexes for table `manufacturer_column_mappings`
--
ALTER TABLE `manufacturer_column_mappings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_manufacturer_account` (`manufacturer_id`,`account_id`),
  ADD KEY `account_id` (`account_id`),
  ADD KEY `created_by` (`created_by`);

--
-- Indexes for table `manufacturer_locations`
--
ALTER TABLE `manufacturer_locations`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_manufacturer_id` (`manufacturer_id`),
  ADD KEY `idx_is_primary` (`is_primary`);

--
-- Indexes for table `manufacturer_location_requests`
--
ALTER TABLE `manufacturer_location_requests`
  ADD PRIMARY KEY (`id`),
  ADD KEY `requested_by` (`requested_by`),
  ADD KEY `reviewed_by` (`reviewed_by`),
  ADD KEY `approved_location_id` (`approved_location_id`),
  ADD KEY `idx_manufacturer_location_requests_status` (`status`),
  ADD KEY `idx_manufacturer_location_requests_account` (`account_id`),
  ADD KEY `idx_manufacturer_location_requests_mfg` (`manufacturer_id`);

--
-- Indexes for table `manufacturer_requests`
--
ALTER TABLE `manufacturer_requests`
  ADD PRIMARY KEY (`id`),
  ADD KEY `requested_by` (`requested_by`),
  ADD KEY `reviewed_by` (`reviewed_by`),
  ADD KEY `approved_manufacturer_id` (`approved_manufacturer_id`),
  ADD KEY `idx_manufacturer_requests_status` (`status`),
  ADD KEY `idx_manufacturer_requests_account` (`account_id`);

--
-- Indexes for table `modules`
--
ALTER TABLE `modules`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_unassigned_modules_project` (`project_id`);

--
-- Indexes for table `module_batch_milestones`
--
ALTER TABLE `module_batch_milestones`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_module_id` (`module_id`),
  ADD KEY `idx_trigger_event` (`trigger_event`);

--
-- Indexes for table `module_batch_reconciliation_audit`
--
ALTER TABLE `module_batch_reconciliation_audit`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_mbra_batch_created` (`module_batch_id`,`created_at`),
  ADD KEY `idx_mbra_project_created` (`project_id`,`created_at`),
  ADD KEY `idx_mbra_actor_created` (`actor_user_id`,`created_at`),
  ADD KEY `idx_mbra_action_created` (`action_type`,`created_at`);

--
-- Indexes for table `notifications`
--
ALTER TABLE `notifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user_read` (`user_id`,`read_at`),
  ADD KEY `idx_type` (`type`),
  ADD KEY `idx_created` (`created_at`);

--
-- Indexes for table `notification_settings`
--
ALTER TABLE `notification_settings`
  ADD PRIMARY KEY (`user_id`);

--
-- Indexes for table `overheads`
--
ALTER TABLE `overheads`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `password_reset_tokens`
--
ALTER TABLE `password_reset_tokens`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `token` (`token`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `idx_token` (`token`),
  ADD KEY `idx_expires` (`expires_at`);

--
-- Indexes for table `pending_requests`
--
ALTER TABLE `pending_requests`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `account_id` (`account_id`);

--
-- Indexes for table `planning_activation_requests`
--
ALTER TABLE `planning_activation_requests`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_activation_requests_account` (`account_id`),
  ADD KEY `idx_activation_requests_user` (`user_id`),
  ADD KEY `idx_activation_requests_status` (`status`),
  ADD KEY `idx_activation_requests_type` (`request_type`),
  ADD KEY `idx_activation_requests_project` (`planning_project_id`),
  ADD KEY `idx_activation_requests_scenario` (`scenario_id`),
  ADD KEY `fk_activation_requests_reviewed_by` (`reviewed_by`);

--
-- Indexes for table `planning_allocations`
--
ALTER TABLE `planning_allocations`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_allocations_scenario` (`scenario_id`),
  ADD KEY `idx_allocations_planning_project` (`planning_project_id`),
  ADD KEY `idx_allocations_active_project` (`active_project_id`),
  ADD KEY `idx_allocations_period` (`period_start`,`period_end`),
  ADD KEY `idx_allocations_contract` (`contract_id`),
  ADD KEY `idx_allocations_inventory` (`inventory_pool_id`),
  ADD KEY `idx_allocations_locked` (`is_locked`),
  ADD KEY `fk_allocations_locked_by` (`locked_by`);

--
-- Indexes for table `planning_contract_period_rules`
--
ALTER TABLE `planning_contract_period_rules`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_period_rules_contract` (`contract_id`),
  ADD KEY `idx_period_rules_dates` (`period_start`,`period_end`);

--
-- Indexes for table `planning_contract_push_rules`
--
ALTER TABLE `planning_contract_push_rules`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_push_rules_contract` (`contract_id`);

--
-- Indexes for table `planning_framework_contracts`
--
ALTER TABLE `planning_framework_contracts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_planning_contracts_account` (`account_id`),
  ADD KEY `idx_planning_contracts_scenario` (`scenario_id`),
  ADD KEY `idx_planning_contracts_manufacturer` (`manufacturer_id`),
  ADD KEY `idx_planning_contracts_planning_manufacturer` (`planning_manufacturer_id`),
  ADD KEY `idx_planning_contracts_dates` (`contract_start_date`,`contract_end_date`),
  ADD KEY `idx_planning_contracts_equipment` (`equipment_type`),
  ADD KEY `fk_planning_contracts_user` (`created_by`);

--
-- Indexes for table `planning_inventory_pools`
--
ALTER TABLE `planning_inventory_pools`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_inventory_pools_account` (`account_id`),
  ADD KEY `idx_inventory_pools_scenario` (`scenario_id`),
  ADD KEY `idx_inventory_pools_warehouse` (`warehouse_id`),
  ADD KEY `fk_inventory_pools_manufacturer` (`manufacturer_id`),
  ADD KEY `fk_inventory_pools_planning_mfr` (`planning_manufacturer_id`),
  ADD KEY `fk_inventory_pools_user` (`created_by`);

--
-- Indexes for table `planning_manufacturers`
--
ALTER TABLE `planning_manufacturers`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_planning_manufacturers_account` (`account_id`),
  ADD KEY `idx_planning_manufacturers_scenario` (`scenario_id`),
  ADD KEY `idx_planning_manufacturers_approved` (`is_approved`),
  ADD KEY `fk_planning_manufacturers_user` (`created_by`),
  ADD KEY `fk_planning_manufacturers_real` (`approved_manufacturer_id`);

--
-- Indexes for table `planning_projects`
--
ALTER TABLE `planning_projects`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_planning_projects_account` (`account_id`),
  ADD KEY `idx_planning_projects_scenario` (`scenario_id`),
  ADD KEY `idx_planning_projects_status` (`status`),
  ADD KEY `idx_planning_projects_delivery` (`primary_delivery_start`,`primary_delivery_end`),
  ADD KEY `idx_planning_projects_activated` (`activated_project_id`),
  ADD KEY `fk_planning_projects_activated_by` (`activated_by`),
  ADD KEY `fk_planning_projects_user` (`created_by`);

--
-- Indexes for table `planning_risk_alerts`
--
ALTER TABLE `planning_risk_alerts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_risk_alerts_scenario` (`scenario_id`),
  ADD KEY `idx_risk_alerts_type` (`alert_type`),
  ADD KEY `idx_risk_alerts_severity` (`severity`),
  ADD KEY `idx_risk_alerts_resolved` (`is_resolved`),
  ADD KEY `fk_risk_alerts_contract` (`contract_id`),
  ADD KEY `fk_risk_alerts_planning_project` (`planning_project_id`),
  ADD KEY `fk_risk_alerts_active_project` (`active_project_id`),
  ADD KEY `fk_risk_alerts_resolved_by` (`resolved_by`);

--
-- Indexes for table `planning_scenarios`
--
ALTER TABLE `planning_scenarios`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_planning_scenarios_account` (`account_id`),
  ADD KEY `idx_planning_scenarios_active` (`account_id`,`is_active`),
  ADD KEY `idx_planning_scenarios_created_by` (`created_by`);

--
-- Indexes for table `planning_sunny_notes`
--
ALTER TABLE `planning_sunny_notes`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_sunny_notes_scenario` (`scenario_id`),
  ADD KEY `idx_sunny_notes_type` (`note_type`),
  ADD KEY `idx_sunny_notes_created` (`created_at`),
  ADD KEY `fk_sunny_notes_contract` (`linked_contract_id`),
  ADD KEY `fk_sunny_notes_planning_project` (`linked_planning_project_id`),
  ADD KEY `fk_sunny_notes_active_project` (`linked_active_project_id`),
  ADD KEY `fk_sunny_notes_allocation` (`linked_allocation_id`),
  ADD KEY `fk_sunny_notes_user` (`created_by`);

--
-- Indexes for table `projection_cost_summary`
--
ALTER TABLE `projection_cost_summary`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_cost_summary_projection` (`projection_id`);

--
-- Indexes for table `projection_legs`
--
ALTER TABLE `projection_legs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_leg_projection` (`projection_id`),
  ADD KEY `idx_leg_order` (`projection_id`,`leg_order`),
  ADD KEY `idx_leg_from_stop` (`from_stop_id`),
  ADD KEY `idx_leg_to_stop` (`to_stop_id`);

--
-- Indexes for table `projection_modules`
--
ALTER TABLE `projection_modules`
  ADD PRIMARY KEY (`id`),
  ADD KEY `projection_id` (`projection_id`);

--
-- Indexes for table `projection_module_allocations`
--
ALTER TABLE `projection_module_allocations`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_allocation_projection` (`projection_id`),
  ADD KEY `idx_allocation_module` (`module_id`);

--
-- Indexes for table `projection_module_items`
--
ALTER TABLE `projection_module_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `projection_module_id` (`projection_module_id`);

--
-- Indexes for table `projection_module_milestones`
--
ALTER TABLE `projection_module_milestones`
  ADD PRIMARY KEY (`id`),
  ADD KEY `projection_module_id` (`projection_module_id`);

--
-- Indexes for table `projection_stops`
--
ALTER TABLE `projection_stops`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_stop_projection` (`projection_id`),
  ADD KEY `idx_stop_order` (`projection_id`,`stop_order`),
  ADD KEY `idx_stop_warehouse` (`warehouse_id`);

--
-- Indexes for table `projection_stop_fees`
--
ALTER TABLE `projection_stop_fees`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_fee_stop` (`stop_id`),
  ADD KEY `idx_fee_type` (`fee_type`);

--
-- Indexes for table `projects`
--
ALTER TABLE `projects`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_admin` (`admin_id`),
  ADD KEY `idx_projects_status` (`status`),
  ADD KEY `fk_manual_health_set_by` (`manual_health_set_by`);

--
-- Indexes for table `project_documents`
--
ALTER TABLE `project_documents`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_project_id` (`project_id`),
  ADD KEY `idx_document_type` (`document_type`),
  ADD KEY `idx_uploaded_by` (`uploaded_by`),
  ADD KEY `idx_uploaded_at` (`uploaded_at`),
  ADD KEY `idx_is_active` (`is_active`),
  ADD KEY `fk_project_documents_delivery` (`delivery_id`),
  ADD KEY `fk_project_documents_warehouse` (`warehouse_id`),
  ADD KEY `fk_project_documents_invoice` (`project_invoice_id`),
  ADD KEY `fk_project_documents_manufacturer` (`manufacturer_id`),
  ADD KEY `idx_project_documents_safe_harbor` (`is_safe_harbor`),
  ADD KEY `idx_project_documents_type_subtype` (`document_type`,`document_sub_type`);

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
  ADD UNIQUE KEY `uq_project_wattage` (`project_id`,`wattage`);

--
-- Indexes for table `project_wattage_orders_backup_before_fix`
--
ALTER TABLE `project_wattage_orders_backup_before_fix`
  ADD PRIMARY KEY (`id`),
  ADD KEY `project_id` (`project_id`);

--
-- Indexes for table `schedule_uploads`
--
ALTER TABLE `schedule_uploads`
  ADD PRIMARY KEY (`id`),
  ADD KEY `uploaded_by` (`uploaded_by`),
  ADD KEY `idx_account_date` (`account_id`,`upload_date`),
  ADD KEY `idx_project_date` (`project_id`,`upload_date`),
  ADD KEY `idx_manufacturer` (`manufacturer_id`);

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
-- Indexes for table `sunny_conversations`
--
ALTER TABLE `sunny_conversations`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user_last` (`user_id`,`last_message_at`);

--
-- Indexes for table `sunny_feedback`
--
ALTER TABLE `sunny_feedback`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user_time` (`user_id`,`created_at`),
  ADD KEY `fk_feedback_convo` (`conversation_id`);

--
-- Indexes for table `sunny_memory`
--
ALTER TABLE `sunny_memory`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user_account` (`user_id`,`account_id`),
  ADD KEY `idx_category_entity` (`category`,`entity_id`);

--
-- Indexes for table `sunny_messages`
--
ALTER TABLE `sunny_messages`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_convo_time` (`conversation_id`,`created_at`);

--
-- Indexes for table `sunny_quick_actions`
--
ALTER TABLE `sunny_quick_actions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user_position` (`user_id`,`position`);

--
-- Indexes for table `sunny_usage`
--
ALTER TABLE `sunny_usage`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_user_date_model` (`user_id`,`usage_date`,`model`),
  ADD KEY `idx_user_date` (`user_id`,`usage_date`);

--
-- Indexes for table `unassigned_module_items`
--
ALTER TABLE `unassigned_module_items`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_unassigned_module_wattage` (`unassigned_module_id`,`wattage`);

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
  ADD KEY `idx_warehouses_port` (`is_port`),
  ADD KEY `idx_warehouses_account_id` (`account_id`);

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
  ADD KEY `fk_warranty_scheduling` (`scheduling_id`),
  ADD KEY `idx_warranty_claims_estimated_delivery` (`estimated_delivery_date`);

--
-- Indexes for table `warranty_claim_events`
--
ALTER TABLE `warranty_claim_events`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_claim_events_claim` (`claim_id`),
  ADD KEY `idx_claim_events_timestamp` (`event_ts`),
  ADD KEY `idx_claim_events_public` (`is_public`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `warranty_claim_replacements`
--
ALTER TABLE `warranty_claim_replacements`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_claim_replacements_claim` (`claim_id`),
  ADD KEY `idx_claim_replacements_pallet` (`pallet_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `accounts`
--
ALTER TABLE `accounts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `accounts_payable`
--
ALTER TABLE `accounts_payable`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `account_users`
--
ALTER TABLE `account_users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `anticipated_delivery_schedule`
--
ALTER TABLE `anticipated_delivery_schedule`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `anticipated_delivery_schedule_details`
--
ALTER TABLE `anticipated_delivery_schedule_details`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `archived_projects`
--
ALTER TABLE `archived_projects`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `container_tracking_positions`
--
ALTER TABLE `container_tracking_positions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `container_tracking_waypoints`
--
ALTER TABLE `container_tracking_waypoints`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `customer_accounts`
--
ALTER TABLE `customer_accounts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `customer_account_users`
--
ALTER TABLE `customer_account_users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `deliveries`
--
ALTER TABLE `deliveries`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `delivery_milestone_instances`
--
ALTER TABLE `delivery_milestone_instances`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `delivery_projections`
--
ALTER TABLE `delivery_projections`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `delivery_status_events`
--
ALTER TABLE `delivery_status_events`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `demo_requests`
--
ALTER TABLE `demo_requests`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `flash_test_data`
--
ALTER TABLE `flash_test_data`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `forecast_items`
--
ALTER TABLE `forecast_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `forecast_projects`
--
ALTER TABLE `forecast_projects`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `freight_estimates`
--
ALTER TABLE `freight_estimates`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `inventory_pallets`
--
ALTER TABLE `inventory_pallets`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT COMMENT 'Unique ID for each pallet';

--
-- AUTO_INCREMENT for table `login_attempts`
--
ALTER TABLE `login_attempts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `manufacturers`
--
ALTER TABLE `manufacturers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `manufacturer_column_mappings`
--
ALTER TABLE `manufacturer_column_mappings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `manufacturer_locations`
--
ALTER TABLE `manufacturer_locations`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `manufacturer_location_requests`
--
ALTER TABLE `manufacturer_location_requests`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `manufacturer_requests`
--
ALTER TABLE `manufacturer_requests`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `modules`
--
ALTER TABLE `modules`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `module_batch_milestones`
--
ALTER TABLE `module_batch_milestones`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `module_batch_reconciliation_audit`
--
ALTER TABLE `module_batch_reconciliation_audit`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `notifications`
--
ALTER TABLE `notifications`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `overheads`
--
ALTER TABLE `overheads`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `password_reset_tokens`
--
ALTER TABLE `password_reset_tokens`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `pending_requests`
--
ALTER TABLE `pending_requests`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `planning_activation_requests`
--
ALTER TABLE `planning_activation_requests`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `planning_allocations`
--
ALTER TABLE `planning_allocations`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `planning_contract_period_rules`
--
ALTER TABLE `planning_contract_period_rules`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `planning_contract_push_rules`
--
ALTER TABLE `planning_contract_push_rules`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `planning_framework_contracts`
--
ALTER TABLE `planning_framework_contracts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `planning_inventory_pools`
--
ALTER TABLE `planning_inventory_pools`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `planning_manufacturers`
--
ALTER TABLE `planning_manufacturers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `planning_projects`
--
ALTER TABLE `planning_projects`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `planning_risk_alerts`
--
ALTER TABLE `planning_risk_alerts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `planning_scenarios`
--
ALTER TABLE `planning_scenarios`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `planning_sunny_notes`
--
ALTER TABLE `planning_sunny_notes`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `projection_cost_summary`
--
ALTER TABLE `projection_cost_summary`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `projection_legs`
--
ALTER TABLE `projection_legs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `projection_modules`
--
ALTER TABLE `projection_modules`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `projection_module_allocations`
--
ALTER TABLE `projection_module_allocations`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `projection_module_items`
--
ALTER TABLE `projection_module_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `projection_module_milestones`
--
ALTER TABLE `projection_module_milestones`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `projection_stops`
--
ALTER TABLE `projection_stops`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `projection_stop_fees`
--
ALTER TABLE `projection_stop_fees`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `projects`
--
ALTER TABLE `projects`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `project_documents`
--
ALTER TABLE `project_documents`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `project_invoices`
--
ALTER TABLE `project_invoices`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `project_wattage_orders`
--
ALTER TABLE `project_wattage_orders`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `project_wattage_orders_backup_before_fix`
--
ALTER TABLE `project_wattage_orders_backup_before_fix`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `schedule_uploads`
--
ALTER TABLE `schedule_uploads`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `site_operating_hours`
--
ALTER TABLE `site_operating_hours`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `site_safety`
--
ALTER TABLE `site_safety`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `site_scheduling`
--
ALTER TABLE `site_scheduling`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `site_users`
--
ALTER TABLE `site_users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sunny_conversations`
--
ALTER TABLE `sunny_conversations`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sunny_feedback`
--
ALTER TABLE `sunny_feedback`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sunny_memory`
--
ALTER TABLE `sunny_memory`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sunny_messages`
--
ALTER TABLE `sunny_messages`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sunny_quick_actions`
--
ALTER TABLE `sunny_quick_actions`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sunny_usage`
--
ALTER TABLE `sunny_usage`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `unassigned_module_items`
--
ALTER TABLE `unassigned_module_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `vendors`
--
ALTER TABLE `vendors`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `warehouses`
--
ALTER TABLE `warehouses`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `warehouse_cost_items`
--
ALTER TABLE `warehouse_cost_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `warehouse_estimates`
--
ALTER TABLE `warehouse_estimates`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `warehouse_quotes`
--
ALTER TABLE `warehouse_quotes`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `warranty_claims`
--
ALTER TABLE `warranty_claims`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `warranty_claim_events`
--
ALTER TABLE `warranty_claim_events`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `warranty_claim_replacements`
--
ALTER TABLE `warranty_claim_replacements`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

-- --------------------------------------------------------

--
-- Structure for view `v_contract_utilization`
--
DROP TABLE IF EXISTS `v_contract_utilization`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `v_contract_utilization`  AS SELECT `pfc`.`id` AS `contract_id`, `pfc`.`account_id` AS `account_id`, `pfc`.`scenario_id` AS `scenario_id`, `pfc`.`name` AS `contract_name`, `pfc`.`total_mw_dc` AS `total_mw_dc`, coalesce(sum(`pa`.`allocated_mw_dc`),0) AS `allocated_mw`, `pfc`.`total_mw_dc`- coalesce(sum(`pa`.`allocated_mw_dc`),0) AS `remaining_mw`, round(coalesce(sum(`pa`.`allocated_mw_dc`),0) / `pfc`.`total_mw_dc` * 100,2) AS `utilization_pct`, `pfc`.`contract_start_date` AS `contract_start_date`, `pfc`.`contract_end_date` AS `contract_end_date`, `pfc`.`priority_score` AS `priority_score` FROM (`planning_framework_contracts` `pfc` left join `planning_allocations` `pa` on(`pa`.`contract_id` = `pfc`.`id`)) GROUP BY `pfc`.`id` ;

-- --------------------------------------------------------

--
-- Structure for view `v_planning_projects_summary`
--
DROP TABLE IF EXISTS `v_planning_projects_summary`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `v_planning_projects_summary`  AS SELECT `pp`.`id` AS `id`, `pp`.`account_id` AS `account_id`, `pp`.`scenario_id` AS `scenario_id`, `pp`.`name` AS `name`, `pp`.`location_region` AS `location_region`, `pp`.`required_mw_dc` AS `required_mw_dc`, `pp`.`primary_delivery_start` AS `primary_delivery_start`, `pp`.`primary_delivery_end` AS `primary_delivery_end`, `pp`.`status` AS `status`, coalesce(sum(`pa`.`allocated_mw_dc`),0) AS `total_allocated_mw`, `pp`.`required_mw_dc`- coalesce(sum(`pa`.`allocated_mw_dc`),0) AS `remaining_mw`, CASE WHEN coalesce(sum(`pa`.`allocated_mw_dc`),0) >= `pp`.`required_mw_dc` THEN 'fully_allocated' WHEN coalesce(sum(`pa`.`allocated_mw_dc`),0) > 0 THEN 'partial' ELSE 'unallocated' END AS `allocation_status`, `pp`.`created_at` AS `created_at`, `pp`.`updated_at` AS `updated_at` FROM (`planning_projects` `pp` left join `planning_allocations` `pa` on(`pa`.`planning_project_id` = `pp`.`id`)) GROUP BY `pp`.`id` ;

-- --------------------------------------------------------

--
-- Structure for view `v_portfolio_summary`
--
DROP TABLE IF EXISTS `v_portfolio_summary`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `v_portfolio_summary`  AS SELECT `ps`.`id` AS `scenario_id`, `ps`.`account_id` AS `account_id`, `ps`.`name` AS `scenario_name`, `ps`.`is_active` AS `is_active`, coalesce(sum(distinct `pp`.`required_mw_dc`),0) AS `total_planned_mw`, coalesce(sum(distinct `pfc`.`total_mw_dc`),0) AS `total_contracted_mw`, coalesce(`alloc`.`total_allocated`,0) AS `total_allocated_mw`, coalesce(sum(distinct `pfc`.`total_mw_dc`),0) - coalesce(sum(distinct `pp`.`required_mw_dc`),0) AS `surplus_deficit_mw`, (select count(0) from `planning_risk_alerts` `pra` where `pra`.`scenario_id` = `ps`.`id` and `pra`.`is_resolved` = 0) AS `open_alerts`, `ps`.`last_optimized_at` AS `last_optimized_at`, `ps`.`created_at` AS `created_at`, `ps`.`updated_at` AS `updated_at` FROM (((`planning_scenarios` `ps` left join `planning_projects` `pp` on(`pp`.`scenario_id` = `ps`.`id` or `pp`.`scenario_id` is null and `pp`.`account_id` = `ps`.`account_id`)) left join `planning_framework_contracts` `pfc` on(`pfc`.`scenario_id` = `ps`.`id` or `pfc`.`scenario_id` is null and `pfc`.`account_id` = `ps`.`account_id`)) left join (select `planning_allocations`.`scenario_id` AS `scenario_id`,sum(`planning_allocations`.`allocated_mw_dc`) AS `total_allocated` from `planning_allocations` group by `planning_allocations`.`scenario_id`) `alloc` on(`alloc`.`scenario_id` = `ps`.`id`)) GROUP BY `ps`.`id` ;

-- --------------------------------------------------------

--
-- Structure for view `v_projection_summary`
--
DROP TABLE IF EXISTS `v_projection_summary`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `v_projection_summary`  AS SELECT `dp`.`id` AS `projection_id`, `dp`.`project_id` AS `project_id`, `dp`.`projection_name` AS `projection_name`, `dp`.`is_primary` AS `is_primary`, `dp`.`is_template` AS `is_template`, `dp`.`status` AS `status`, `p`.`project_name` AS `project_name`, `p`.`project_address` AS `project_address`, (select count(0) from `projection_stops` `ps` where `ps`.`projection_id` = `dp`.`id`) AS `stop_count`, (select count(0) from `projection_legs` `pl` where `pl`.`projection_id` = `dp`.`id`) AS `leg_count`, (select sum(`pma`.`quantity`) from `projection_module_allocations` `pma` where `pma`.`projection_id` = `dp`.`id`) AS `total_modules`, coalesce(`pcs`.`grand_total`,0) AS `grand_total`, coalesce(`pcs`.`total_freight_cost`,0) AS `total_freight_cost`, coalesce(`pcs`.`total_warehousing_cost`,0) AS `total_warehousing_cost`, `dp`.`created_by` AS `created_by`, `dp`.`created_at` AS `created_at`, `dp`.`updated_at` AS `updated_at` FROM ((`delivery_projections` `dp` left join `projects` `p` on(`p`.`id` = `dp`.`project_id`)) left join `projection_cost_summary` `pcs` on(`pcs`.`projection_id` = `dp`.`id`)) ;

-- --------------------------------------------------------

--
-- Structure for view `v_project_projection_status`
--
DROP TABLE IF EXISTS `v_project_projection_status`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `v_project_projection_status`  AS SELECT `p`.`id` AS `project_id`, `p`.`project_name` AS `project_name`, `p`.`project_address` AS `project_address`, (select count(0) from `delivery_projections` `dp` where `dp`.`project_id` = `p`.`id`) AS `projection_count`, (select `dp`.`id` from `delivery_projections` `dp` where `dp`.`project_id` = `p`.`id` and `dp`.`is_primary` = 1 limit 1) AS `primary_projection_id`, (select `dp`.`status` from `delivery_projections` `dp` where `dp`.`project_id` = `p`.`id` and `dp`.`is_primary` = 1 limit 1) AS `primary_projection_status`, CASE WHEN exists(select 1 from `delivery_projections` `dp` where `dp`.`project_id` = `p`.`id` limit 1) THEN 'has_projection' ELSE 'no_projection' END AS `projection_availability` FROM `projects` AS `p` WHERE `p`.`status` <> 'archived' ;

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
-- Constraints for table `anticipated_delivery_schedule`
--
ALTER TABLE `anticipated_delivery_schedule`
  ADD CONSTRAINT `anticipated_delivery_schedule_ibfk_1` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `anticipated_delivery_schedule_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `anticipated_delivery_schedule_details`
--
ALTER TABLE `anticipated_delivery_schedule_details`
  ADD CONSTRAINT `anticipated_delivery_schedule_details_ibfk_1` FOREIGN KEY (`schedule_id`) REFERENCES `anticipated_delivery_schedule` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `archived_projects`
--
ALTER TABLE `archived_projects`
  ADD CONSTRAINT `archived_projects_ibfk_1` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `archived_projects_ibfk_2` FOREIGN KEY (`account_id`) REFERENCES `customer_accounts` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `archived_projects_ibfk_3` FOREIGN KEY (`closed_by`) REFERENCES `users` (`id`) ON DELETE CASCADE;

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
-- Constraints for table `delivery_milestone_instances`
--
ALTER TABLE `delivery_milestone_instances`
  ADD CONSTRAINT `fk_instance_delivery` FOREIGN KEY (`delivery_id`) REFERENCES `deliveries` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_instance_milestone` FOREIGN KEY (`milestone_id`) REFERENCES `module_batch_milestones` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_instance_module_batch` FOREIGN KEY (`module_batch_id`) REFERENCES `modules` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `delivery_pallets`
--
ALTER TABLE `delivery_pallets`
  ADD CONSTRAINT `fk_dp_delivery` FOREIGN KEY (`delivery_id`) REFERENCES `deliveries` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_dp_pallet` FOREIGN KEY (`inventory_pallet_id`) REFERENCES `inventory_pallets` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `delivery_projections`
--
ALTER TABLE `delivery_projections`
  ADD CONSTRAINT `fk_projection_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_projection_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE;

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
-- Constraints for table `manufacturer_column_mappings`
--
ALTER TABLE `manufacturer_column_mappings`
  ADD CONSTRAINT `manufacturer_column_mappings_ibfk_1` FOREIGN KEY (`manufacturer_id`) REFERENCES `manufacturers` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `manufacturer_column_mappings_ibfk_2` FOREIGN KEY (`account_id`) REFERENCES `customer_accounts` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `manufacturer_column_mappings_ibfk_3` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `manufacturer_locations`
--
ALTER TABLE `manufacturer_locations`
  ADD CONSTRAINT `manufacturer_locations_ibfk_1` FOREIGN KEY (`manufacturer_id`) REFERENCES `manufacturers` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `manufacturer_location_requests`
--
ALTER TABLE `manufacturer_location_requests`
  ADD CONSTRAINT `manufacturer_location_requests_ibfk_1` FOREIGN KEY (`manufacturer_id`) REFERENCES `manufacturers` (`id`),
  ADD CONSTRAINT `manufacturer_location_requests_ibfk_2` FOREIGN KEY (`account_id`) REFERENCES `customer_accounts` (`id`),
  ADD CONSTRAINT `manufacturer_location_requests_ibfk_3` FOREIGN KEY (`requested_by`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `manufacturer_location_requests_ibfk_4` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `manufacturer_location_requests_ibfk_5` FOREIGN KEY (`approved_location_id`) REFERENCES `manufacturer_locations` (`id`);

--
-- Constraints for table `manufacturer_requests`
--
ALTER TABLE `manufacturer_requests`
  ADD CONSTRAINT `manufacturer_requests_ibfk_1` FOREIGN KEY (`account_id`) REFERENCES `customer_accounts` (`id`),
  ADD CONSTRAINT `manufacturer_requests_ibfk_2` FOREIGN KEY (`requested_by`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `manufacturer_requests_ibfk_3` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `manufacturer_requests_ibfk_4` FOREIGN KEY (`approved_manufacturer_id`) REFERENCES `manufacturers` (`id`);

--
-- Constraints for table `modules`
--
ALTER TABLE `modules`
  ADD CONSTRAINT `fk_unassigned_modules_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Constraints for table `module_batch_milestones`
--
ALTER TABLE `module_batch_milestones`
  ADD CONSTRAINT `fk_milestones_module` FOREIGN KEY (`module_id`) REFERENCES `modules` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `notifications`
--
ALTER TABLE `notifications`
  ADD CONSTRAINT `notifications_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `notification_settings`
--
ALTER TABLE `notification_settings`
  ADD CONSTRAINT `notification_settings_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `password_reset_tokens`
--
ALTER TABLE `password_reset_tokens`
  ADD CONSTRAINT `password_reset_tokens_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `planning_activation_requests`
--
ALTER TABLE `planning_activation_requests`
  ADD CONSTRAINT `fk_activation_requests_account` FOREIGN KEY (`account_id`) REFERENCES `customer_accounts` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_activation_requests_project` FOREIGN KEY (`planning_project_id`) REFERENCES `planning_projects` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_activation_requests_reviewed_by` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_activation_requests_scenario` FOREIGN KEY (`scenario_id`) REFERENCES `planning_scenarios` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_activation_requests_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `planning_allocations`
--
ALTER TABLE `planning_allocations`
  ADD CONSTRAINT `fk_allocations_active_project` FOREIGN KEY (`active_project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_allocations_contract` FOREIGN KEY (`contract_id`) REFERENCES `planning_framework_contracts` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_allocations_inventory` FOREIGN KEY (`inventory_pool_id`) REFERENCES `planning_inventory_pools` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_allocations_locked_by` FOREIGN KEY (`locked_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_allocations_planning_project` FOREIGN KEY (`planning_project_id`) REFERENCES `planning_projects` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_allocations_scenario` FOREIGN KEY (`scenario_id`) REFERENCES `planning_scenarios` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `planning_contract_period_rules`
--
ALTER TABLE `planning_contract_period_rules`
  ADD CONSTRAINT `fk_period_rules_contract` FOREIGN KEY (`contract_id`) REFERENCES `planning_framework_contracts` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `planning_contract_push_rules`
--
ALTER TABLE `planning_contract_push_rules`
  ADD CONSTRAINT `fk_push_rules_contract` FOREIGN KEY (`contract_id`) REFERENCES `planning_framework_contracts` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `planning_framework_contracts`
--
ALTER TABLE `planning_framework_contracts`
  ADD CONSTRAINT `fk_planning_contracts_account` FOREIGN KEY (`account_id`) REFERENCES `customer_accounts` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_planning_contracts_manufacturer` FOREIGN KEY (`manufacturer_id`) REFERENCES `manufacturers` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_planning_contracts_planning_mfr` FOREIGN KEY (`planning_manufacturer_id`) REFERENCES `planning_manufacturers` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_planning_contracts_scenario` FOREIGN KEY (`scenario_id`) REFERENCES `planning_scenarios` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_planning_contracts_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `planning_inventory_pools`
--
ALTER TABLE `planning_inventory_pools`
  ADD CONSTRAINT `fk_inventory_pools_account` FOREIGN KEY (`account_id`) REFERENCES `customer_accounts` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_inventory_pools_manufacturer` FOREIGN KEY (`manufacturer_id`) REFERENCES `manufacturers` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_inventory_pools_planning_mfr` FOREIGN KEY (`planning_manufacturer_id`) REFERENCES `planning_manufacturers` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_inventory_pools_scenario` FOREIGN KEY (`scenario_id`) REFERENCES `planning_scenarios` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_inventory_pools_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_inventory_pools_warehouse` FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `planning_manufacturers`
--
ALTER TABLE `planning_manufacturers`
  ADD CONSTRAINT `fk_planning_manufacturers_account` FOREIGN KEY (`account_id`) REFERENCES `customer_accounts` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_planning_manufacturers_real` FOREIGN KEY (`approved_manufacturer_id`) REFERENCES `manufacturers` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_planning_manufacturers_scenario` FOREIGN KEY (`scenario_id`) REFERENCES `planning_scenarios` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_planning_manufacturers_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `planning_projects`
--
ALTER TABLE `planning_projects`
  ADD CONSTRAINT `fk_planning_projects_account` FOREIGN KEY (`account_id`) REFERENCES `customer_accounts` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_planning_projects_activated` FOREIGN KEY (`activated_project_id`) REFERENCES `projects` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_planning_projects_activated_by` FOREIGN KEY (`activated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_planning_projects_scenario` FOREIGN KEY (`scenario_id`) REFERENCES `planning_scenarios` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_planning_projects_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `planning_risk_alerts`
--
ALTER TABLE `planning_risk_alerts`
  ADD CONSTRAINT `fk_risk_alerts_active_project` FOREIGN KEY (`active_project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_risk_alerts_contract` FOREIGN KEY (`contract_id`) REFERENCES `planning_framework_contracts` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_risk_alerts_planning_project` FOREIGN KEY (`planning_project_id`) REFERENCES `planning_projects` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_risk_alerts_resolved_by` FOREIGN KEY (`resolved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_risk_alerts_scenario` FOREIGN KEY (`scenario_id`) REFERENCES `planning_scenarios` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `planning_scenarios`
--
ALTER TABLE `planning_scenarios`
  ADD CONSTRAINT `fk_planning_scenarios_account` FOREIGN KEY (`account_id`) REFERENCES `customer_accounts` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_planning_scenarios_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `planning_sunny_notes`
--
ALTER TABLE `planning_sunny_notes`
  ADD CONSTRAINT `fk_sunny_notes_active_project` FOREIGN KEY (`linked_active_project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_sunny_notes_allocation` FOREIGN KEY (`linked_allocation_id`) REFERENCES `planning_allocations` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_sunny_notes_contract` FOREIGN KEY (`linked_contract_id`) REFERENCES `planning_framework_contracts` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_sunny_notes_planning_project` FOREIGN KEY (`linked_planning_project_id`) REFERENCES `planning_projects` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_sunny_notes_scenario` FOREIGN KEY (`scenario_id`) REFERENCES `planning_scenarios` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_sunny_notes_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `projection_cost_summary`
--
ALTER TABLE `projection_cost_summary`
  ADD CONSTRAINT `fk_summary_projection` FOREIGN KEY (`projection_id`) REFERENCES `delivery_projections` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `projection_legs`
--
ALTER TABLE `projection_legs`
  ADD CONSTRAINT `fk_leg_from_stop` FOREIGN KEY (`from_stop_id`) REFERENCES `projection_stops` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_leg_projection` FOREIGN KEY (`projection_id`) REFERENCES `delivery_projections` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_leg_to_stop` FOREIGN KEY (`to_stop_id`) REFERENCES `projection_stops` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `projection_modules`
--
ALTER TABLE `projection_modules`
  ADD CONSTRAINT `projection_modules_ibfk_1` FOREIGN KEY (`projection_id`) REFERENCES `delivery_projections` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `projection_module_allocations`
--
ALTER TABLE `projection_module_allocations`
  ADD CONSTRAINT `fk_allocation_projection` FOREIGN KEY (`projection_id`) REFERENCES `delivery_projections` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `projection_module_items`
--
ALTER TABLE `projection_module_items`
  ADD CONSTRAINT `projection_module_items_ibfk_1` FOREIGN KEY (`projection_module_id`) REFERENCES `projection_modules` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `projection_module_milestones`
--
ALTER TABLE `projection_module_milestones`
  ADD CONSTRAINT `projection_module_milestones_ibfk_1` FOREIGN KEY (`projection_module_id`) REFERENCES `projection_modules` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `projection_stops`
--
ALTER TABLE `projection_stops`
  ADD CONSTRAINT `fk_stop_projection` FOREIGN KEY (`projection_id`) REFERENCES `delivery_projections` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_stop_warehouse` FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `projection_stop_fees`
--
ALTER TABLE `projection_stop_fees`
  ADD CONSTRAINT `fk_fee_stop` FOREIGN KEY (`stop_id`) REFERENCES `projection_stops` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `projects`
--
ALTER TABLE `projects`
  ADD CONSTRAINT `fk_admin` FOREIGN KEY (`admin_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_manual_health_set_by` FOREIGN KEY (`manual_health_set_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `project_documents`
--
ALTER TABLE `project_documents`
  ADD CONSTRAINT `fk_manufacturer` FOREIGN KEY (`manufacturer_id`) REFERENCES `manufacturers` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_project_documents_delivery` FOREIGN KEY (`delivery_id`) REFERENCES `deliveries` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_project_documents_invoice` FOREIGN KEY (`project_invoice_id`) REFERENCES `project_invoices` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_project_documents_manufacturer` FOREIGN KEY (`manufacturer_id`) REFERENCES `manufacturers` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_project_documents_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_project_documents_user` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_project_documents_warehouse` FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_project_invoice` FOREIGN KEY (`project_invoice_id`) REFERENCES `project_invoices` (`id`) ON DELETE SET NULL;

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
-- Constraints for table `schedule_uploads`
--
ALTER TABLE `schedule_uploads`
  ADD CONSTRAINT `schedule_uploads_ibfk_1` FOREIGN KEY (`account_id`) REFERENCES `customer_accounts` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `schedule_uploads_ibfk_2` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `schedule_uploads_ibfk_3` FOREIGN KEY (`manufacturer_id`) REFERENCES `manufacturers` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `schedule_uploads_ibfk_4` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`) ON DELETE CASCADE;

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
-- Constraints for table `sunny_conversations`
--
ALTER TABLE `sunny_conversations`
  ADD CONSTRAINT `fk_convos_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `sunny_feedback`
--
ALTER TABLE `sunny_feedback`
  ADD CONSTRAINT `fk_feedback_convo` FOREIGN KEY (`conversation_id`) REFERENCES `sunny_conversations` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_feedback_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `sunny_memory`
--
ALTER TABLE `sunny_memory`
  ADD CONSTRAINT `sunny_memory_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `sunny_messages`
--
ALTER TABLE `sunny_messages`
  ADD CONSTRAINT `fk_msgs_convo` FOREIGN KEY (`conversation_id`) REFERENCES `sunny_conversations` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `sunny_quick_actions`
--
ALTER TABLE `sunny_quick_actions`
  ADD CONSTRAINT `fk_quick_actions_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `sunny_usage`
--
ALTER TABLE `sunny_usage`
  ADD CONSTRAINT `fk_usage_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `unassigned_module_items`
--
ALTER TABLE `unassigned_module_items`
  ADD CONSTRAINT `fk_unassigned_module_items_module` FOREIGN KEY (`unassigned_module_id`) REFERENCES `modules` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `warehouses`
--
ALTER TABLE `warehouses`
  ADD CONSTRAINT `fk_warehouses_account_id` FOREIGN KEY (`account_id`) REFERENCES `customer_accounts` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

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

--
-- Constraints for table `warranty_claim_events`
--
ALTER TABLE `warranty_claim_events`
  ADD CONSTRAINT `warranty_claim_events_ibfk_1` FOREIGN KEY (`claim_id`) REFERENCES `warranty_claims` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `warranty_claim_events_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `warranty_claim_replacements`
--
ALTER TABLE `warranty_claim_replacements`
  ADD CONSTRAINT `warranty_claim_replacements_ibfk_1` FOREIGN KEY (`claim_id`) REFERENCES `warranty_claims` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `warranty_claim_replacements_ibfk_2` FOREIGN KEY (`pallet_id`) REFERENCES `inventory_pallets` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
