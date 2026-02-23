-- Adds customs-hold tracking metadata and event audit table.

ALTER TABLE deliveries
    ADD COLUMN IF NOT EXISTS customs_hold_started_date DATE NULL AFTER customs_cleared_date,
    ADD COLUMN IF NOT EXISTS customs_hold_released_date DATE NULL AFTER customs_hold_started_date,
    ADD COLUMN IF NOT EXISTS customs_hold_reason VARCHAR(255) NULL AFTER customs_hold_released_date,
    ADD COLUMN IF NOT EXISTS customs_hold_notes TEXT NULL AFTER customs_hold_reason;

ALTER TABLE deliveries
    ADD INDEX IF NOT EXISTS idx_deliveries_status_project (status_of_delivery, project_id),
    ADD INDEX IF NOT EXISTS idx_deliveries_customs_hold_started (customs_hold_started_date);

CREATE TABLE IF NOT EXISTS delivery_status_events (
    id INT AUTO_INCREMENT PRIMARY KEY,
    delivery_id INT NOT NULL,
    from_status VARCHAR(50) NULL,
    to_status VARCHAR(50) NOT NULL,
    event_type VARCHAR(64) NOT NULL,
    event_notes TEXT NULL,
    changed_by INT NULL,
    changed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_delivery_status_events_delivery (delivery_id),
    KEY idx_delivery_status_events_event (event_type),
    KEY idx_delivery_status_events_changed_at (changed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

