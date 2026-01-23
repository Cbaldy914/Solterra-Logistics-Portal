-- =====================================================
-- Add PO Execution Date to Modules table
-- Solterra Logistics Portal
-- Created: 2026-01-23
-- =====================================================

-- Add po_execution_date column to modules table to track when PO was actually executed
-- This enables accurate actual cost tracking on the Forecasted vs Actual chart

ALTER TABLE `modules`
ADD COLUMN `po_execution_date` date DEFAULT NULL COMMENT 'Date when the PO was executed for this module batch'
AFTER `cost_per_watt`;

-- =====================================================
-- END OF MIGRATION
-- =====================================================
