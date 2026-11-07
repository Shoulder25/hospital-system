-- Stage 3 nursing fields.
-- Run once against an existing hospital_system database.
-- Existing assessment decisions are preserved; NULL means assessment saved
-- but the nurse has not yet submitted an outcome.
ALTER TABLE nurse_assessments
    MODIFY COLUMN decision
        ENUM('minor_illness', 'tests', 'general_doctor') NULL DEFAULT NULL,
    ADD COLUMN symptoms TEXT NULL AFTER complaint,
    ADD COLUMN symptom_duration VARCHAR(100) NULL AFTER symptoms;
