# Therapy module

The therapy module records in-house therapy sessions for active therapy
referrals. It reuses `therapy_referrals`, `therapy_sessions`, `diagnoses`,
`visits`, `patients`, `prescriptions`, `bills`, `bill_items`, `fee_catalog`,
`users`, and `audit_log`. The existing schema includes the session-to-referral,
session-to-therapist, and bill-item-to-session relationships, so no migration
is required. This is an academic prototype; use fictional data only.

## Status transitions

- Queue: visit `awaiting_therapy`, referral `referred` or `in_progress`.
- First recorded session changes the referral to `in_progress`. Each session
  and its `THERAPY_SESSION_DEMO` bill item are saved together.
- Complete: referral `completed`; visit becomes `awaiting_pharmacy` if an
  active prescription exists, otherwise `awaiting_payment`.
- Completion requires at least one recorded therapy session.

## API endpoints

Endpoints are provisional until reconciled with Person 3's API specification.
All endpoints require an authenticated `therapist` or
`system_administrator`; POST requests require the existing `X-CSRF-Token`.

| Method | Route | Request | Result |
|---|---|---|---|
| GET | `/api/therapy/queue` | None | Active referrals, diagnosis, reason, therapy type, and prior sessions |
| POST | `/api/therapy/referrals/{id}/sessions` | `{"session_notes":"Fictional session notes"}`; notes may be empty | Session and linked bill item; HTTP 201 |
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

Each visit bill item uses the active `THERAPY_SESSION_DEMO` fee entry. Its
amount is a sample value from `fee_catalog`, not a real tariff. Session
creation, bill item creation, total refresh, and audit entry are transactional.
Completion updates the referral, visit, and audit log in one transaction.

## Browser testing

1. Start Apache and MySQL in XAMPP, then sign in as an active therapist or
   system administrator.
2. Open `http://localhost/hospital-system/frontend/therapy/`.
3. Use a doctor or specialist diagnosis with a therapy referral. Confirm the
   visit is `awaiting_therapy` and appears with its diagnosis, therapy type,
   and referral reason.
4. Record two sessions and refresh the visit bill. Confirm two bill items,
   each linked to its own therapy session.
5. Complete therapy. With no active prescription, the visit should become
   `awaiting_payment`; with an active prescription, it should become
   `awaiting_pharmacy`.

## Postman testing

1. Sign in and retain the PHP session cookie. Get the token from
   `GET /backend/index.php?route=/api/auth/me`.
2. Fetch `GET /backend/index.php?route=/api/therapy/queue`.
3. Record a session using
   `POST /backend/index.php?route=/api/therapy/referrals/{id}/sessions`,
   the session cookie, `X-CSRF-Token`, and
   `{"session_notes":"Fictional therapy session"}`.
4. Repeat for a second session, then call
   `POST /backend/index.php?route=/api/therapy/referrals/{id}/complete`
   with `{}` and the same session and CSRF header.
5. Confirm an anonymous request returns HTTP 401, a user with another role
   receives HTTP 403, and a referral without a session cannot be completed.

Queue views and therapy changes are recorded in the existing `audit_log`.
