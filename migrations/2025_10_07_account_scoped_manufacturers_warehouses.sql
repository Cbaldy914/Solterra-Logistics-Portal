-- Account scoping for warehouses + manufacturer request workflow
-- Run in phpMyAdmin (SQL tab) against the portal database.

START TRANSACTION;

ALTER TABLE warehouses
    ADD COLUMN account_id INT(11) NULL AFTER id;
CREATE INDEX idx_warehouses_account_id ON warehouses (account_id);

ALTER TABLE warehouses
    ADD CONSTRAINT fk_warehouses_account_id
        FOREIGN KEY (account_id) REFERENCES customer_accounts(id)
        ON DELETE SET NULL
        ON UPDATE CASCADE;

-- Manufacturer requests (submitted by customer_admin; approved by admin/global_admin)
CREATE TABLE manufacturer_requests (
    id INT(11) PRIMARY KEY AUTO_INCREMENT,
    account_id INT(11) NOT NULL,
    requested_by INT(11) NOT NULL,
    status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
    name VARCHAR(255) NOT NULL,
    short_name VARCHAR(255),
    contact_person VARCHAR(255),
    phone VARCHAR(50),
    email VARCHAR(255),
    website VARCHAR(255),
    street_address VARCHAR(255),
    city VARCHAR(100),
    state VARCHAR(50),
    zip_code VARCHAR(20),
    country VARCHAR(100) DEFAULT 'USA',
    logo_url VARCHAR(255),
    is_active TINYINT(1) DEFAULT 1,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    reviewed_by INT(11),
    reviewed_at DATETIME,
    rejection_reason TEXT,
    approved_manufacturer_id INT(11),
    FOREIGN KEY (account_id) REFERENCES customer_accounts(id),
    FOREIGN KEY (requested_by) REFERENCES users(id),
    FOREIGN KEY (reviewed_by) REFERENCES users(id),
    FOREIGN KEY (approved_manufacturer_id) REFERENCES manufacturers(id)
);

CREATE INDEX idx_manufacturer_requests_status ON manufacturer_requests (status);
CREATE INDEX idx_manufacturer_requests_account ON manufacturer_requests (account_id);

-- Manufacturer location requests (submitted by customer_admin; approved by admin/global_admin)
CREATE TABLE manufacturer_location_requests (
    id INT(11) PRIMARY KEY AUTO_INCREMENT,
    manufacturer_id INT(11) NOT NULL,
    account_id INT(11) NOT NULL,
    requested_by INT(11) NOT NULL,
    status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
    location_name VARCHAR(255) NOT NULL,
    street_address VARCHAR(255),
    city VARCHAR(100),
    state VARCHAR(50),
    zip_code VARCHAR(20),
    country VARCHAR(100) DEFAULT 'USA',
    is_primary TINYINT(1) DEFAULT 0,
    is_active TINYINT(1) DEFAULT 1,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    reviewed_by INT(11),
    reviewed_at DATETIME,
    rejection_reason TEXT,
    approved_location_id INT(11),
    FOREIGN KEY (manufacturer_id) REFERENCES manufacturers(id),
    FOREIGN KEY (account_id) REFERENCES customer_accounts(id),
    FOREIGN KEY (requested_by) REFERENCES users(id),
    FOREIGN KEY (reviewed_by) REFERENCES users(id),
    FOREIGN KEY (approved_location_id) REFERENCES manufacturer_locations(id)
);

CREATE INDEX idx_manufacturer_location_requests_status ON manufacturer_location_requests (status);
CREATE INDEX idx_manufacturer_location_requests_account ON manufacturer_location_requests (account_id);
CREATE INDEX idx_manufacturer_location_requests_mfg ON manufacturer_location_requests (manufacturer_id);

-- Notification settings for manufacturer requests
ALTER TABLE notification_settings
    ADD COLUMN in_app_manufacturer_request TINYINT(1) DEFAULT 1,
    ADD COLUMN email_manufacturer_request TINYINT(1) DEFAULT 0;

COMMIT;

-- After running this migration, backfill account_id for existing warehouses.
-- Example (replace ACCOUNT_ID with the correct value per account):
-- UPDATE warehouses SET account_id = ACCOUNT_ID WHERE account_id IS NULL;
