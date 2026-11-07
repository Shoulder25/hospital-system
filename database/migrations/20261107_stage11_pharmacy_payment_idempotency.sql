-- Persist payment request keys so a retried submission cannot charge twice.
ALTER TABLE payments
    ADD COLUMN client_request_id CHAR(36) NULL
        AFTER is_simulated,
    ADD COLUMN client_request_hash CHAR(64) NULL
        AFTER client_request_id,
    ADD UNIQUE KEY uq_payments_client_request (client_request_id);
