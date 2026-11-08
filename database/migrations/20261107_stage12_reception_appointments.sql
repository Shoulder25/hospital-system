ALTER TABLE visits
    ADD COLUMN reason_for_visit VARCHAR(500) NULL AFTER arrived_at,
    ADD COLUMN reception_notes TEXT NULL AFTER reason_for_visit;

CREATE TABLE IF NOT EXISTS appointments (
    appointment_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    patient_id INT UNSIGNED NOT NULL,
    scheduled_at DATETIME NOT NULL,
    reason VARCHAR(500) NOT NULL,
    status ENUM('scheduled', 'arrived', 'cancelled') NOT NULL DEFAULT 'scheduled',
    visit_id INT UNSIGNED NULL,
    created_by_user_id INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (appointment_id),
    UNIQUE KEY uq_appointments_visit (visit_id),
    KEY idx_appointments_scheduled_status (scheduled_at, status),
    KEY idx_appointments_patient_schedule (patient_id, scheduled_at),
    CONSTRAINT fk_appointments_patient
        FOREIGN KEY (patient_id) REFERENCES patients (patient_id),
    CONSTRAINT fk_appointments_visit
        FOREIGN KEY (visit_id) REFERENCES visits (visit_id)
        ON DELETE SET NULL,
    CONSTRAINT fk_appointments_created_by
        FOREIGN KEY (created_by_user_id) REFERENCES users (user_id)
        ON DELETE SET NULL
) ENGINE=InnoDB;
