# API mapping to Person 3's proposed paths

Person 3's `System Design and Architecture` document marks its endpoint list as
proposed. The current paths are also provisional; this table compares them but
does not treat the proposal as final. Routes are dispatched through
`backend/index.php` and `backend/routes/api.php`.

| Module | Person 3 proposal | Current implementation | Mapping |
|---|---|---|---|
| Authentication | `POST /api/login` | `POST /api/auth/login` | Implemented; different path |
| Authentication | `POST /api/logout` | `POST /api/auth/logout` | Implemented; different path |
| Administration | `GET /api/users` | `GET /api/admin/users` | Implemented; admin-only, different path |
| Administration | `POST /api/users` | `POST /api/admin/users` | Implemented; admin-only, different path/contract |
| Administration | `PUT /api/users/{id}` | `PATCH /api/admin/users/{id}/status` | Status-only; general update not implemented |
| Administration | — | `GET/POST /api/admin/fees`; `PATCH /api/admin/fees/{id}` | Fee-catalog management; administrator-only |
| Reception | `POST /api/patients` | `POST /api/reception/patients` | Implemented; different path |
| Reception | `GET /api/patients` | `GET /api/reception/patients` | Implemented; different path |
| Reception | `GET /api/patients/{id}` | `GET /api/reception/patients/{id}` | Implemented; different path |
| Reception | `PUT /api/patients/{id}` | `PATCH /api/reception/patients/{id}` | Implemented as partial update |
| Reception | `POST /api/visits` | `POST /api/reception/visits` | Implemented; different path |
| Reception | `GET /api/visits/{id}` | — | No general detail endpoint; `GET /api/visits/status?search=...` returns status/timeline only |
| Nursing | `POST /api/assessments` | `POST /api/nursing/visits/{id}/assessment` | Implemented; visit ID in path |
| Nursing | `GET /api/visits/{id}/assessments` | `GET /api/nursing/visits/{id}` | Includes assessment/history; response differs |
| General doctor | `POST /api/consultations` | `POST /api/consultations` | Implemented |
| General doctor | `POST /api/diagnoses` | `POST /api/diagnoses` | Implemented |
| General doctor | `POST /api/prescriptions` | `POST /api/prescriptions` | Implemented |
| Laboratory | `GET /api/laboratory/orders` | `GET /api/laboratory/orders` | Implemented |
| Laboratory | `POST /api/laboratory/orders` | — | Orders are created by nursing/doctor decisions; no direct create route |
| Laboratory | `POST /api/laboratory/results` | `POST /api/laboratory/results` | Implemented |
| Radiology | `GET /api/radiology/orders` | `GET /api/radiology/orders` | Implemented |
| Radiology | `POST /api/radiology/orders` | — | Orders are created by nursing/doctor decisions; no direct create route |
| Radiology | `POST /api/radiology/results` | `POST /api/radiology/results` | Implemented |
| Cardiac | `GET /api/cardiac/orders` | `GET /api/cardiac/orders` | Implemented; includes ECG attempts and private attachments |
| Cardiac | `POST /api/cardiac/orders` | — | Orders are created by nursing/doctor decisions; no direct create route |
| Cardiac | `POST /api/cardiac/results` | `POST /api/cardiac/results` | Draft and final ECG results; final submission completes the order |
| Specialist | `GET /api/specialist/referrals` | `GET /api/specialists/referrals` | Implemented; current path is plural |
| Specialist | `POST /api/specialist/referrals` | `POST /api/diagnoses` | Created as a doctor diagnosis/decision; no direct matching route |
| Specialist | `POST /api/specialist/consultations` | `POST /api/specialists/referrals/{id}/outcome` | Consultation/outcome combined |
| Specialist | `POST /api/specialist/diagnoses` | `POST /api/specialists/referrals/{id}/outcome` | Diagnosis/outcome combined |
| Therapy | `GET /api/therapy/appointments` | `GET /api/therapy/queue`; `GET /api/therapy/referrals/{id}` | Implemented; different paths |
| Therapy | `POST /api/therapy/appointments` | `POST /api/diagnoses` | Doctor diagnosis can create a therapy referral |
| Therapy | `POST /api/therapy/records` | `POST /api/therapy/referrals/{id}/sessions` | Implemented; different path |
| Pharmacy | `GET /api/prescriptions` | `GET /api/pharmacy/queue`, `GET /api/pharmacy/visits/{id}` | Visit-scoped; no global prescriptions route |
| Pharmacy | `PUT /api/prescriptions/{id}/status` | — | No direct route; prescription status changes during dispensing |
| Pharmacy | `POST /api/dispensing` | `POST /api/pharmacy/visits/{id}/dispense` | Implemented; visit-scoped |
| Billing | `GET /api/visits/{id}/bill` | `GET /api/pharmacy/visits/{id}` | Includes bill but requires pharmacy access |
| Billing | `POST /api/bills` | — | Bills are created within ticket/pharmacy/therapy workflows |
| Billing | `POST /api/payments` | `POST /api/billing/visits/{id}/ticket-payment`; `POST /api/pharmacy/visits/{id}/payments` | Separate ticket and pharmacy paths |
| Billing | `GET /api/payments/{id}` | `GET /api/billing/payments/{id}/receipt`; `GET /api/pharmacy/payments/{id}/receipt` | Role-specific receipt routes |
| Emergency | No corresponding route identified in the supplied proposal | Nurse: `GET /api/emergency/nurse/dashboard`, `/queue`, `/visits/{id}`; `POST /api/emergency/nurse/visits/{id}/acknowledge`, `/assessment`, `/observations`, `/handover`. Doctor: `GET /api/emergency/queue`, `/visits/{id}`; `POST /api/emergency/visits/{id}/accept`, `/encounter`, `/nurses`, `/prescription`, `/finish` | Current emergency paths are provisional; nursing writes are emergency-nurse-only and administrator nursing access is read-only |

Additional current routes include health/session helpers, admin roles/audit
log/reports and fee management, visit status, reception dashboard/organizations,
`GET /api/billing/ticket-queue` (with `/api/billing/queue` retained as an alias),
`GET /api/billing/visits/{id}` for cashier ticket-payment details, nursing and
doctor dashboards/queues, department order routing/start actions,
specialist accept/outside-referral details, therapy completion,
`GET /api/pharmacy/visits/{id}/dispensings` for dispensing history,
medication-only prescription selection, and pharmacy receipts. Pharmacy
payment requests require a client UUID for idempotent retries. See
`backend/routes/api.php` for the exact route patterns and methods.

Billing provides cashier itemization at `GET /api/billing/visits/{id}/bill`,
`POST /api/billing/bills/{id}/items` for explicitly enabled standalone catalog
fees, and `DELETE /api/billing/bills/{id}/items/{item_id}` to void eligible
unpaid cashier-added items without deleting their history. The server resolves
prices from `fee_catalog`; department-managed charges remain attached to their
existing workflows and source records.

The Therapy API also provides dashboard and outcome reports,
`GET /api/therapy/referrals/{id}` for authorized clinical history, and
accept/flag, assessment, plan, progress, session, file, and completion routes.
Therapist reads and writes are scoped to unassigned intake referrals or cases
assigned to that therapist. Referring doctors and specialists can read their
own therapy outcomes. Session writes use a client request UUID so retries do
not duplicate a session or its linked bill item. See `THERAPY.md` for the
current workflow and migration details.

Doctor record reads use `GET /api/doctor/visits/{id}` and
`GET /api/doctor/visits/{id}/history`. Nursing uses
`POST /api/nursing/visits/{id}/start` to claim an awaiting visit,
`POST /api/nursing/visits/{id}/assessment`, `/decision`, and `/minor-treatment`.
Nursing queue assignment filters are available at
`GET /api/nursing/queue?search=&filter=`; active-nurse selection is available
to administrators at `GET /api/nursing/nurses`, and visit reassignment uses
`POST /api/nursing/visits/{id}/reassign`.
Doctor writes use `POST /api/consultations`, `/api/diagnoses`, and
`/api/prescriptions`; the diagnosis request carries the selected test,
specialist-referral, or therapy next step.

Emergency Department routes and workflow are described in `EMERGENCY.md`.
All current endpoint paths remain provisional until Person 3's specification
is reconciled.

Cardiac technicians start routed ECG orders through
`POST /api/cardiac/orders/{id}/start`, can record an unsuccessful attempt with
`POST /api/cardiac/orders/{id}/examinations`, and upload/manage private ECG
files through `POST /api/cardiac/orders/{id}/files`,
`GET /api/cardiac/files/{id}`, and `DELETE /api/cardiac/files/{id}/delete`.
General doctors can open or download attachments only after a final ECG report
is submitted. File support is JPG, JPEG, PNG, and PDF; DICOM is not configured.
Existing databases must apply `database/migrations/20261108_cardiac_module.sql`.
