-- Persist one client request ID per therapy session to make retries idempotent.
ALTER TABLE therapy_sessions
    ADD COLUMN client_request_id CHAR(36) NULL
        AFTER session_notes,
    ADD UNIQUE KEY uq_therapy_sessions_client_request (client_request_id);
