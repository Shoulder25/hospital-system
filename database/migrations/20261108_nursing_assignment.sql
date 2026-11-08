-- Add visit-level assignment and start time for the shared nursing queue.
-- Run once against an existing hospital_system database.
ALTER TABLE visits
    ADD COLUMN nursing_assigned_to_user_id INT UNSIGNED NULL AFTER status,
    ADD COLUMN nursing_started_at DATETIME NULL AFTER nursing_assigned_to_user_id,
    ADD KEY idx_visits_nursing_assignment (status, nursing_assigned_to_user_id),
    ADD CONSTRAINT fk_visits_nursing_assignee
        FOREIGN KEY (nursing_assigned_to_user_id) REFERENCES users (user_id)
        ON DELETE SET NULL;
