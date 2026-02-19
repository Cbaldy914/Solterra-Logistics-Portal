-- Stores vessel waypoint history for on-water containers.
-- Each saved coordinate in container_tracking.php appends one row.

CREATE TABLE IF NOT EXISTS container_tracking_waypoints (
    id INT AUTO_INCREMENT PRIMARY KEY,
    container_number VARCHAR(64) NOT NULL,
    project_id INT NOT NULL,
    latitude DECIMAL(10,7) NOT NULL,
    longitude DECIMAL(10,7) NOT NULL,
    recorded_by INT DEFAULT NULL,
    recorded_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_container_project_time (container_number, project_id, recorded_at),
    KEY idx_project_id (project_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
