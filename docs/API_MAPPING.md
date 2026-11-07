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
| Cardiac | `GET /api/cardiac/orders` | `GET /api/cardiac/orders` | Implemented |
| Cardiac | `POST /api/cardiac/orders` | — | Orders are created by nursing/doctor decisions; no direct create route |
| Cardiac | `POST /api/cardiac/results` | `POST /api/cardiac/results` | Implemented |
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
| Emergency | No corresponding route identified in the supplied proposal | `GET /api/emergency/queue`; `GET /api/emergency/visits/{id}`; `POST /api/emergency/visits/{id}/encounter`; `POST /api/emergency/visits/{id}/nurses`; `POST /api/emergency/visits/{id}/prescription`; `POST /api/emergency/visits/{id}/finish` | Current emergency paths are provisional |

Additional current routes include health/session helpers, admin roles/audit
log/reports, visit status, reception dashboard/organizations,
`GET /api/billing/ticket-queue` (with `/api/billing/queue` retained as an alias),
`GET /api/billing/visits/{id}` for cashier ticket-payment details, nursing and
doctor dashboards/queues, department order routing/start actions,
specialist accept/outside-referral details, therapy completion,
`GET /api/pharmacy/visits/{id}/dispensings` for dispensing history,
medication-only prescription selection, and pharmacy receipts. Pharmacy
payment requests require a client UUID for idempotent retries. See
`backend/routes/api.php` for the exact route patterns and methods.

The Therapy API also provides `GET /api/therapy/referrals/{id}` for an
authorized referral's clinical record and session history. Session writes use
a client request UUID so retries do not duplicate a session or its linked bill
item.

Doctor record reads use `GET /api/doctor/visits/{id}` and
`GET /api/doctor/visits/{id}/history`. Nursing uses
`POST /api/nursing/visits/{id}/assessment`, `/decision`, and `/minor-treatment`.
Doctor writes use `POST /api/consultations`, `/api/diagnoses`, and
`/api/prescriptions`; the diagnosis request carries the selected test,
specialist-referral, or therapy next step.

Emergency Department routes and workflow are described in `EMERGENCY.md`.
All current endpoint paths remain provisional until Person 3's specification
is reconciled.
