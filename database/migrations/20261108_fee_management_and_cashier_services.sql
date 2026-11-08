ALTER TABLE fee_catalog
    ADD COLUMN currency_code CHAR(3) NOT NULL DEFAULT 'SZL' AFTER default_amount,
    ADD COLUMN cashier_addable TINYINT(1) NOT NULL DEFAULT 0 AFTER is_active,
    ADD COLUMN updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP AFTER cashier_addable,
    ADD COLUMN updated_by_user_id INT UNSIGNED NULL AFTER updated_at,
    ADD CONSTRAINT fk_fee_catalog_updated_by
        FOREIGN KEY (updated_by_user_id) REFERENCES users (user_id)
        ON DELETE SET NULL;

ALTER TABLE bill_items
    ADD COLUMN client_request_id CHAR(36) NULL AFTER emergency_encounter_id,
    ADD COLUMN created_by_user_id INT UNSIGNED NULL AFTER client_request_id,
    ADD COLUMN is_voided TINYINT(1) NOT NULL DEFAULT 0 AFTER created_by_user_id,
    ADD COLUMN voided_at DATETIME NULL AFTER is_voided,
    ADD COLUMN voided_by_user_id INT UNSIGNED NULL AFTER voided_at,
    ADD UNIQUE KEY uq_bill_items_client_request (client_request_id),
    ADD CONSTRAINT fk_bill_items_created_by
        FOREIGN KEY (created_by_user_id) REFERENCES users (user_id)
        ON DELETE SET NULL,
    ADD CONSTRAINT fk_bill_items_voided_by
        FOREIGN KEY (voided_by_user_id) REFERENCES users (user_id)
        ON DELETE SET NULL;
