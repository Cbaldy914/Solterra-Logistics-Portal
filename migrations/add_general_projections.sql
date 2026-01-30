-- General Projections: Allow delivery_projections to exist without a project
-- This enables hypothetical project planning before projects are created in the system

-- Make project_id nullable (currently NOT NULL with FK constraint)
ALTER TABLE delivery_projections DROP FOREIGN KEY fk_projection_project;
ALTER TABLE delivery_projections MODIFY project_id int(11) DEFAULT NULL;
ALTER TABLE delivery_projections ADD CONSTRAINT fk_projection_project
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE;

-- Add general projection columns
ALTER TABLE delivery_projections
    ADD COLUMN is_general tinyint(1) NOT NULL DEFAULT 0 AFTER is_template,
    ADD COLUMN general_project_name varchar(255) DEFAULT NULL AFTER is_general,
    ADD COLUMN general_project_address varchar(500) DEFAULT NULL AFTER general_project_name,
    ADD COLUMN general_estimated_mw decimal(10,2) DEFAULT NULL AFTER general_project_address,
    ADD COLUMN linked_project_id int(11) DEFAULT NULL AFTER general_estimated_mw,
    ADD KEY idx_projection_general (is_general),
    ADD KEY idx_projection_linked (linked_project_id);
