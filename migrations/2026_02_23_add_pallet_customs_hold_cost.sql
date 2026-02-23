-- Adds pallet-level customs hold cost tracking.

ALTER TABLE inventory_pallets
    ADD COLUMN IF NOT EXISTS customs_hold_cost DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER accessorial_cost,
    ADD COLUMN IF NOT EXISTS customs_hold_cost_notes VARCHAR(255) NULL AFTER customs_hold_cost,
    ADD COLUMN IF NOT EXISTS customs_hold_cost_updated_at DATETIME NULL AFTER customs_hold_cost_notes;
