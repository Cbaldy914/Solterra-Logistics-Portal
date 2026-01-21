-- =====================================================
-- Add PO Execution Date to Module Allocations
-- Solterra Logistics Portal
-- Created: 2026-01-20
-- =====================================================

-- Add po_execution_date column to track when PO was executed for each allocation
-- This enables milestone payment tracking based on PO execution date

ALTER TABLE `projection_module_allocations`
ADD COLUMN `po_execution_date` date DEFAULT NULL COMMENT 'Date when the PO was executed for milestone calculations'
AFTER `pallets`;

-- =====================================================
-- END OF MIGRATION
-- =====================================================
