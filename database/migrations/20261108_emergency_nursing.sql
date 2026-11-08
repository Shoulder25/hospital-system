-- Emergency Nurse workflow: permit an acknowledged nurse case before a doctor
-- is assigned, and retain drafts, observations, and handover acknowledgements.
ALTER TABLE emergency_encounters
    MODIFY COLUMN emergency_doctor_id INT UNSIGNED NULL,
    ADD COLUMN handover_status
        ENUM('not_requested', 'pending', 'accepted')
        NOT NULL DEFAULT 'not_requested' AFTER stabilization_notes,
    ADD COLUMN handover_requested_at DATETIME NULL AFTER handover_status,
    ADD COLUMN handover_accepted_at DATETIME NULL AFTER handover_requested_at;

CREATE TABLE emergency_nursing_assessments (
    emergency_nursing_assessment_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    emergency_encounter_id INT UNSIGNED NOT NULL,
    nurse_user_id INT UNSIGNED NOT NULL,
    symptoms TEXT NULL,
    blood_pressure VARCHAR(40) NULL,
    pulse VARCHAR(40) NULL,
    respiratory_rate VARCHAR(40) NULL,
    temperature VARCHAR(40) NULL,
    oxygen_saturation VARCHAR(40) NULL,
    consciousness_level VARCHAR(100) NULL,
    pain_score VARCHAR(40) NULL,
    allergies TEXT NULL,
    visible_injuries TEXT NULL,
    triage_priority VARCHAR(100) NULL,
    nursing_notes TEXT NULL,
    immediate_care TEXT NULL,
    status ENUM('draft', 'complete') NOT NULL DEFAULT 'draft',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,
    completed_at DATETIME NULL,
    PRIMARY KEY (emergency_nursing_assessment_id),
    UNIQUE KEY uq_emergency_nursing_assessment_encounter (emergency_encounter_id),
    KEY idx_emergency_nursing_assessment_nurse (nurse_user_id),
    CONSTRAINT fk_emergency_nursing_assessment_encounter
        FOREIGN KEY (emergency_encounter_id)
        REFERENCES emergency_encounters (emergency_encounter_id),
    CONSTRAINT fk_emergency_nursing_assessment_nurse
        FOREIGN KEY (nurse_user_id) REFERENCES users (user_id)
) ENGINE=InnoDB;

CREATE TABLE emergency_nursing_observations (
    emergency_nursing_observation_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    emergency_encounter_id INT UNSIGNED NOT NULL,
    nurse_user_id INT UNSIGNED NOT NULL,
    blood_pressure VARCHAR(40) NULL,
    pulse VARCHAR(40) NULL,
    respiratory_rate VARCHAR(40) NULL,
    temperature VARCHAR(40) NULL,
    oxygen_saturation VARCHAR(40) NULL,
    consciousness_level VARCHAR(100) NULL,
    pain_score VARCHAR(40) NULL,
    nursing_notes TEXT NULL,
    immediate_care TEXT NULL,
    recorded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (emergency_nursing_observation_id),
    KEY idx_emergency_nursing_observations_encounter
        (emergency_encounter_id, recorded_at),
    KEY idx_emergency_nursing_observations_nurse (nurse_user_id),
    CONSTRAINT fk_emergency_nursing_observations_encounter
        FOREIGN KEY (emergency_encounter_id)
        REFERENCES emergency_encounters (emergency_encounter_id),
    CONSTRAINT fk_emergency_nursing_observations_nurse
        FOREIGN KEY (nurse_user_id) REFERENCES users (user_id)
) ENGINE=InnoDB;
