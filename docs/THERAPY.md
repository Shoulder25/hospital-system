# Therapy module

The therapy module records in-house therapy sessions for active therapy
referrals. It reuses `therapy_referrals`, `therapy_sessions`, `diagnoses`,
`visits`, `patients`, `prescriptions`, `bills`, `bill_items`, `fee_catalog`,
`users`, and `audit_log`. The existing schema includes the session-to-referral,
session-to-therapist, and bill-item-to-session relationships. An additive
migration adds a unique client request ID to therapy sessions so retries cannot
create duplicate sessions or charges. Apply
`database/migrations/20261107_stage10_therapy_idempotency.sql` once to an
existing database; fresh imports receive the same column from
`database/hosp.sql`. This is an academic prototype; use fictional data only.

## Status transitions

- Queue: referrals in `referred`, `in_progress`, or `completed` status.
  Active referrals appear first; completed referrals remain available as
  read-only session history.
- First recorded session changes the referral to `in_progress`. Each session
  and its active `THERAPY_SESSION_DEMO` fee-catalog bill item are saved
  together. Session notes are required.
- A `client_request_id` UUID is required for session writes. Repeating the same
  request for the same therapist/referral returns the existing session and
  bill item without adding another charge.
- Complete: referral `completed`; visit becomes `awaiting_pharmacy` if an
  active prescription exists, otherwise `awaiting_payment`.
- Completion requires at least one recorded therapy session.

## API endpoints

Endpoints are provisional until reconciled with Person 3's API specification.
All endpoints require an authenticated `therapist` or
`system_administrator`; POST requests require the existing `X-CSRF-Token`.

| Method | Route | Request | Result |
|---|---|---|---|
| GET | `/api/therapy/queue` | None | Active and completed referrals, diagnosis, reason, therapy type, and prior sessions |
| GET | `/api/therapy/referrals/{id}` | None | One referral with referring clinician, consultation notes, diagnosis, visit status, and complete session history |
| POST | `/api/therapy/referrals/{id}/sessions` | `{"client_request_id":"550e8400-e29b-41d4-a716-446655440000","session_notes":"Fictional session notes"}` | Session and linked bill item; HTTP 201, or existing session on retry |
| POST | `/api/therapy/referrals/{id}/complete` | `{}` | Completed referral and next visit status |

Example successful session response:

```json
{
  "success": true,
  "message": "Therapy session recorded and billed.",
  "data": {
    "therapy_session_id": 8,
    "therapy_referral_id": 3,
    "visit_id": 25,
    "referral_status": "in_progress",
    "bill_item_id": 17,
    "bill_total": "20.00"
  }
}
```

Each visit bill item uses the active `THERAPY_SESSION_DEMO` fee entry in
`fee_catalog`. Its configured amount is a sample value, not a real tariff.
Session creation, bill item creation, total refresh, and audit entry are
transactional. Completion updates the referral, visit, and audit log in one
transaction. Session creation requires notes describing the session; the
authenticated therapist is recorded from the current session.

Apply the migration from the project root if the database already exists:

```powershell
Get-Content -Raw database\migrations\20261107_stage10_therapy_idempotency.sql |
  & C:\xampp\mysql\bin\mysql.exe -u root hospital_system
```

Set the MySQL username/password as appropriate for the local XAMPP setup.

## Browser testing

1. Start Apache and MySQL in XAMPP, then sign in as an active therapist or
   system administrator.
2. Open `http://localhost/hospital-system/frontend/therapy/`.
3. Use a doctor or specialist diagnosis with a therapy referral. Confirm the
   visit is `awaiting_therapy` and appears with its diagnosis, therapy type,
   and referral reason.
4. Record two sessions and refresh the visit bill. Confirm two bill items,
   each linked to its own therapy session.
5. Refresh/open a completed referral and confirm both sessions remain visible
   and controls are read-only.
6. Complete therapy. With no active prescription, the visit should become
   `awaiting_payment`; with an active prescription, it should become
   `awaiting_pharmacy`.

## Postman testing

1. Sign in and retain the PHP session cookie. Get the token from
   `GET /backend/index.php?route=/api/auth/me`.
2. Fetch `GET /backend/index.php?route=/api/therapy/queue`.
3. Record a session using
   `POST /backend/index.php?route=/api/therapy/referrals/{id}/sessions`,
   the session cookie, `X-CSRF-Token`, and
   `{"client_request_id":"550e8400-e29b-41d4-a716-446655440000","session_notes":"Fictional therapy session"}`.
4. Repeat the same request to confirm it returns the existing session without
   a second bill item. Use a new UUID for a genuinely new session. Retrieve
   the complete visit and session history with
   `GET /backend/index.php?route=/api/therapy/referrals/{id}`.
5. Call
   `POST /backend/index.php?route=/api/therapy/referrals/{id}/complete`
   with `{}` and the same session and CSRF header.
6. Confirm an anonymous request returns HTTP 401, a user with another role
   receives HTTP 403, and a referral without a session cannot be completed.

Queue views and therapy changes are recorded in the existing `audit_log`.
