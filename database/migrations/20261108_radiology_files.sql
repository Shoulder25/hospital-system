CREATE TABLE radiology_files (
    radiology_file_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    test_order_id INT UNSIGNED NOT NULL,
    uploaded_by_user_id INT UNSIGNED NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    storage_key CHAR(48) NOT NULL,
    mime_type VARCHAR(100) NOT NULL,
    file_kind ENUM('image', 'supporting_document') NOT NULL,
    file_size_bytes INT UNSIGNED NOT NULL,
    uploaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    deleted_at DATETIME NULL,
    deleted_by_user_id INT UNSIGNED NULL,
    PRIMARY KEY (radiology_file_id),
    UNIQUE KEY uq_radiology_files_storage_key (storage_key),
    KEY idx_radiology_files_order (test_order_id, uploaded_at),
    KEY idx_radiology_files_deleted (deleted_at),
    CONSTRAINT fk_radiology_files_order
        FOREIGN KEY (test_order_id) REFERENCES test_orders (test_order_id),
    CONSTRAINT fk_radiology_files_uploaded_by
        FOREIGN KEY (uploaded_by_user_id) REFERENCES users (user_id),
    CONSTRAINT fk_radiology_files_deleted_by
        FOREIGN KEY (deleted_by_user_id) REFERENCES users (user_id)
        ON DELETE SET NULL
) ENGINE=InnoDB;
