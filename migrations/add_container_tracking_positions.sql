-- Stores optional manual vessel coordinates for active containers.
-- Used by container_tracking.php and module_movements.php as an override
-- to avoid land-crossing ETA interpolation when real vessel position is known.

CREATE TABLE IF NOT EXISTS container_tracking_positions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    container_number VARCHAR(64) NOT NULL,
    project_id INT NOT NULL,
    latitude DECIMAL(10,7) DEFAULT NULL,
    longitude DECIMAL(10,7) DEFAULT NULL,
    updated_by INT DEFAULT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_container_project (container_number, project_id),
    KEY idx_project_id (project_id),
    KEY idx_container_number (container_number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
