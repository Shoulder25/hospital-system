# Stage 5: Testing centre, laboratory, radiology and cardiac

These modules extend the shared backend and MySQL database. The department
pages contain embedded HTML; shared JavaScript and CSS are in the frontend
assets. This is an academic prototype, not a clinical system. Use fictional
data only. API names and payloads are provisional until reconciled with
Person 3's final specification.

## Database update

The additive migration
[`database/migrations/20261107_stage5_testing.sql`](../database/migrations/20261107_stage5_testing.sql)
adds desk-routing metadata to `test_orders` and separate notes to
`test_results`. The Laboratory specimen migration
[`database/migrations/20261108_laboratory_specimens.sql`](../database/migrations/20261108_laboratory_specimens.sql)
adds specimen collection attempts and the staff member responsible for the
latest update. Run both migrations once on an existing database from the
project root:

```powershell
Get-Content -Raw database\migrations\20261107_stage5_testing.sql |
  & C:\xampp\mysql\bin\mysql.exe -u root hospital_system
Get-Content -Raw database\migrations\20261108_laboratory_specimens.sql |
  & C:\xampp\mysql\bin\mysql.exe -u root hospital_system
```

Fresh imports use the synchronized `database/hosp.sql`. Configure the local
MySQL username/password as described in
[`docs/AUTHENTICATION.md`](./AUTHENTICATION.md).

## Access and workflow

Sign in before visiting a department page:

- Testing Desk Clerk pages:
  - Dashboard: `http://localhost/hospital-system/frontend/testing-desk/index.php`
  - Orders awaiting routing: `http://localhost/hospital-system/frontend/testing-desk/queue.php`
  - Route an order: `http://localhost/hospital-system/frontend/testing-desk/route-order.php`
  - In progress: `http://localhost/hospital-system/frontend/testing-desk/in-progress.php`
  - Completed: `http://localhost/hospital-system/frontend/testing-desk/completed.php`
  - Order details: `http://localhost/hospital-system/frontend/testing-desk/order-details.php?order_id={id}`
  - History: `http://localhost/hospital-system/frontend/testing-desk/history.php`
- Laboratory pages:
  - Dashboard: `http://localhost/hospital-system/frontend/laboratory/index.php`
  - Incoming orders: `http://localhost/hospital-system/frontend/laboratory/orders.php`
  - Pending collection: `http://localhost/hospital-system/frontend/laboratory/pending.php`
  - Specimens: `http://localhost/hospital-system/frontend/laboratory/specimens.php`
  - Processing: `http://localhost/hospital-system/frontend/laboratory/processing.php`
  - Results: `http://localhost/hospital-system/frontend/laboratory/results.php`
  - Completed: `http://localhost/hospital-system/frontend/laboratory/completed.php`
  - History: `http://localhost/hospital-system/frontend/laboratory/history.php`
  - Order details: `http://localhost/hospital-system/frontend/laboratory/order-details.php?order_id={id}`
- Radiology: `http://localhost/hospital-system/frontend/radiology/index.php`
- Cardiac: `http://localhost/hospital-system/frontend/cardiac/index.php`

The backend checks the active session and role for every page and API request.
Testing desk clerks can route existing orders; laboratory technicians can
process blood, glucose, urine, pregnancy, and pap smear orders; radiographers
can process X-ray; cardiac technicians can process ECG. System administrators
can access these modules. Other roles receive HTTP 403, and anonymous API
requests receive HTTP 401. State-changing requests require the existing
`X-CSRF-Token` header. The responsible user is taken from the session and
important actions are recorded in `audit_log`.

The Testing Desk Clerk views and routes existing valid orders, but does not
change requests, perform investigations, diagnose, or record clinical results.
The requested test determines the department: blood, glucose, urine, pregnancy
and pap smear go to Laboratory; X-ray to Radiology; ECG to Cardiac. The shared
CareBridge sidebar provides separate dashboard, routing queue, route-order,
in-progress, completed, details, and history pages.

A technician can start a routed order and save one final result. Saving the result,
completing the order, adding its linked test bill item, refreshing the bill
total, and recording the audit entry happen in one transaction. The charge is
read from the active `fee_catalog` entry for the test type: `TEST_BLOOD_DEMO`,
`TEST_GLUCOSE_DEMO`, `TEST_URINE_DEMO`, `TEST_PREGNANCY_DEMO`,
`TEST_PAP_SMEAR_DEMO`, `TEST_X_RAY_DEMO`, or `TEST_ECG_DEMO`. Retesting must
create a new order; each order receives its own charge, while previous orders,
results, and charges remain intact. Completed results are available to the
doctor, who remains responsible for interpretation and diagnosis.

For Laboratory, receiving an order creates an `awaiting_collection` specimen
attempt. The technician records the specimen type and server collection time,
then moves it through `collected` and `processing`. Unsuitable specimens require
a reason; recollection creates a new attempt and does not erase the rejected
attempt. The authenticated technician is recorded for each update. Final
results mark the current specimen and order completed and use the existing
test-fee transaction. Preliminary result entries are retained as drafts and are
not presented as completed results to the doctor. Additional authors can append
result entries to completed orders as `amended`; earlier entries remain
unchanged. This project has no separate result-verifier role.

## API

Use the central `backend/index.php` entry point, existing PHP session cookie,
and JSON request/response format.

| Method | Route | Access | Purpose |
|---|---|---|---|
| GET | `/api/testing-desk/orders?search=&status=` | Testing desk clerk, administrator | List existing orders, department, status and result history |
| POST | `/api/testing-desk/orders/{id}/route` | Testing desk clerk, administrator + CSRF | Record that the patient was routed to the ordered department |
| GET | `/api/laboratory/orders?search=&status=` | Laboratory technician, administrator | List laboratory orders |
| POST | `/api/laboratory/orders/{id}/start` | Laboratory technician, administrator + CSRF | Mark a routed lab order in progress |
| POST | `/api/laboratory/orders/{id}/specimens` | Laboratory technician, administrator + CSRF | Collect, process, reject, or start a new collection attempt |
| POST | `/api/laboratory/results` | Laboratory technician, administrator + CSRF | Append a preliminary, final, or amended lab result |
| GET | `/api/radiology/orders?search=&status=` | Radiographer, administrator | List X-ray orders |
| POST | `/api/radiology/orders/{id}/start` | Radiographer, administrator + CSRF | Mark a routed X-ray order in progress |
| POST | `/api/radiology/results` | Radiographer, administrator + CSRF | Record an X-ray report |
| GET | `/api/cardiac/orders?search=&status=` | Cardiac technician, administrator | List ECG orders |
| POST | `/api/cardiac/orders/{id}/start` | Cardiac technician, administrator + CSRF | Mark a routed ECG order in progress |
| POST | `/api/cardiac/results` | Cardiac technician, administrator + CSRF | Record ECG findings |

`search` matches patient number/name, visit ID, or order ID. `status` may be
`all`, `ordered`, `in_progress`, `completed`, or `cancelled`. The Laboratory
list also accepts `test_type` (`blood`, `glucose`, `urine`, `pregnancy`, or
`pap_smear`).

To record a result, send:

```json
{
  "test_order_id": 25,
  "result_text": "Fictional test result",
  "notes": "Optional notes",
  "result_status": "final"
}
```

For Laboratory, `result_status` may be `preliminary`, `final`, or `amended`.
Preliminary entries are append-only and leave the order in progress. A final
result requires a specimen in processing and completes the order. An amended
entry requires an existing final result and a completed order; it preserves the
existing results and does not create another test charge. Radiology and Cardiac
continue to accept final results only. Invalid input returns HTTP 422; missing
orders return HTTP 404; wrong-department, workflow, and duplicate-final
requests are rejected.

Specimen requests use the following JSON shapes:

```json
{"action": "collect", "specimen_type": "value recorded by the technician"}
{"action": "processing"}
{"action": "reject", "rejection_reason": "Reason recorded by the technician"}
{"action": "recollect"}
```

The collection timestamp is generated by the server. `recollect` is allowed
only after rejection and creates a separate specimen attempt.

## XAMPP and Postman checks

1. Start Apache and MySQL, sign in, and open the applicable department page.
2. Create role-specific test accounts through the system administrator page;
   use only fictional test records.
3. Use a valid test order created by the existing nursing/doctor workflow.
   The desk routes it, then the correct department starts it and records a
   result. Refresh the department queue to verify its completed status and
   result history.
4. In Postman, retain the PHP session cookie and get `data.csrf_token` from
   `GET /api/auth/me`. Send it as `X-CSRF-Token` on `POST` requests.
5. Verify the laboratory cannot start or submit an ECG/X-ray order, radiology
   cannot process a laboratory order, anonymous requests receive HTTP 401, and
   a receptionist receives HTTP 403.
6. Confirm the doctor can view completed results from
   `/api/doctor/visits/{id}` and that prior results remain present after a
   retest creates a new order.

Do not use these modules to diagnose patients or record real medical results.
