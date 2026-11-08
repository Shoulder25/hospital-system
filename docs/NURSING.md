# Nursing assessment and minor illness

The Nursing interface is split into Nursing Overview
(`frontend/nursing/index.php`), Incoming Patient Queue
(`frontend/nursing/queue.php`), Completed Assessments
(`frontend/nursing/completed.php`), Doctor Referrals
(`frontend/nursing/referrals.php`), and the visit-scoped assessment page
(`frontend/nursing/assessment.php`). All use the shared authenticated Nursing
layout and collapsible staff sidebar.

The Stage 3 nursing API is provisional until it is reconciled with Person 3's
API specification. It uses existing `visits`, `patients`,
`nurse_assessments`, `medicines`, `minor_treatment_items`, `test_orders`,
`bills`, `bill_items`, `users`, and `audit_log` tables.

## Database update

The existing assessment table did not have separate symptom fields, and its
required `decision` column prevented saving an assessment before the nurse
selected an outcome. The additive migration adds `symptoms` and
`symptom_duration`, and makes `decision` nullable until an outcome is
submitted. Existing decision values and records are retained.

The migration has been applied to the local `hospital_system` database. For
another existing database, run once from the project root:

```powershell
Get-Content -Raw database\migrations\20261107_stage3_nursing.sql |
  & C:\xampp\mysql\bin\mysql.exe -u root hospital_system
```

For a fresh import, `database/hosp.sql` already contains the updated fields.
The low-schedule medicines and their prices in the schema are explicitly
marked demo values; replace them with the approved catalogue before real use.

Visit-level nurse assignment is added by
`database/migrations/20261108_nursing_assignment.sql`. It adds the nullable
`nursing_assigned_to_user_id` and `nursing_started_at` fields, an index for the
shared queue, and a foreign key to `users`. Run this migration once against an
existing database after authenticating the MySQL command with the credentials
configured for the local XAMPP instance, for example:

```powershell
Get-Content -Raw database\migrations\20261108_nursing_assignment.sql |
  & C:\xampp\mysql\bin\mysql.exe --user=YOUR_DB_USER --password hospital_system
```

No separate assignment-history table is needed. The existing `audit_log`
stores the responsible administrator and timestamp; reassignment actions
include both the previous and new nurse IDs.

## Access and workflow

Only active users with role `nurse` or `system_administrator` can access the
nursing page and read APIs. Nurses start and manage assessments; administrators
have read/oversight access and may reassign visits which do not yet have a
saved assessment. State-changing calls require the existing `X-CSRF-Token`
header. The nurse user ID is obtained from the authenticated session. The
queue reads live visits with status `awaiting_nurse` where `is_emergency = 0`
and `medication_collection_only = 0`; each row also shows whether an approved
payment covers the full non-voided ticket charge. Visits without that approved
payment are excluded from the queue, and the start/assessment handlers enforce
the same requirement server-side. The dashboard refreshes the live queue
periodically.

Emergency and medication-collection-only patients are excluded. Nurses record
complaints, symptoms, duration, optional vital signs and notes. Input ranges
are basic data validation only; the system does not interpret measurements or
make diagnoses. A visit can have one assessment and one submitted decision;
previous assessments on earlier visits remain read-only in the history. The
current visit is scoped from its visit ID and the linked patient ID; historical
nursing assessments, doctor notes, diagnoses and prior test results are loaded
only for that same patient. The current schema has no allergy table, so no
allergy data is returned or displayed as if it had been checked.

Starting a visit locks the visit row in a transaction and assigns it to the
authenticated nurse only if it remains `awaiting_nurse` and unassigned. A
second nurse receives HTTP 409 and must refresh the queue. Assessment writes
also verify the assignment owner. System administrators can reassign an
unassessed visit to an active nurse; reassignments are audited with the prior
and new nurse IDs. A saved assessment cannot be reassigned.

| Method | Route | Access | Purpose |
|---|---|---|---|
| GET | `/api/nursing/dashboard` | Nurse, system administrator | Live queue/outcome statistics and active low-schedule medicines |
| GET | `/api/nursing/queue?search=&filter=` | Nurse, system administrator | Live eligible queue; search by name/number and filter all/unassigned/assigned/mine |
| GET | `/api/nursing/completed?search=` | Nurse, system administrator | Historical assessments with a submitted disposition and current visit status |
| GET | `/api/nursing/referrals?search=` | Nurse, system administrator | Assessments routed to the General Doctor, including current status and recorded findings |
| GET | `/api/nursing/nurses` | System administrator | List active nurses for assignment/reassignment |
| GET | `/api/nursing/visits/{id}` | Nurse, system administrator | Patient, current visit, read-only clinical history and saved assessment |
| POST | `/api/nursing/visits/{id}/start` | Nurse + CSRF | Atomically claim and start an eligible visit |
| POST | `/api/nursing/visits/{id}/reassign` | System administrator + CSRF | Assign an unassessed visit to another active nurse |
| POST | `/api/nursing/visits/{id}/assessment` | Nurse + CSRF | Save complaint, symptoms, vital signs and nursing notes |
| POST | `/api/nursing/visits/{id}/decision` | Nurse + CSRF | Record outcome and create approved test orders if selected |
| POST | `/api/nursing/visits/{id}/minor-treatment` | Nurse + CSRF | Record low-schedule treatment and add deferred charges to the visit bill |

Decision routing:

- `minor_illness`: records the decision, then waits for the minor-treatment
  endpoint. Treatment items and their charges are added to the visit's single
  bill, stock is decremented for recorded treatment, and the visit moves to
  `awaiting_payment` for the pharmacy payment point.
- `tests`: requires one or more of `blood`, `glucose`, `urine`, `pregnancy`,
  `pap_smear`, `x_ray`, or `ecg`. Creates rows in `test_orders` with the
  existing lab/radiology/cardiac department mapping and moves the visit to
  `awaiting_tests`.
- `general_doctor`: moves the visit to `awaiting_doctor`.

The migration reuses existing tables; no additional tables were added. Minor
treatment charges use the existing bill, `minor_treatment_items`, and
`bill_items` relations. Test orders use the existing `test_orders` table; no
testing-department UI was added in this stage.

## Test with XAMPP and Postman

1. Start Apache and MySQL and sign in as an active nurse or system
   administrator.
2. Open `http://localhost/hospital-system/frontend/nursing/index.php`.
  Use the Nursing sidebar to open the queue, completed assessments, and doctor
  referrals as separate pages.
3. From Reception, create a normal, school, or referral visit and record its
   initial ticket payment. Confirm it moves to `awaiting_nurse`, then appears
   in the Nursing queue with payment confirmed. Emergency and medication-only
   visits should not appear in the normal queue.
4. In Postman, use the same session cookie as login and obtain
   `data.csrf_token` from `GET /api/auth/me`. Send it in the
   `X-CSRF-Token` header on all `POST` requests.
5. Click **Start assessment** on an unassigned queue row. Confirm the row shows
   the assigned nurse and start time. Open the same visit as another nurse and
   confirm starting it is rejected with HTTP 409.
6. Open the assigned visit, review the separately labelled previous visits,
   nursing assessments, doctor notes and test results, then save the current
   assessment and choose one outcome.
   `tests` also requires a `test_types` array, for example
   `["blood","ecg"]`.
  Confirm `general_doctor` changes the status to `awaiting_doctor`, the visit
  appears in `/api/doctor/queue`, and the same referral is listed on Nursing's
  Doctor Referrals page.
7. For minor illness, first submit the `minor_illness` decision, then post:

```json
{
  "items": [
    { "medicine_id": 1, "quantity": 1 }
  ],
  "treatment_notes": "Fictional demo treatment notes"
}
```

8. An unauthenticated API request should return HTTP 401. A user with any
   other role should receive HTTP 403. A repeated assessment or decision
   should return HTTP 409.

This is an academic prototype, not a clinical system. Use fictional data only.
