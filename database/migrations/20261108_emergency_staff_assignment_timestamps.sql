-- Preserve recorded times for new emergency nurse assignments.
-- Existing assignments remain NULL because their original times are unknown.
ALTER TABLE emergency_nurses
    ADD COLUMN assigned_at DATETIME NULL DEFAULT NULL AFTER nurse_user_id,
    ADD KEY idx_emergency_nurses_assignment_time
        (emergency_encounter_id, assigned_at);
