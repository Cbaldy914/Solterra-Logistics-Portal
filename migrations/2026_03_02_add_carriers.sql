-- Carrier management system: carriers table, carrier_requests, deliveries ALTER
-- Run in phpMyAdmin (SQL tab) against the portal database.

START TRANSACTION;

-- ============================================================
-- 1. carriers table (modeled on manufacturers)
-- ============================================================
CREATE TABLE carriers (
    id INT(11) PRIMARY KEY AUTO_INCREMENT,
    account_id INT(11) NULL,
    name VARCHAR(255) NOT NULL,
    short_name VARCHAR(100) DEFAULT NULL,
    mc_number VARCHAR(50) DEFAULT NULL,
    dot_number VARCHAR(50) DEFAULT NULL,
    carrier_type ENUM('ftl','ltl','drayage','intermodal','ocean','other') DEFAULT 'ftl',
    is_solterra_managed TINYINT(1) DEFAULT 0,
    contact_person VARCHAR(255) DEFAULT NULL,
    phone VARCHAR(50) DEFAULT NULL,
    email VARCHAR(255) DEFAULT NULL,
    website VARCHAR(255) DEFAULT NULL,
    street_address VARCHAR(255) DEFAULT NULL,
    city VARCHAR(100) DEFAULT NULL,
    state VARCHAR(50) DEFAULT NULL,
    zip_code VARCHAR(20) DEFAULT NULL,
    country VARCHAR(100) DEFAULT 'USA',
    address TEXT DEFAULT NULL,
    logo_url VARCHAR(500) DEFAULT NULL,
    is_active TINYINT(1) DEFAULT 1,
    notes TEXT DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_carriers_account FOREIGN KEY (account_id) REFERENCES customer_accounts(id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

CREATE INDEX idx_carriers_account_id ON carriers (account_id);
CREATE INDEX idx_carriers_is_active ON carriers (is_active);
CREATE INDEX idx_carriers_is_solterra_managed ON carriers (is_solterra_managed);

-- ============================================================
-- 2. Address triggers (identical pattern to manufacturers)
-- ============================================================
DELIMITER $$

CREATE TRIGGER update_carrier_address_on_insert BEFORE INSERT ON carriers FOR EACH ROW
BEGIN
    IF NEW.street_address IS NOT NULL OR NEW.city IS NOT NULL OR NEW.state IS NOT NULL OR NEW.zip_code IS NOT NULL THEN
        SET NEW.address = CONCAT_WS(', ',
            NULLIF(NEW.street_address, ''),
            NULLIF(NEW.city, ''),
            CASE
                WHEN NEW.state IS NOT NULL AND NEW.zip_code IS NOT NULL
                THEN CONCAT(NEW.state, ' ', NEW.zip_code)
                WHEN NEW.state IS NOT NULL
                THEN NEW.state
                WHEN NEW.zip_code IS NOT NULL
                THEN NEW.zip_code
                ELSE NULL
            END,
            NULLIF(NEW.country, '')
        );
    END IF;
END$$

CREATE TRIGGER update_carrier_address_on_update BEFORE UPDATE ON carriers FOR EACH ROW
BEGIN
    IF NEW.street_address IS NOT NULL OR NEW.city IS NOT NULL OR NEW.state IS NOT NULL OR NEW.zip_code IS NOT NULL THEN
        SET NEW.address = CONCAT_WS(', ',
            NULLIF(NEW.street_address, ''),
            NULLIF(NEW.city, ''),
            CASE
                WHEN NEW.state IS NOT NULL AND NEW.zip_code IS NOT NULL
                THEN CONCAT(NEW.state, ' ', NEW.zip_code)
                WHEN NEW.state IS NOT NULL
                THEN NEW.state
                WHEN NEW.zip_code IS NOT NULL
                THEN NEW.zip_code
                ELSE NULL
            END,
            NULLIF(NEW.country, '')
        );
    END IF;
END$$

DELIMITER ;

-- ============================================================
-- 3. Seed Solterra as a carrier
-- ============================================================
INSERT INTO carriers (name, short_name, is_solterra_managed, carrier_type)
VALUES ('Solterra Solutions', 'Solterra', 1, 'ftl');

-- ============================================================
-- 4. carrier_requests table (approval workflow)
-- ============================================================
CREATE TABLE carrier_requests (
    id INT(11) PRIMARY KEY AUTO_INCREMENT,
    account_id INT(11) NOT NULL,
    requested_by INT(11) NOT NULL,
    status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
    name VARCHAR(255) NOT NULL,
    short_name VARCHAR(100) DEFAULT NULL,
    mc_number VARCHAR(50) DEFAULT NULL,
    dot_number VARCHAR(50) DEFAULT NULL,
    carrier_type ENUM('ftl','ltl','drayage','intermodal','ocean','other') DEFAULT 'ftl',
    is_solterra_managed TINYINT(1) DEFAULT 0,
    contact_person VARCHAR(255) DEFAULT NULL,
    phone VARCHAR(50) DEFAULT NULL,
    email VARCHAR(255) DEFAULT NULL,
    website VARCHAR(255) DEFAULT NULL,
    street_address VARCHAR(255) DEFAULT NULL,
    city VARCHAR(100) DEFAULT NULL,
    state VARCHAR(50) DEFAULT NULL,
    zip_code VARCHAR(20) DEFAULT NULL,
    country VARCHAR(100) DEFAULT 'USA',
    logo_url VARCHAR(500) DEFAULT NULL,
    is_active TINYINT(1) DEFAULT 1,
    notes TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    reviewed_by INT(11) DEFAULT NULL,
    reviewed_at DATETIME DEFAULT NULL,
    rejection_reason TEXT DEFAULT NULL,
    approved_carrier_id INT(11) DEFAULT NULL,
    CONSTRAINT fk_carrier_requests_account FOREIGN KEY (account_id) REFERENCES customer_accounts(id),
    CONSTRAINT fk_carrier_requests_requested_by FOREIGN KEY (requested_by) REFERENCES users(id),
    CONSTRAINT fk_carrier_requests_reviewed_by FOREIGN KEY (reviewed_by) REFERENCES users(id),
    CONSTRAINT fk_carrier_requests_approved_carrier FOREIGN KEY (approved_carrier_id) REFERENCES carriers(id)
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

CREATE INDEX idx_carrier_requests_status ON carrier_requests (status);
CREATE INDEX idx_carrier_requests_account ON carrier_requests (account_id);

-- ============================================================
-- 5. ALTER deliveries: add carrier_id + carrier_reference_number
-- ============================================================
ALTER TABLE deliveries
    ADD COLUMN carrier_id INT(11) NULL AFTER supplier,
    ADD COLUMN carrier_reference_number VARCHAR(100) NULL AFTER carrier_id;

ALTER TABLE deliveries
    ADD CONSTRAINT fk_deliveries_carrier FOREIGN KEY (carrier_id) REFERENCES carriers(id)
        ON DELETE SET NULL ON UPDATE CASCADE;

CREATE INDEX idx_deliveries_carrier_id ON deliveries (carrier_id);

-- ============================================================
-- 6. Notification settings for carrier requests
-- ============================================================
ALTER TABLE notification_settings
    ADD COLUMN in_app_carrier_request TINYINT(1) DEFAULT 1,
    ADD COLUMN email_carrier_request TINYINT(1) DEFAULT 0;

COMMIT;
