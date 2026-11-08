ALTER TABLE medicines
    ADD COLUMN reorder_level DECIMAL(10,2) NOT NULL DEFAULT 5.00
        AFTER quantity_in_stock,
    ADD COLUMN expiry_date DATE NULL
        AFTER reorder_level;

ALTER TABLE dispensings
    ADD COLUMN dispensing_notes TEXT NULL
        AFTER payment_id;

CREATE TABLE IF NOT EXISTS pharmacy_stock_movements (
    stock_movement_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    medicine_id INT UNSIGNED NOT NULL,
    recorded_by_user_id INT UNSIGNED NOT NULL,
    movement_type ENUM('received', 'adjustment', 'dispensed') NOT NULL,
    quantity_change DECIMAL(10,2) NOT NULL,
    stock_after DECIMAL(10,2) NOT NULL,
    dispensing_id INT UNSIGNED NULL,
    notes VARCHAR(1000) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (stock_movement_id),
    KEY idx_pharmacy_stock_medicine_date (medicine_id, created_at),
    KEY idx_pharmacy_stock_type_date (movement_type, created_at),
    CONSTRAINT fk_pharmacy_stock_medicine
        FOREIGN KEY (medicine_id) REFERENCES medicines (medicine_id),
    CONSTRAINT fk_pharmacy_stock_user
        FOREIGN KEY (recorded_by_user_id) REFERENCES users (user_id),
    CONSTRAINT fk_pharmacy_stock_dispensing
        FOREIGN KEY (dispensing_id) REFERENCES dispensings (dispensing_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS pharmacy_prescription_exceptions (
    exception_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    prescription_item_id INT UNSIGNED NOT NULL,
    recorded_by_user_id INT UNSIGNED NOT NULL,
    status ENUM('open', 'resolved') NOT NULL DEFAULT 'open',
    reason TEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    resolved_at DATETIME NULL,
    PRIMARY KEY (exception_id),
    KEY idx_pharmacy_exception_item_status
        (prescription_item_id, status, created_at),
    CONSTRAINT fk_pharmacy_exception_item
        FOREIGN KEY (prescription_item_id)
        REFERENCES prescription_items (prescription_item_id),
    CONSTRAINT fk_pharmacy_exception_user
        FOREIGN KEY (recorded_by_user_id) REFERENCES users (user_id)
) ENGINE=InnoDB;
