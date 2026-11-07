-- Stage 5 desk routing and department result notes.
-- Run once against an existing hospital_system database.
ALTER TABLE test_orders
    ADD COLUMN testing_desk_routed_at DATETIME NULL AFTER ordered_at,
    ADD COLUMN testing_desk_routed_by_user_id INT UNSIGNED NULL
        AFTER testing_desk_routed_at,
    ADD KEY idx_test_orders_desk_route (testing_desk_routed_at),
    ADD CONSTRAINT fk_test_orders_desk_routed_by
        FOREIGN KEY (testing_desk_routed_by_user_id) REFERENCES users (user_id)
        ON DELETE SET NULL;

ALTER TABLE test_results
    ADD COLUMN result_notes TEXT NULL AFTER result_text;
