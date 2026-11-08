ALTER TABLE therapy_referrals
    ADD COLUMN assigned_therapist_user_id INT UNSIGNED NULL
        AFTER referred_by_user_id,
    ADD COLUMN review_status ENUM('pending', 'accepted', 'flagged')
        NOT NULL DEFAULT 'pending' AFTER status,
    ADD COLUMN flagged_reason TEXT NULL AFTER review_status,
    ADD COLUMN accepted_at DATETIME NULL AFTER flagged_reason,
    ADD COLUMN completed_at DATETIME NULL AFTER accepted_at,
    ADD COLUMN outcome TEXT NULL AFTER completed_at,
    ADD COLUMN discharge_notes TEXT NULL AFTER outcome,
    ADD UNIQUE KEY uq_therapy_referrals_source_consultation (source_consultation_id),
    ADD KEY idx_therapy_referrals_assignment (assigned_therapist_user_id, status),
    ADD CONSTRAINT fk_therapy_referrals_therapist
        FOREIGN KEY (assigned_therapist_user_id) REFERENCES users (user_id);

ALTER TABLE therapy_sessions
    ADD COLUMN activities_performed TEXT NULL AFTER session_notes,
    ADD COLUMN observations TEXT NULL AFTER activities_performed,
    ADD COLUMN patient_response TEXT NULL AFTER observations,
    ADD COLUMN progress_notes TEXT NULL AFTER patient_response,
    ADD COLUMN challenges TEXT NULL AFTER progress_notes,
    ADD COLUMN follow_up_recommendations TEXT NULL AFTER challenges,
    ADD COLUMN session_completed TINYINT(1) NOT NULL DEFAULT 1
        AFTER follow_up_recommendations;

CREATE TABLE IF NOT EXISTS therapy_assessments (
    assessment_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    therapy_referral_id INT UNSIGNED NOT NULL,
    therapist_user_id INT UNSIGNED NOT NULL,
    relevant_history TEXT NULL,
    functional_status TEXT NOT NULL,
    assessment_findings TEXT NOT NULL,
    initial_goals TEXT NOT NULL,
    assessed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (assessment_id),
    UNIQUE KEY uq_therapy_assessments_referral (therapy_referral_id),
    KEY idx_therapy_assessments_therapist (therapist_user_id, assessed_at),
    CONSTRAINT fk_therapy_assessments_referral
        FOREIGN KEY (therapy_referral_id)
        REFERENCES therapy_referrals (therapy_referral_id),
    CONSTRAINT fk_therapy_assessments_therapist
        FOREIGN KEY (therapist_user_id) REFERENCES users (user_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS therapy_treatment_plans (
    treatment_plan_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    therapy_referral_id INT UNSIGNED NOT NULL,
    therapist_user_id INT UNSIGNED NOT NULL,
    goals TEXT NOT NULL,
    interventions TEXT NOT NULL,
    frequency VARCHAR(150) NULL,
    duration VARCHAR(150) NULL,
    review_date DATE NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (treatment_plan_id),
    UNIQUE KEY uq_therapy_treatment_plans_referral (therapy_referral_id),
    KEY idx_therapy_treatment_plans_therapist (therapist_user_id, updated_at),
    CONSTRAINT fk_therapy_treatment_plans_referral
        FOREIGN KEY (therapy_referral_id)
        REFERENCES therapy_referrals (therapy_referral_id),
    CONSTRAINT fk_therapy_treatment_plans_therapist
        FOREIGN KEY (therapist_user_id) REFERENCES users (user_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS therapy_progress (
    progress_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    therapy_referral_id INT UNSIGNED NOT NULL,
    therapy_session_id INT UNSIGNED NULL,
    therapist_user_id INT UNSIGNED NOT NULL,
    patient_response TEXT NOT NULL,
    progress_notes TEXT NOT NULL,
    challenges TEXT NULL,
    follow_up_recommendations TEXT NULL,
    logged_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (progress_id),
    KEY idx_therapy_progress_referral (therapy_referral_id, logged_at),
    KEY idx_therapy_progress_therapist (therapist_user_id, logged_at),
    CONSTRAINT fk_therapy_progress_referral
        FOREIGN KEY (therapy_referral_id)
        REFERENCES therapy_referrals (therapy_referral_id),
    CONSTRAINT fk_therapy_progress_session
        FOREIGN KEY (therapy_session_id)
        REFERENCES therapy_sessions (therapy_session_id)
        ON DELETE SET NULL,
    CONSTRAINT fk_therapy_progress_therapist
        FOREIGN KEY (therapist_user_id) REFERENCES users (user_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS therapy_files (
    therapy_file_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    therapy_referral_id INT UNSIGNED NOT NULL,
    uploaded_by_user_id INT UNSIGNED NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    storage_key CHAR(48) NOT NULL,
    mime_type VARCHAR(100) NOT NULL,
    file_size_bytes INT UNSIGNED NOT NULL,
    uploaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (therapy_file_id),
    UNIQUE KEY uq_therapy_files_storage_key (storage_key),
    KEY idx_therapy_files_referral (therapy_referral_id, uploaded_at),
    CONSTRAINT fk_therapy_files_referral
        FOREIGN KEY (therapy_referral_id)
        REFERENCES therapy_referrals (therapy_referral_id),
    CONSTRAINT fk_therapy_files_uploader
        FOREIGN KEY (uploaded_by_user_id) REFERENCES users (user_id)
) ENGINE=InnoDB;
