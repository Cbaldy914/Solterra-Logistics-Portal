-- Fix: Add missing status values to inventory_pallets ENUM.
-- 'Customs Hold' was missing, causing pallets placed on hold to get empty status (MySQL non-strict ENUM behavior).
-- 'Allocated to Project' is also used by the shipment workflow.

ALTER TABLE inventory_pallets
    MODIFY COLUMN status ENUM(
        'At Manufacturer',
        'On Water',
        'Cleared Customs',
        'Customs Hold',
        'In Transit to Warehouse',
        'In Warehouse',
        'Allocated to Project',
        'In Transit to Project',
        'Delivered to Project',
        'Damaged'
    ) NOT NULL DEFAULT 'At Manufacturer';

-- Repair any pallets that were corrupted by the missing ENUM value.
-- They will have empty string status from the failed ENUM insert.
-- Set them back to 'Cleared Customs' so users can re-process them.
UPDATE inventory_pallets SET status = 'Cleared Customs' WHERE status = '';
