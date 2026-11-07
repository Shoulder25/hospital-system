-- Stage 9 links an outside referral to the specialist referral it resolves.
-- Existing outside referrals remain valid with a NULL specialist referral.
ALTER TABLE outside_referrals
    ADD COLUMN specialist_referral_id INT UNSIGNED NULL
        AFTER source_consultation_id,
    ADD UNIQUE KEY uq_outside_referrals_specialist_referral
        (specialist_referral_id),
    ADD CONSTRAINT fk_outside_referrals_specialist_referral
        FOREIGN KEY (specialist_referral_id)
        REFERENCES specialist_referrals (specialist_referral_id);
