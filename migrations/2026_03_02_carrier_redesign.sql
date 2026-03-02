-- ============================================================
-- Carrier System Redesign Migration
-- Date: 2026-03-02
-- Description: Add compliance columns, carrier_id to project_documents,
--              drop carrier_requests, seed Solterra Solutions carrier
-- ============================================================

-- 1a. Add compliance columns to carriers table
ALTER TABLE carriers
  ADD COLUMN coi_on_file TINYINT(1) DEFAULT 0 AFTER notes,
  ADD COLUMN coi_expiration_date DATE DEFAULT NULL AFTER coi_on_file,
  ADD COLUMN insurance_minimum_met TINYINT(1) DEFAULT 0 AFTER coi_expiration_date,
  ADD COLUMN authority_status ENUM('active','inactive','revoked') DEFAULT NULL AFTER insurance_minimum_met,
  ADD COLUMN fmcsa_safety_rating ENUM('satisfactory','conditional','unsatisfactory','not_rated') DEFAULT NULL AFTER authority_status;

-- 1b. Add carrier_id column to project_documents
ALTER TABLE project_documents
  ADD COLUMN carrier_id INT(11) DEFAULT NULL AFTER manufacturer_id,
  ADD INDEX idx_project_documents_carrier (carrier_id),
  ADD CONSTRAINT fk_project_documents_carrier
    FOREIGN KEY (carrier_id) REFERENCES carriers(id) ON DELETE SET NULL;

-- 1c. Drop carrier_requests table
DROP TABLE IF EXISTS carrier_requests;

-- 1d. Seed "Solterra Solutions" carrier (ensure exists)
INSERT IGNORE INTO carriers (name, short_name, is_solterra_managed, carrier_type, is_active)
VALUES ('Solterra Solutions', 'Solterra', 1, 'ftl', 1);
