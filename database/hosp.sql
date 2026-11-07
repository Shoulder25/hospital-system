-- Hospital Integrated Patient Processing System
-- MySQL / InnoDB schema with fictional demo reference data.
-- All amounts and stock below are sample values for development only.
-- Import this file in phpMyAdmin or run it with the MySQL command-line client.

CREATE DATABASE IF NOT EXISTS hospital_system
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE hospital_system;

CREATE TABLE IF NOT EXISTS roles (
    role_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    role_name VARCHAR(80) NOT NULL,
    description VARCHAR(255) NULL,
    PRIMARY KEY (role_id),
    UNIQUE KEY uq_roles_role_name (role_name)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS organizations (
    organization_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(150) NOT NULL,
    organization_type ENUM(
        'school',
        'referring_facility',
        'outside_provider',
        'locum_employer',
        'other'
    ) NOT NULL DEFAULT 'other',
    contact_details VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (organization_id),
    KEY idx_organizations_type (organization_type)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS users (
    user_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    role_id INT UNSIGNED NOT NULL,
    organization_id INT UNSIGNED NULL,
    username VARCHAR(80) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    display_name VARCHAR(150) NOT NULL,
    specialist_type ENUM('resident', 'locum') NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id),
    UNIQUE KEY uq_users_username (username),
    KEY idx_users_role (role_id),
    KEY idx_users_organization (organization_id),
    CONSTRAINT fk_users_role
        FOREIGN KEY (role_id) REFERENCES roles (role_id),
    CONSTRAINT fk_users_organization
        FOREIGN KEY (organization_id) REFERENCES organizations (organization_id)
        ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS patients (
    patient_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    patient_number VARCHAR(30) NOT NULL,
    national_id VARCHAR(50) NULL,
    first_name VARCHAR(100) NULL,
    last_name VARCHAR(100) NULL,
    date_of_birth DATE NULL,
    gender ENUM('female', 'male', 'other', 'unknown') NOT NULL DEFAULT 'unknown',
    phone VARCHAR(40) NULL,
    address VARCHAR(255) NULL,
    is_temporary TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (patient_id),
    UNIQUE KEY uq_patients_patient_number (patient_number),
    UNIQUE KEY uq_patients_national_id (national_id),
    KEY idx_patients_name_dob (last_name, first_name, date_of_birth),
    KEY idx_patients_first_name (first_name)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS visits (
    visit_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    patient_id INT UNSIGNED NOT NULL,
    arrived_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    is_emergency TINYINT(1) NOT NULL DEFAULT 0,
    medication_collection_only TINYINT(1) NOT NULL DEFAULT 0,
    visit_source ENUM('walk_in', 'school_appointment', 'referral') NOT NULL
        DEFAULT 'walk_in',
    source_organization_id INT UNSIGNED NULL,
    source_notes TEXT NULL,
    status ENUM(
        'registered',
        'emergency_in_progress',
        'awaiting_ticket_payment',
        'awaiting_nurse',
        'awaiting_tests',
        'awaiting_doctor',
        'awaiting_specialist',
        'awaiting_therapy',
        'awaiting_pharmacy',
        'awaiting_payment',
        'completed',
        'cancelled'
    ) NOT NULL DEFAULT 'registered',
    created_by_user_id INT UNSIGNED NULL,
    PRIMARY KEY (visit_id),
    KEY idx_visits_patient_arrived (patient_id, arrived_at),
    KEY idx_visits_status (status),
    KEY idx_visits_arrived_at (arrived_at),
    CONSTRAINT fk_visits_patient
        FOREIGN KEY (patient_id) REFERENCES patients (patient_id),
    CONSTRAINT fk_visits_source_organization
        FOREIGN KEY (source_organization_id)
        REFERENCES organizations (organization_id)
        ON DELETE SET NULL,
    CONSTRAINT fk_visits_created_by
        FOREIGN KEY (created_by_user_id) REFERENCES users (user_id)
        ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS emergency_encounters (
    emergency_encounter_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    visit_id INT UNSIGNED NOT NULL,
    emergency_doctor_id INT UNSIGNED NOT NULL,
    triage_level VARCHAR(40) NULL,
    stabilization_notes TEXT NULL,
    started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ended_at DATETIME NULL,
    PRIMARY KEY (emergency_encounter_id),
    UNIQUE KEY uq_emergency_encounters_visit (visit_id),
    KEY idx_emergency_encounters_doctor (emergency_doctor_id),
    CONSTRAINT fk_emergency_encounters_visit
        FOREIGN KEY (visit_id) REFERENCES visits (visit_id),
    CONSTRAINT fk_emergency_encounters_doctor
        FOREIGN KEY (emergency_doctor_id) REFERENCES users (user_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS emergency_nurses (
    emergency_encounter_id INT UNSIGNED NOT NULL,
    nurse_user_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (emergency_encounter_id, nurse_user_id),
    CONSTRAINT fk_emergency_nurses_encounter
        FOREIGN KEY (emergency_encounter_id)
        REFERENCES emergency_encounters (emergency_encounter_id),
    CONSTRAINT fk_emergency_nurses_user
        FOREIGN KEY (nurse_user_id) REFERENCES users (user_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS nurse_assessments (
    assessment_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    visit_id INT UNSIGNED NOT NULL,
    nurse_user_id INT UNSIGNED NOT NULL,
    complaint TEXT NULL,
    blood_pressure VARCHAR(20) NULL,
    pulse SMALLINT UNSIGNED NULL,
    temperature DECIMAL(5,2) NULL,
    respiratory_rate SMALLINT UNSIGNED NULL,
    oxygen_saturation DECIMAL(5,2) NULL,
    weight DECIMAL(6,2) NULL,
    height DECIMAL(6,2) NULL,
    notes TEXT NULL,
    decision ENUM('minor_illness', 'tests', 'general_doctor') NOT NULL,
    minor_treatment_notes TEXT NULL,
    assessed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (assessment_id),
    KEY idx_nurse_assessments_visit (visit_id, assessed_at),
    KEY idx_nurse_assessments_nurse (nurse_user_id),
    CONSTRAINT fk_nurse_assessments_visit
        FOREIGN KEY (visit_id) REFERENCES visits (visit_id),
    CONSTRAINT fk_nurse_assessments_nurse
        FOREIGN KEY (nurse_user_id) REFERENCES users (user_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS medicines (
    medicine_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    medicine_name VARCHAR(150) NOT NULL,
    is_low_schedule TINYINT(1) NOT NULL DEFAULT 0,
    unit_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    quantity_in_stock DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    is_demo TINYINT(1) NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    PRIMARY KEY (medicine_id),
    UNIQUE KEY uq_medicines_name (medicine_name),
    KEY idx_medicines_low_schedule (is_low_schedule, is_active)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS minor_treatment_items (
    minor_treatment_item_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    assessment_id INT UNSIGNED NOT NULL,
    medicine_id INT UNSIGNED NOT NULL,
    quantity DECIMAL(10,2) NOT NULL,
    unit_price DECIMAL(10,2) NOT NULL,
    PRIMARY KEY (minor_treatment_item_id),
    KEY idx_minor_treatment_assessment (assessment_id),
    KEY idx_minor_treatment_medicine (medicine_id),
    CONSTRAINT fk_minor_treatment_assessment
        FOREIGN KEY (assessment_id) REFERENCES nurse_assessments (assessment_id),
    CONSTRAINT fk_minor_treatment_medicine
        FOREIGN KEY (medicine_id) REFERENCES medicines (medicine_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS test_orders (
    test_order_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    visit_id INT UNSIGNED NOT NULL,
    test_type ENUM(
        'blood',
        'glucose',
        'urine',
        'pregnancy',
        'pap_smear',
        'x_ray',
        'ecg'
    ) NOT NULL,
    department ENUM('laboratory', 'radiology', 'cardiac') NOT NULL,
    ordered_by_user_id INT UNSIGNED NOT NULL,
    retest_of_order_id INT UNSIGNED NULL,
    status ENUM('ordered', 'in_progress', 'completed', 'cancelled')
        NOT NULL DEFAULT 'ordered',
    ordered_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (test_order_id),
    KEY idx_test_orders_visit (visit_id, ordered_at),
    KEY idx_test_orders_status (status),
    KEY idx_test_orders_retest_of (retest_of_order_id),
    CONSTRAINT fk_test_orders_visit
        FOREIGN KEY (visit_id) REFERENCES visits (visit_id),
    CONSTRAINT fk_test_orders_ordered_by
        FOREIGN KEY (ordered_by_user_id) REFERENCES users (user_id),
    CONSTRAINT fk_test_orders_retest_of
        FOREIGN KEY (retest_of_order_id) REFERENCES test_orders (test_order_id)
        ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS test_results (
    test_result_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    test_order_id INT UNSIGNED NOT NULL,
    recorded_by_user_id INT UNSIGNED NOT NULL,
    result_text LONGTEXT NOT NULL,
    status ENUM('preliminary', 'final', 'amended') NOT NULL DEFAULT 'final',
    recorded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (test_result_id),
    KEY idx_test_results_order (test_order_id, recorded_at),
    CONSTRAINT fk_test_results_order
        FOREIGN KEY (test_order_id) REFERENCES test_orders (test_order_id),
    CONSTRAINT fk_test_results_recorded_by
        FOREIGN KEY (recorded_by_user_id) REFERENCES users (user_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS consultations (
    consultation_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    visit_id INT UNSIGNED NOT NULL,
    clinician_user_id INT UNSIGNED NOT NULL,
    consultation_type ENUM('emergency', 'general', 'specialist') NOT NULL,
    notes TEXT NULL,
    diagnosis_made TINYINT(1) NOT NULL DEFAULT 0,
    diagnosis_text TEXT NULL,
    treatment_plan TEXT NULL,
    next_step ENUM(
        'undecided',
        'more_tests',
        'specialist_referral',
        'outside_referral',
        'prescription',
        'therapy',
        'completed'
    ) NOT NULL DEFAULT 'undecided',
    consulted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (consultation_id),
    KEY idx_consultations_visit (visit_id, consulted_at),
    KEY idx_consultations_clinician (clinician_user_id),
    CONSTRAINT fk_consultations_visit
        FOREIGN KEY (visit_id) REFERENCES visits (visit_id),
    CONSTRAINT fk_consultations_clinician
        FOREIGN KEY (clinician_user_id) REFERENCES users (user_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS specialist_referrals (
    specialist_referral_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    visit_id INT UNSIGNED NOT NULL,
    source_consultation_id INT UNSIGNED NOT NULL,
    specialist_user_id INT UNSIGNED NOT NULL,
    reason TEXT NOT NULL,
    clinical_summary TEXT NULL,
    status ENUM('pending', 'accepted', 'completed', 'cancelled')
        NOT NULL DEFAULT 'pending',
    response_consultation_id INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (specialist_referral_id),
    KEY idx_specialist_referrals_visit (visit_id, created_at),
    KEY idx_specialist_referrals_specialist (specialist_user_id, status),
    CONSTRAINT fk_specialist_referrals_visit
        FOREIGN KEY (visit_id) REFERENCES visits (visit_id),
    CONSTRAINT fk_specialist_referrals_source_consultation
        FOREIGN KEY (source_consultation_id)
        REFERENCES consultations (consultation_id),
    CONSTRAINT fk_specialist_referrals_specialist
        FOREIGN KEY (specialist_user_id) REFERENCES users (user_id),
    CONSTRAINT fk_specialist_referrals_response
        FOREIGN KEY (response_consultation_id)
        REFERENCES consultations (consultation_id)
        ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS outside_referrals (
    outside_referral_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    visit_id INT UNSIGNED NOT NULL,
    source_consultation_id INT UNSIGNED NOT NULL,
    organization_id INT UNSIGNED NULL,
    provider_name VARCHAR(150) NULL,
    clinical_summary TEXT NOT NULL,
    outside_tests_payable TINYINT(1) NOT NULL DEFAULT 1,
    status ENUM('issued', 'sent', 'completed', 'cancelled')
        NOT NULL DEFAULT 'issued',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (outside_referral_id),
    KEY idx_outside_referrals_visit (visit_id, created_at),
    CONSTRAINT fk_outside_referrals_visit
        FOREIGN KEY (visit_id) REFERENCES visits (visit_id),
    CONSTRAINT fk_outside_referrals_consultation
        FOREIGN KEY (source_consultation_id)
        REFERENCES consultations (consultation_id),
    CONSTRAINT fk_outside_referrals_organization
        FOREIGN KEY (organization_id) REFERENCES organizations (organization_id)
        ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS therapy_referrals (
    therapy_referral_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    visit_id INT UNSIGNED NOT NULL,
    source_consultation_id INT UNSIGNED NOT NULL,
    referred_by_user_id INT UNSIGNED NOT NULL,
    reason TEXT NOT NULL,
    status ENUM('referred', 'in_progress', 'completed', 'cancelled')
        NOT NULL DEFAULT 'referred',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (therapy_referral_id),
    KEY idx_therapy_referrals_visit (visit_id, created_at),
    CONSTRAINT fk_therapy_referrals_visit
        FOREIGN KEY (visit_id) REFERENCES visits (visit_id),
    CONSTRAINT fk_therapy_referrals_consultation
        FOREIGN KEY (source_consultation_id)
        REFERENCES consultations (consultation_id),
    CONSTRAINT fk_therapy_referrals_referred_by
        FOREIGN KEY (referred_by_user_id) REFERENCES users (user_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS therapy_sessions (
    therapy_session_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    therapy_referral_id INT UNSIGNED NOT NULL,
    therapist_user_id INT UNSIGNED NOT NULL,
    session_notes TEXT NULL,
    session_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (therapy_session_id),
    KEY idx_therapy_sessions_referral (therapy_referral_id, session_at),
    KEY idx_therapy_sessions_therapist (therapist_user_id),
    CONSTRAINT fk_therapy_sessions_referral
        FOREIGN KEY (therapy_referral_id)
        REFERENCES therapy_referrals (therapy_referral_id),
    CONSTRAINT fk_therapy_sessions_therapist
        FOREIGN KEY (therapist_user_id) REFERENCES users (user_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS prescriptions (
    prescription_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    visit_id INT UNSIGNED NOT NULL,
    prescribed_by_user_id INT UNSIGNED NOT NULL,
    status ENUM('draft', 'active', 'completed', 'cancelled', 'expired')
        NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (prescription_id),
    KEY idx_prescriptions_visit (visit_id, created_at),
    KEY idx_prescriptions_status (status),
    CONSTRAINT fk_prescriptions_visit
        FOREIGN KEY (visit_id) REFERENCES visits (visit_id),
    CONSTRAINT fk_prescriptions_prescribed_by
        FOREIGN KEY (prescribed_by_user_id) REFERENCES users (user_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS prescription_items (
    prescription_item_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    prescription_id INT UNSIGNED NOT NULL,
    medicine_id INT UNSIGNED NOT NULL,
    dosage VARCHAR(100) NULL,
    frequency VARCHAR(100) NULL,
    duration VARCHAR(100) NULL,
    quantity DECIMAL(10,2) NOT NULL,
    instructions TEXT NULL,
    PRIMARY KEY (prescription_item_id),
    KEY idx_prescription_items_prescription (prescription_id),
    KEY idx_prescription_items_medicine (medicine_id),
    CONSTRAINT fk_prescription_items_prescription
        FOREIGN KEY (prescription_id) REFERENCES prescriptions (prescription_id),
    CONSTRAINT fk_prescription_items_medicine
        FOREIGN KEY (medicine_id) REFERENCES medicines (medicine_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS fee_catalog (
    fee_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    fee_code VARCHAR(60) NOT NULL,
    description VARCHAR(180) NOT NULL,
    default_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    is_demo TINYINT(1) NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    PRIMARY KEY (fee_id),
    UNIQUE KEY uq_fee_catalog_code (fee_code)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS bills (
    bill_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    visit_id INT UNSIGNED NOT NULL,
    bill_number VARCHAR(40) NOT NULL,
    total_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    status ENUM('draft', 'pending', 'partially_paid', 'paid', 'cancelled')
        NOT NULL DEFAULT 'draft',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (bill_id),
    UNIQUE KEY uq_bills_visit (visit_id),
    UNIQUE KEY uq_bills_number (bill_number),
    KEY idx_bills_status (status),
    CONSTRAINT fk_bills_visit
        FOREIGN KEY (visit_id) REFERENCES visits (visit_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS bill_items (
    bill_item_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    bill_id INT UNSIGNED NOT NULL,
    fee_id INT UNSIGNED NULL,
    charge_type ENUM(
        'ticket',
        'test',
        'medication',
        'therapy',
        'minor_treatment',
        'emergency',
        'outside_referral',
        'other'
    ) NOT NULL,
    description VARCHAR(255) NOT NULL,
    quantity DECIMAL(10,2) NOT NULL DEFAULT 1.00,
    unit_amount DECIMAL(10,2) NOT NULL,
    test_order_id INT UNSIGNED NULL,
    prescription_item_id INT UNSIGNED NULL,
    minor_treatment_item_id INT UNSIGNED NULL,
    therapy_session_id INT UNSIGNED NULL,
    outside_referral_id INT UNSIGNED NULL,
    emergency_encounter_id INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (bill_item_id),
    KEY idx_bill_items_bill (bill_id),
    KEY idx_bill_items_test_order (test_order_id),
    KEY idx_bill_items_prescription_item (prescription_item_id),
    KEY idx_bill_items_minor_treatment (minor_treatment_item_id),
    KEY idx_bill_items_therapy_session (therapy_session_id),
    KEY idx_bill_items_outside_referral (outside_referral_id),
    KEY idx_bill_items_emergency_encounter (emergency_encounter_id),
    CONSTRAINT fk_bill_items_bill
        FOREIGN KEY (bill_id) REFERENCES bills (bill_id),
    CONSTRAINT fk_bill_items_fee
        FOREIGN KEY (fee_id) REFERENCES fee_catalog (fee_id)
        ON DELETE SET NULL,
    CONSTRAINT fk_bill_items_test_order
        FOREIGN KEY (test_order_id) REFERENCES test_orders (test_order_id)
        ON DELETE SET NULL,
    CONSTRAINT fk_bill_items_prescription_item
        FOREIGN KEY (prescription_item_id)
        REFERENCES prescription_items (prescription_item_id)
        ON DELETE SET NULL,
    CONSTRAINT fk_bill_items_minor_treatment
        FOREIGN KEY (minor_treatment_item_id)
        REFERENCES minor_treatment_items (minor_treatment_item_id)
        ON DELETE SET NULL,
    CONSTRAINT fk_bill_items_therapy_session
        FOREIGN KEY (therapy_session_id)
        REFERENCES therapy_sessions (therapy_session_id)
        ON DELETE SET NULL,
    CONSTRAINT fk_bill_items_outside_referral
        FOREIGN KEY (outside_referral_id)
        REFERENCES outside_referrals (outside_referral_id)
        ON DELETE SET NULL,
    CONSTRAINT fk_bill_items_emergency_encounter
        FOREIGN KEY (emergency_encounter_id)
        REFERENCES emergency_encounters (emergency_encounter_id)
        ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS medical_aid_schemes (
    medical_aid_scheme_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    scheme_name VARCHAR(150) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    PRIMARY KEY (medical_aid_scheme_id),
    UNIQUE KEY uq_medical_aid_scheme_name (scheme_name)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS patient_medical_aid_memberships (
    membership_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    patient_id INT UNSIGNED NOT NULL,
    medical_aid_scheme_id INT UNSIGNED NOT NULL,
    member_number VARCHAR(100) NOT NULL,
    member_name VARCHAR(150) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    PRIMARY KEY (membership_id),
    KEY idx_memberships_patient (patient_id, is_active),
    KEY idx_memberships_scheme (medical_aid_scheme_id),
    CONSTRAINT fk_memberships_patient
        FOREIGN KEY (patient_id) REFERENCES patients (patient_id),
    CONSTRAINT fk_memberships_scheme
        FOREIGN KEY (medical_aid_scheme_id)
        REFERENCES medical_aid_schemes (medical_aid_scheme_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS payments (
    payment_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    bill_id INT UNSIGNED NOT NULL,
    recorded_by_user_id INT UNSIGNED NULL,
    membership_id INT UNSIGNED NULL,
    payment_method ENUM('cash', 'card', 'medical_aid') NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    status ENUM('pending', 'approved', 'rejected', 'failed', 'refunded')
        NOT NULL DEFAULT 'pending',
    external_reference VARCHAR(150) NULL,
    receipt_number VARCHAR(50) NULL,
    is_simulated TINYINT(1) NOT NULL DEFAULT 0,
    paid_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (payment_id),
    UNIQUE KEY uq_payments_receipt_number (receipt_number),
    KEY idx_payments_bill (bill_id, status),
    KEY idx_payments_membership (membership_id),
    CONSTRAINT fk_payments_bill
        FOREIGN KEY (bill_id) REFERENCES bills (bill_id),
    CONSTRAINT fk_payments_recorded_by
        FOREIGN KEY (recorded_by_user_id) REFERENCES users (user_id)
        ON DELETE SET NULL,
    CONSTRAINT fk_payments_membership
        FOREIGN KEY (membership_id)
        REFERENCES patient_medical_aid_memberships (membership_id)
        ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS dispensings (
    dispensing_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    visit_id INT UNSIGNED NOT NULL,
    prescription_id INT UNSIGNED NOT NULL,
    pharmacist_user_id INT UNSIGNED NOT NULL,
    payment_id INT UNSIGNED NULL,
    dispensed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (dispensing_id),
    KEY idx_dispensings_visit (visit_id, dispensed_at),
    KEY idx_dispensings_prescription (prescription_id),
    KEY idx_dispensings_pharmacist (pharmacist_user_id),
    KEY idx_dispensings_payment (payment_id),
    CONSTRAINT fk_dispensings_visit
        FOREIGN KEY (visit_id) REFERENCES visits (visit_id),
    CONSTRAINT fk_dispensings_prescription
        FOREIGN KEY (prescription_id) REFERENCES prescriptions (prescription_id),
    CONSTRAINT fk_dispensings_pharmacist
        FOREIGN KEY (pharmacist_user_id) REFERENCES users (user_id),
    CONSTRAINT fk_dispensings_payment
        FOREIGN KEY (payment_id) REFERENCES payments (payment_id)
        ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS dispensing_items (
    dispensing_item_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    dispensing_id INT UNSIGNED NOT NULL,
    prescription_item_id INT UNSIGNED NOT NULL,
    medicine_id INT UNSIGNED NOT NULL,
    quantity_dispensed DECIMAL(10,2) NOT NULL,
    unit_price DECIMAL(10,2) NOT NULL,
    PRIMARY KEY (dispensing_item_id),
    KEY idx_dispensing_items_dispensing (dispensing_id),
    KEY idx_dispensing_items_prescription_item (prescription_item_id),
    KEY idx_dispensing_items_medicine (medicine_id),
    CONSTRAINT fk_dispensing_items_dispensing
        FOREIGN KEY (dispensing_id) REFERENCES dispensings (dispensing_id),
    CONSTRAINT fk_dispensing_items_prescription_item
        FOREIGN KEY (prescription_item_id)
        REFERENCES prescription_items (prescription_item_id),
    CONSTRAINT fk_dispensing_items_medicine
        FOREIGN KEY (medicine_id) REFERENCES medicines (medicine_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS audit_log (
    audit_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT UNSIGNED NULL,
    patient_id INT UNSIGNED NULL,
    visit_id INT UNSIGNED NULL,
    action VARCHAR(100) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (audit_id),
    KEY idx_audit_log_patient_time (patient_id, created_at),
    KEY idx_audit_log_visit_time (visit_id, created_at),
    KEY idx_audit_log_user_time (user_id, created_at),
    CONSTRAINT fk_audit_log_user
        FOREIGN KEY (user_id) REFERENCES users (user_id)
        ON DELETE SET NULL,
    CONSTRAINT fk_audit_log_patient
        FOREIGN KEY (patient_id) REFERENCES patients (patient_id)
        ON DELETE SET NULL,
    CONSTRAINT fk_audit_log_visit
        FOREIGN KEY (visit_id) REFERENCES visits (visit_id)
        ON DELETE SET NULL
) ENGINE=InnoDB;

-- Role catalogue. User accounts are intentionally not seeded: setup must
-- create accounts with password_hash() rather than provide demo credentials.
INSERT IGNORE INTO roles (role_name, description) VALUES
    ('system_administrator', 'Manages system users and configuration'),
    ('receptionist', 'Searches and registers patients and visits'),
    ('cashier', 'Records ticket-fee payments and receipts'),
    ('emergency_doctor', 'Records emergency treatment and prescriptions'),
    ('emergency_nurse', 'Records emergency nursing care'),
    ('nurse', 'Assesses patients and records minor illness care'),
    ('general_doctor', 'Reviews results, diagnoses, orders tests and referrals'),
    ('resident_specialist', 'Provides resident specialist consultations'),
    ('locum_specialist', 'Provides locum specialist consultations'),
    ('testing_desk_clerk', 'Creates and routes in-house test orders'),
    ('laboratory_technician', 'Records laboratory test results'),
    ('radiographer', 'Records X-ray results'),
    ('cardiac_technician', 'Records ECG results'),
    ('therapist', 'Records referred therapy sessions'),
    ('pharmacist', 'Processes pharmacy payments and dispensing');

-- Fictional organizations for development and demonstrations only.
INSERT IGNORE INTO organizations
    (name, organization_type, contact_details) VALUES
    ('DEMO - Example School', 'school', 'Fictional demo organization'),
    ('DEMO - Example Referring Facility', 'referring_facility',
        'Fictional demo organization'),
    ('DEMO - Example Outside Provider', 'outside_provider',
        'Fictional demo organization'),
    ('DEMO - Example Locum Agency', 'locum_employer',
        'Fictional demo organization');

-- DEMO ONLY: replace these examples with an approved medicine catalogue.
-- Prices and quantities are not real inventory or clinical guidance.
INSERT IGNORE INTO medicines
    (medicine_name, is_low_schedule, unit_price, quantity_in_stock, is_demo) VALUES
    ('DEMO - Low-schedule example medicine (replace before use)',
        1, 1.00, 20.00, 1),
    ('DEMO - General example medicine (replace before use)',
        0, 1.50, 20.00, 1);

-- DEMO ONLY: sample fees, not real hospital prices. Configure approved
-- amounts before using the system for any non-demo purpose.
INSERT IGNORE INTO fee_catalog
    (fee_code, description, default_amount, is_demo) VALUES
    ('TICKET_DEMO', 'DEMO - Ticket fee', 10.00, 1),
    ('TEST_BLOOD_DEMO', 'DEMO - Blood test', 5.00, 1),
    ('TEST_GLUCOSE_DEMO', 'DEMO - Glucose test', 5.00, 1),
    ('TEST_URINE_DEMO', 'DEMO - Urine test', 5.00, 1),
    ('TEST_PREGNANCY_DEMO', 'DEMO - Pregnancy test', 5.00, 1),
    ('TEST_PAP_SMEAR_DEMO', 'DEMO - Pap smear test', 5.00, 1),
    ('TEST_X_RAY_DEMO', 'DEMO - X-ray', 8.00, 1),
    ('TEST_ECG_DEMO', 'DEMO - ECG', 8.00, 1),
    ('THERAPY_SESSION_DEMO', 'DEMO - Therapy session', 10.00, 1),
    ('OUTSIDE_TESTS_MANUAL', 'Outside tests - enter amount manually',
        0.00, 1),
    ('EMERGENCY_CARE_DEMO', 'DEMO - Emergency care charge', 10.00, 1),
    ('MINOR_TREATMENT_DEMO', 'DEMO - Minor illness treatment charge',
        5.00, 1);

INSERT IGNORE INTO medical_aid_schemes (scheme_name, is_active) VALUES
    ('DEMO - Example Medical Aid Scheme', 1);
