-- Migration: Create archived_projects table and add status column to projects
-- Run this SQL in your database to enable project archiving functionality

-- Add status column to projects table if it doesn't exist
ALTER TABLE projects
ADD COLUMN IF NOT EXISTS status ENUM('active', 'closed') DEFAULT 'active';

-- Create index on status for faster filtering
CREATE INDEX IF NOT EXISTS idx_projects_status ON projects(status);

-- Create archived_projects table
CREATE TABLE IF NOT EXISTS archived_projects (
    id INT AUTO_INCREMENT PRIMARY KEY,
    project_id INT NOT NULL,
    account_id INT,
    project_name VARCHAR(255) NOT NULL,
    archive_path VARCHAR(500) NOT NULL,
    archive_filename VARCHAR(255) NOT NULL,
    file_size_bytes BIGINT DEFAULT 0,
    closed_by INT NOT NULL,
    closed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    summary_text TEXT,
    delivery_percent DECIMAL(5,2) DEFAULT 0,
    total_modules INT DEFAULT 0,
    delivered_modules INT DEFAULT 0,
    notes TEXT,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    FOREIGN KEY (account_id) REFERENCES customer_accounts(id) ON DELETE SET NULL,
    FOREIGN KEY (closed_by) REFERENCES users(id) ON DELETE CASCADE,

    INDEX idx_archived_account (account_id),
    INDEX idx_archived_closed_at (closed_at),
    INDEX idx_archived_project (project_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Create directory for archived project files (run this manually on your server):
-- mkdir -p uploads/archived_projects
