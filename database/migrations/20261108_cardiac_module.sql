-- Cardiac examination attempts and private ECG attachments.
-- Run once against an existing hospital_system database.

CREATE TABLE cardiac_examination_attempts (
    cardiac_examination_attempt_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    test_order_id INT UNSIGNED NOT NULL,
    technician_user_id INT UNSIGNED NOT NULL,
    completed_by_user_id INT UNSIGNED NULL,
    status ENUM('in_progress', 'unsuccessful', 'completed') NOT NULL,
    started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completed_at DATETIME NULL,
    failure_reason TEXT NULL,
    PRIMARY KEY (cardiac_examination_attempt_id),
    KEY idx_cardiac_attempts_order (test_order_id, cardiac_examination_attempt_id),
    KEY idx_cardiac_attempts_status (status),
    CONSTRAINT fk_cardiac_attempts_order
        FOREIGN KEY (test_order_id) REFERENCES test_orders (test_order_id),
    CONSTRAINT fk_cardiac_attempts_technician
        FOREIGN KEY (technician_user_id) REFERENCES users (user_id),
    CONSTRAINT fk_cardiac_attempts_completed_by
        FOREIGN KEY (completed_by_user_id) REFERENCES users (user_id)
        ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE cardiac_files (
    cardiac_file_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
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
    PRIMARY KEY (cardiac_file_id),
    UNIQUE KEY uq_cardiac_files_storage_key (storage_key),
    KEY idx_cardiac_files_order (test_order_id, uploaded_at),
    KEY idx_cardiac_files_deleted (deleted_at),
    CONSTRAINT fk_cardiac_files_order
        FOREIGN KEY (test_order_id) REFERENCES test_orders (test_order_id),
    CONSTRAINT fk_cardiac_files_uploaded_by
        FOREIGN KEY (uploaded_by_user_id) REFERENCES users (user_id),
    CONSTRAINT fk_cardiac_files_deleted_by
        FOREIGN KEY (deleted_by_user_id) REFERENCES users (user_id)
        ON DELETE SET NULL
) ENGINE=InnoDB;
