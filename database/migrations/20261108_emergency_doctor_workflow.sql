-- Emergency clinician progress, responsibility instructions, and disposition.
ALTER TABLE emergency_encounters
    ADD COLUMN outcome ENUM('observation', 'admission', 'transfer', 'discharge')
        NULL AFTER handover_accepted_at,
    ADD COLUMN outcome_notes TEXT NULL AFTER outcome,
    ADD COLUMN outcome_recorded_at DATETIME NULL AFTER outcome_notes,
    ADD COLUMN outcome_recorded_by_user_id INT UNSIGNED NULL
        AFTER outcome_recorded_at,
    ADD KEY idx_emergency_encounters_outcome (outcome),
    ADD CONSTRAINT fk_emergency_encounters_outcome_user
        FOREIGN KEY (outcome_recorded_by_user_id) REFERENCES users (user_id)
        ON DELETE SET NULL;

ALTER TABLE diagnoses
    ADD COLUMN diagnosis_stage ENUM('preliminary', 'final')
        NOT NULL DEFAULT 'final' AFTER diagnosis_made;

CREATE TABLE IF NOT EXISTS emergency_doctor_notes (
    emergency_doctor_note_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    emergency_encounter_id INT UNSIGNED NOT NULL,
    doctor_user_id INT UNSIGNED NOT NULL,
    note_type ENUM('treatment', 'instruction') NOT NULL,
    note_text TEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (emergency_doctor_note_id),
    KEY idx_emergency_doctor_notes_encounter
        (emergency_encounter_id, created_at),
    CONSTRAINT fk_emergency_doctor_notes_encounter
        FOREIGN KEY (emergency_encounter_id)
        REFERENCES emergency_encounters (emergency_encounter_id),
    CONSTRAINT fk_emergency_doctor_notes_doctor
        FOREIGN KEY (doctor_user_id) REFERENCES users (user_id)
) ENGINE=InnoDB;
