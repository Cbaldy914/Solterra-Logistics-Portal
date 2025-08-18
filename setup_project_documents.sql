-- Create project_documents table for general document management
-- This complements existing entity-specific document storage

USE solterra_portal;

CREATE TABLE IF NOT EXISTS `project_documents` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `project_id` int(11) NOT NULL,
  `document_type` varchar(100) NOT NULL COMMENT 'invoices, pods, flash_test_data, bills_of_lading, warehousing, modules, delivery_packet, incident_reports, safe_harbor_evidence',
  `file_name` varchar(255) NOT NULL COMMENT 'Unique filename on server',
  `original_file_name` varchar(255) NOT NULL COMMENT 'Original filename from user',
  `file_path` varchar(500) NOT NULL COMMENT 'Full path to file',
  `file_size` bigint NOT NULL COMMENT 'File size in bytes',
  `mime_type` varchar(100) NOT NULL COMMENT 'MIME type of file',
  `uploaded_by` int(11) NOT NULL COMMENT 'User ID who uploaded the file',
  `uploaded_at` datetime NOT NULL DEFAULT current_timestamp(),
  `description` text DEFAULT NULL COMMENT 'Optional description of the document',
  `is_active` tinyint(1) NOT NULL DEFAULT 1 COMMENT 'Soft delete flag',
  PRIMARY KEY (`id`),
  KEY `idx_project_id` (`project_id`),
  KEY `idx_document_type` (`document_type`),
  KEY `idx_uploaded_by` (`uploaded_by`),
  KEY `idx_uploaded_at` (`uploaded_at`),
  KEY `idx_is_active` (`is_active`),
  CONSTRAINT `fk_project_documents_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_project_documents_user` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='General project documents not tied to specific entities';

-- Create uploads directory structure (you'll need to create these folders manually)
-- uploads/
--   project_documents/
--     {project_id}/
--       invoices/
--       pods/
--       flash_test_data/
--       bills_of_lading/
--       warehousing/
--       modules/
--       delivery_packet/
--       incident_reports/
--       safe_harbor_evidence/
