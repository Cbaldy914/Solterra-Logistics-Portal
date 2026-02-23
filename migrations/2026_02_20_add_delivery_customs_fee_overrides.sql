-- Adds delivery-level customs fee override fields.

ALTER TABLE deliveries
    ADD COLUMN IF NOT EXISTS customs_fee_override DECIMAL(10,2) NULL AFTER accessorial_costs,
    ADD COLUMN IF NOT EXISTS customs_fee_notes VARCHAR(255) NULL AFTER customs_fee_override;

