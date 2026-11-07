# General doctor module

The doctor module follows the existing three-tier application structure:
embedded-PHP presentation, JSON APIs in the central backend, and the shared
MySQL database. The doctor page is
[`frontend/doctor/index.php`](../frontend/doctor/index.php); it contains its
own HTML. JavaScript and styling are kept in separate asset files.

## Database update

The original schema stored basic consultation notes and diagnosis text on
`consultations`, but did not have structured diagnosis records or separate
consultation observations/examination fields. Therapy referrals also had no
therapy type or diagnosis association, and prescriptions were not linked to
their consultation and diagnosis. The additive migration adds these
relationships and creates `diagnoses`. It copies existing consultation
decisions into the new table and does not drop or recreate existing tables.

The migration has been applied to the local `hospital_system` database. Run
it once on an existing database from the project root:

```powershell
Get-Content -Raw database\migrations\20261107_stage4_doctor.sql |
  & C:\xampp\mysql\bin\mysql.exe -u root hospital_system
```

Fresh imports use the synchronized `database/hosp.sql`.

## Access and workflow

Only active `general_doctor` and `system_administrator` accounts may access
the doctor page or APIs. Administrative APIs still independently require the
administrator role. State-changing endpoints require the existing
`X-CSRF-Token` header. The clinician is taken from the authenticated session.

The queue includes `awaiting_doctor` visits and `awaiting_tests` visits only
when no test orders remain pending and at least one final/amended result is
available. Medication-collection-only visits are excluded. Test results are
read from the shared `test_orders`, `test_results`, and `users` tables; the
doctor module does not create or alter results.

| Method | Route | Access | Purpose |
|---|---|---|---|
| GET | `/api/doctor/dashboard` | General doctor, system administrator | Live queue counts, active medicines and specialists |
| GET | `/api/doctor/queue?search=` | General doctor, system administrator | Eligible consultation queue |
| GET | `/api/doctor/visits/{id}` | General doctor, system administrator | Patient, visit, nursing assessment, test results and clinical history |
| GET | `/api/doctor/visits/{id}/history` | General doctor, system administrator | Previous visits and diagnoses |
| POST | `/api/consultations` | General doctor, system administrator + CSRF | Save a consultation against the authenticated clinician |
| POST | `/api/diagnoses` | General doctor, system administrator + CSRF | Record a diagnosis or route for more tests / specialist referral |
| POST | `/api/prescriptions` | General doctor, system administrator + CSRF | Create a prescription linked to the visit, consultation and diagnosis |

When the doctor orders more tests, only `blood`, `glucose`, `urine`,
`pregnancy`, `pap_smear`, `x_ray`, and `ecg` are accepted. Orders are appended
to the existing visit; retests link to the most recent order of the same
type. Specialist referrals must target an active `resident_specialist` or
`locum_specialist`. Therapy referrals are linked to the diagnosis and record
the supplied therapy type and reason.

Prescriptions require an active medicine and a diagnosis linked to the
consultation. This module creates prescription records only; it does not
dispense medicines or add payment approvals. A prescription routes the visit
to `awaiting_pharmacy`, where the pharmacy/payment workflow remains
responsible for authorization before dispensing.

## XAMPP and Postman tests

1. Start Apache and MySQL and sign in with an active general doctor account
   (or a system administrator for development).
2. Open `http://localhost/hospital-system/frontend/doctor/index.php`.
3. Use a visit in `awaiting_doctor`, or a visit whose test orders are
   completed and have a final result.
4. In Postman, retain the PHP session cookie and get `data.csrf_token` from
   `GET /api/auth/me`. Send it as `X-CSRF-Token` on `POST` requests.
5. Save a consultation, then submit a diagnosis with one of:
   - `outcome: "diagnosis"` and optional `prescription_items` and/or therapy
     referral fields.
   - `outcome: "more_tests"` and a `test_types` list.
   - `outcome: "specialist_referral"` with an active specialist user ID and
     referral reason.
6. Anonymous requests should receive HTTP 401; users without the doctor or
   administrator role should receive HTTP 403. Invalid test types, medicines,
   or specialists should be rejected without creating partial clinical
   records.

Example consultation request:

```json
{
  "visit_id": 25,
  "presenting_complaint": "Fictional demo complaint",
  "clinical_observations": "Fictional demo observations",
  "examination_findings": "Fictional demo examination",
  "notes": "Fictional consultation notes"
}
```

For a decision, the diagnosis request also includes `consultation_id`,
`diagnosis_made`, and `outcome`. A test request uses
`"diagnosis_made": false`, `"outcome": "more_tests"`, and
`"test_types": ["blood"]`. A diagnosis request uses
`"diagnosis_made": true`, `"outcome": "diagnosis"`, a
`diagnosis_description`, and a `treatment_plan`; optional prescription items
are passed in `prescription_items`. Therapy referrals use
`refer_for_therapy`, `therapy_type`, and `therapy_reason`.

This is an academic prototype, not a clinical system. Use fictional data
only. The existing schema does not yet include outside-referral handling in
the doctor interface; specialist referral is the implemented route for this
stage.
