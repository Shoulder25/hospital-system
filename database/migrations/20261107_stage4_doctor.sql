-- Stage 4 doctor records. Run once against an existing hospital_system database.
-- Existing consultations remain intact and diagnosed consultations are copied
-- to the additive diagnoses table for structured history.
ALTER TABLE consultations
    ADD COLUMN presenting_complaint TEXT NULL AFTER consultation_type,
    ADD COLUMN clinical_observations TEXT NULL AFTER presenting_complaint,
    ADD COLUMN examination_findings TEXT NULL AFTER clinical_observations;

CREATE TABLE diagnoses (
    diagnosis_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    visit_id INT UNSIGNED NOT NULL,
    consultation_id INT UNSIGNED NOT NULL,
    diagnosed_by_user_id INT UNSIGNED NOT NULL,
    diagnosis_made TINYINT(1) NOT NULL DEFAULT 1,
    diagnosis_description TEXT NULL,
    clinical_findings TEXT NULL,
    treatment_plan TEXT NULL,
    notes TEXT NULL,
    diagnosed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (diagnosis_id),
    KEY idx_diagnoses_visit (visit_id, diagnosed_at),
    KEY idx_diagnoses_consultation (consultation_id),
    KEY idx_diagnoses_clinician (diagnosed_by_user_id),
    CONSTRAINT fk_diagnoses_visit
        FOREIGN KEY (visit_id) REFERENCES visits (visit_id),
    CONSTRAINT fk_diagnoses_consultation
        FOREIGN KEY (consultation_id) REFERENCES consultations (consultation_id),
    CONSTRAINT fk_diagnoses_clinician
        FOREIGN KEY (diagnosed_by_user_id) REFERENCES users (user_id)
) ENGINE=InnoDB;

INSERT INTO diagnoses
    (visit_id, consultation_id, diagnosed_by_user_id, diagnosis_made,
     diagnosis_description, treatment_plan, notes, diagnosed_at)
SELECT visit_id, consultation_id, clinician_user_id, diagnosis_made,
       diagnosis_text, treatment_plan, notes, consulted_at
FROM consultations;

ALTER TABLE prescriptions
    ADD COLUMN consultation_id INT UNSIGNED NULL AFTER visit_id,
    ADD COLUMN diagnosis_id INT UNSIGNED NULL AFTER consultation_id,
    ADD KEY idx_prescriptions_consultation (consultation_id),
    ADD KEY idx_prescriptions_diagnosis (diagnosis_id),
    ADD CONSTRAINT fk_prescriptions_consultation
        FOREIGN KEY (consultation_id) REFERENCES consultations (consultation_id)
        ON DELETE SET NULL,
    ADD CONSTRAINT fk_prescriptions_diagnosis
        FOREIGN KEY (diagnosis_id) REFERENCES diagnoses (diagnosis_id)
        ON DELETE SET NULL;

ALTER TABLE therapy_referrals
    ADD COLUMN diagnosis_id INT UNSIGNED NULL AFTER source_consultation_id,
    ADD COLUMN therapy_type VARCHAR(100) NULL AFTER referred_by_user_id,
    ADD KEY idx_therapy_referrals_diagnosis (diagnosis_id),
    ADD CONSTRAINT fk_therapy_referrals_diagnosis
        FOREIGN KEY (diagnosis_id) REFERENCES diagnoses (diagnosis_id)
        ON DELETE SET NULL;
