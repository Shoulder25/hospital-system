# Therapy module

The Therapy module uses the existing patient, visit, diagnosis, consultation,
referral, billing, and audit records. Doctor and specialist diagnosis flows
create `therapy_referrals`; the therapist module does not create duplicate
patient, visit, or referral records. New assessment, plan, progress, and private
document records are linked to the existing referral. This is an academic
prototype; use fictional data only.

## Pages

The therapist navigation uses the shared CareBridge sidebar, role permissions,
active-page state, and responsive collapse/drawer controls.

| Page | Purpose |
|---|---|
| `frontend/therapy/index.php` | Dashboard and live case totals |
| `frontend/therapy/referrals.php` | Incoming doctor and specialist referrals |
| `frontend/therapy/pending.php` | Accept or flag referrals with a reason |
| `frontend/therapy/patients.php` | Accepted, assigned, active cases |
| `frontend/therapy/assessments.php` | Record or update an initial assessment |
| `frontend/therapy/sessions.php` | Record sessions and complete eligible cases |
| `frontend/therapy/treatment-plans.php` | Create or update a treatment plan |
| `frontend/therapy/progress.php` | Record patient response, progress and challenges |
| `frontend/therapy/reports.php` | Completed case outcomes |
| `frontend/therapy/completed.php` | Read-only completed cases |
| `frontend/therapy/history.php` | Search patients, referrals and sessions |
| `frontend/therapy/referral-details.php` | Full clinical and therapy record |

Select **View case** to open a record in the current activity. **Open full
record** navigates to the patient-specific details page.

## Referral and assignment workflow

- General doctors and specialists create referrals through the existing
  diagnosis/outcome workflows. Each referral retains its source consultation,
  diagnosis, referring clinician, visit, patient and reason.
- A unique key on `source_consultation_id` plus the shared referral-creation
  helper prevents duplicate referrals from the same clinical decision.
- New referrals (`review_status = pending`) are visible in the therapist
  intake queue. Accepting one atomically assigns it to the current therapist.
  Other therapists cannot read or change an assigned case.
- Flagging assigns the case to the reviewing therapist, stores the reason and
  leaves it out of the active patient queue until the referral is reviewed.
- Assessment or session work moves the referral to `in_progress`; completion
  stores the outcome/discharge notes and moves the visit to
  `awaiting_pharmacy` (if an active prescription exists) or `awaiting_payment`.
- The referring doctor can review the therapy outcome through the doctor
  clinical record. The referring specialist sees therapy progress and outcomes
  in the specialist's referral record. A different clinician cannot use the
  therapy detail API to read the case.

## Data and billing

The additive migration adds therapist assignment/review and case outcome fields
to `therapy_referrals`, session details to `therapy_sessions`, and these linked
tables:

- `therapy_assessments`: one editable initial assessment per referral.
- `therapy_treatment_plans`: one editable current plan per referral.
- `therapy_progress`: append-only response/progress/challenge/follow-up entries.
- `therapy_files`: metadata for documents in private file storage.

Sessions are billed using the active `THERAPY_SESSION_DEMO` fee in
`fee_catalog`; its amount is a sample, not a real tariff. Session creation,
the linked bill item, total refresh, and audit entry are transactional. A
`client_request_id` UUID makes session retries idempotent.

Therapy documents are limited to PDF, JPG/JPEG, PNG, or plain text, up to 10 MB.
Files are stored outside the public web root and require an authorized,
session-backed file route to download.

## API endpoints

Endpoints are provisional until reconciled with Person 3's specification.
All therapy changes require an authenticated therapist or system administrator
and `X-CSRF-Token`. A referring general doctor, resident specialist, or locum
specialist may read only their own referral's therapy details.

| Method | Route | Request / result |
|---|---|---|
| GET | `/api/therapy/dashboard` | Live, assignment-scoped counts |
| GET | `/api/therapy/queue?scope=&search=` | Real referrals, diagnoses, referrer, assignment and workflow flags |
| GET | `/api/therapy/reports` | Assigned case counts and completed outcomes |
| GET | `/api/therapy/referrals/{id}` | Clinical context, prior visits, assessment, plan, sessions, progress and files |
| POST | `/api/therapy/referrals/{id}/accept` | Assign a new referral to the current therapist |
| POST | `/api/therapy/referrals/{id}/flag` | `{"reason":"Clarification needed"}` |
| POST | `/api/therapy/referrals/{id}/assessment` | Functional status, findings, initial goals and relevant history |
| POST | `/api/therapy/referrals/{id}/treatment-plan` | Goals, interventions, frequency, duration and review date |
| POST | `/api/therapy/referrals/{id}/sessions` | Session date/time, activities, observations, notes, response, progress, challenges, follow-up, and `client_request_id` |
| POST | `/api/therapy/referrals/{id}/progress` | Response/progress/challenges/follow-up; optional session ID |
| POST | `/api/therapy/referrals/{id}/files` | Multipart `file` upload, 10 MB maximum |
| GET | `/api/therapy/files/{id}` | Private authorized document download |
| POST | `/api/therapy/referrals/{id}/complete` | Required outcome and discharge notes |

Session writes remain idempotent. Repeating the same request for the same
therapist/referral returns the existing session and bill item without adding a
second charge. A case cannot be completed without an accepted referral, initial
assessment, treatment plan and at least one session.

## Database migrations

For an existing database, apply both migrations in order:

1. `database/migrations/20261107_stage10_therapy_idempotency.sql`
2. `database/migrations/20261108_therapy_module.sql`

They are one-time migrations. The current development database was migrated
successfully. Fresh imports from `database/hosp.sql` include both schema
changes. Use the configured database credentials in XAMPP/phpMyAdmin; do not
assume the MySQL root password is empty.

## Browser and API testing

1. Start Apache and MySQL in XAMPP and sign in as an active therapist.
2. Open `http://localhost/hospital-system/frontend/therapy/`.
3. Submit a therapy referral from a general doctor diagnosis or specialist
   outcome. Confirm the referral appears with the genuine patient ID, visit ID,
   diagnosis, instructions and source clinician.
4. Accept the referral; confirm it moves to the assigned-patient queue. Flag a
   separate test referral and confirm the reason is retained.
5. Save an assessment and plan, record one or more sessions and progress notes,
   and optionally upload a supported file.
6. Complete the case with an outcome and discharge notes. Confirm the visit
   moves to `awaiting_payment` or `awaiting_pharmacy`, and the doctor/specialist
   who referred the patient can review the report.
7. Search history by patient name/number, visit ID or referral ID. Completed
   cases remain read-only.

For Postman, sign in at `POST /backend/index.php?route=/api/auth/login` and
retain the session cookie and returned CSRF token. Use the API table above.
Expected access checks: anonymous queue request returns 401; a non-therapy role
cannot access the therapy queue or write; another therapist cannot read or
change an assigned case; the referring clinician can read the therapy report
but cannot mutate it.

Queue views, reads, changes, downloads and completion are recorded in the
existing `audit_log`.
