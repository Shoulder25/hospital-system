# Nursing assessment and minor illness

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

## Access and workflow

Only active users with role `nurse` or `system_administrator` can access the
nursing page and APIs. State-changing calls require the existing
`X-CSRF-Token` header. The nurse user ID is obtained from the authenticated
session. The queue includes only visits with status `awaiting_nurse` where
`is_emergency = 0` and `medication_collection_only = 0`. Therefore ticket
payment must be recorded before a visit enters this queue.

Emergency and medication-collection-only patients are excluded. Nurses record
complaints, symptoms, duration, optional vital signs and notes. Input ranges
are basic data validation only; the system does not interpret measurements or
make diagnoses. A visit can have one assessment and one submitted decision;
previous assessments on earlier visits remain available in the history.

| Method | Route | Access | Purpose |
|---|---|---|---|
| GET | `/api/nursing/dashboard` | Nurse, system administrator | Live queue/outcome statistics and active low-schedule medicines |
| GET | `/api/nursing/queue?search=` | Nurse, system administrator | Eligible nursing queue; optional patient-name/number search |
| GET | `/api/nursing/visits/{id}` | Nurse, system administrator | Patient, visit, saved assessment and previous visits |
| POST | `/api/nursing/visits/{id}/assessment` | Nurse, system administrator + CSRF | Save complaint, symptoms, vital signs and nursing notes |
| POST | `/api/nursing/visits/{id}/decision` | Nurse, system administrator + CSRF | Record outcome and create approved test orders if selected |
| POST | `/api/nursing/visits/{id}/minor-treatment` | Nurse, system administrator + CSRF | Record low-schedule treatment and add deferred charges to the visit bill |

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
3. Use a visit whose status is `awaiting_nurse`; the status must only be
   assigned after ticket payment. Emergency and medication-only visits should
   not appear.
4. In Postman, use the same session cookie as login and obtain
   `data.csrf_token` from `GET /api/auth/me`. Send it in the
   `X-CSRF-Token` header on all `POST` requests.
5. Open a queue visit, save its assessment, then choose one outcome.
   `tests` also requires a `test_types` array, for example
   `["blood","ecg"]`.
6. For minor illness, first submit the `minor_illness` decision, then post:

```json
{
  "items": [
    { "medicine_id": 1, "quantity": 1 }
  ],
  "treatment_notes": "Fictional demo treatment notes"
}
```

7. An unauthenticated API request should return HTTP 401. A user with any
   other role should receive HTTP 403. A repeated assessment or decision
   should return HTTP 409.

This is an academic prototype, not a clinical system. Use fictional data only.
