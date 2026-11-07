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
`test_results`. It creates no tables and preserves existing orders/results.
Run it once on an existing database from the project root:

```powershell
Get-Content -Raw database\migrations\20261107_stage5_testing.sql |
  & C:\xampp\mysql\bin\mysql.exe -u root hospital_system
```

Fresh imports use the synchronized `database/hosp.sql`. Configure the local
MySQL username/password as described in
[`docs/AUTHENTICATION.md`](./AUTHENTICATION.md).

## Access and workflow

Sign in before visiting a department page:

- Testing desk: `http://localhost/hospital-system/frontend/testing-desk/index.php`
- Laboratory: `http://localhost/hospital-system/frontend/laboratory/index.php`
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

The testing desk only routes orders created by nursing or the doctor. A
technician can start a routed order and save one final result. Results append to
the original order; retesting must create a new order and leaves the previous
order/result intact. Completed results are available to the doctor, who remains
responsible for interpretation and diagnosis.

## API

Use the central `backend/index.php` entry point, existing PHP session cookie,
and JSON request/response format.

| Method | Route | Access | Purpose |
|---|---|---|---|
| GET | `/api/testing-desk/orders?search=&status=` | Testing desk clerk, administrator | List existing orders, department, status and result history |
| POST | `/api/testing-desk/orders/{id}/route` | Testing desk clerk, administrator + CSRF | Record that the patient was routed to the ordered department |
| GET | `/api/laboratory/orders?search=&status=` | Laboratory technician, administrator | List laboratory orders |
| POST | `/api/laboratory/orders/{id}/start` | Laboratory technician, administrator + CSRF | Mark a routed lab order in progress |
| POST | `/api/laboratory/results` | Laboratory technician, administrator + CSRF | Record a result for a lab order |
| GET | `/api/radiology/orders?search=&status=` | Radiographer, administrator | List X-ray orders |
| POST | `/api/radiology/orders/{id}/start` | Radiographer, administrator + CSRF | Mark a routed X-ray order in progress |
| POST | `/api/radiology/results` | Radiographer, administrator + CSRF | Record an X-ray report |
| GET | `/api/cardiac/orders?search=&status=` | Cardiac technician, administrator | List ECG orders |
| POST | `/api/cardiac/orders/{id}/start` | Cardiac technician, administrator + CSRF | Mark a routed ECG order in progress |
| POST | `/api/cardiac/results` | Cardiac technician, administrator + CSRF | Record ECG findings |

`search` matches patient number/name, visit ID, or order ID. `status` may be
`ordered`, `in_progress`, `completed`, or `cancelled`.

To record a result, send:

```json
{
  "test_order_id": 25,
  "result_text": "Fictional test result",
  "notes": "Optional notes"
}
```

The order must belong to the caller's department, be desk-routed, and have
status `in_progress`. The result text is required; notes are optional. A
successful result is stored as final, marks the order completed, and returns
HTTP 201. Invalid input returns HTTP 422; missing orders return HTTP 404;
wrong-department, workflow, and duplicate-result requests are rejected.

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
