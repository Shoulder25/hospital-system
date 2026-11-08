-- Laboratory specimen collection attempts are retained per existing test order.
CREATE TABLE lab_specimens (
    lab_specimen_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    test_order_id INT UNSIGNED NOT NULL,
    specimen_type VARCHAR(100) NULL,
    status ENUM(
        'awaiting_collection',
        'collected',
        'processing',
        'completed',
        'rejected'
    ) NOT NULL DEFAULT 'awaiting_collection',
    collected_at DATETIME NULL,
    recorded_by_user_id INT UNSIGNED NOT NULL,
    updated_by_user_id INT UNSIGNED NOT NULL,
    rejection_reason TEXT NULL,
    notes TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (lab_specimen_id),
    KEY idx_lab_specimens_order (test_order_id, lab_specimen_id),
    KEY idx_lab_specimens_status (status),
    CONSTRAINT fk_lab_specimens_order
        FOREIGN KEY (test_order_id) REFERENCES test_orders (test_order_id),
    CONSTRAINT fk_lab_specimens_user
        FOREIGN KEY (recorded_by_user_id) REFERENCES users (user_id),
    CONSTRAINT fk_lab_specimens_updated_by
        FOREIGN KEY (updated_by_user_id) REFERENCES users (user_id)
) ENGINE=InnoDB;
