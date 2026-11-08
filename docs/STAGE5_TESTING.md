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
latest update. Laboratory final results use the shared billing helpers, so the
existing billing migration
[`database/migrations/20261108_fee_management_and_cashier_services.sql`](../database/migrations/20261108_fee_management_and_cashier_services.sql)
makes the demo test fees available. Radiology uploads use
[`database/migrations/20261108_radiology_files.sql`](../database/migrations/20261108_radiology_files.sql),
which records each file against its original test order, the uploading staff
member, and any later removal. A fresh database import already creates this
table from `database/hosp.sql`; on an existing database, apply the migration
instead. Run each required
migration once on an existing database from the project root:

```powershell
Get-Content -Raw database\migrations\20261107_stage5_testing.sql |
  & C:\xampp\mysql\bin\mysql.exe --user=YOUR_DB_USER --password hospital_system
Get-Content -Raw database\migrations\20261108_laboratory_specimens.sql |
  & C:\xampp\mysql\bin\mysql.exe --user=YOUR_DB_USER --password hospital_system
Get-Content -Raw database\migrations\20261108_fee_management_and_cashier_services.sql |
  & C:\xampp\mysql\bin\mysql.exe --user=YOUR_DB_USER --password hospital_system
Get-Content -Raw database\migrations\20261108_radiology_files.sql |
  & C:\xampp\mysql\bin\mysql.exe --user=YOUR_DB_USER --password hospital_system
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
- Radiology orders: `http://localhost/hospital-system/frontend/radiology/index.php`
- Radiology image management: `http://localhost/hospital-system/frontend/radiology/imaging.php`
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

All nine Laboratory pages use the shared CareBridge sidebar with technician-
specific links, current-page highlighting, desktop collapse/mobile drawer, and
independently scrollable navigation.

For Laboratory, receiving an order creates an `awaiting_collection` specimen
attempt. The technician records the specimen type and server collection time,
then moves it through `collected` and `processing`. Unsuitable specimens require
a reason; recollection creates a new attempt and does not erase the rejected
attempt. The authenticated technician and update time are retained for each
attempt. Preliminary entries are append-only and leave the order in progress.
A final result marks the current specimen and order completed, creates the
linked test-fee item once, and records the audit event in the same transaction.
Technicians can append `amended` entries after completion without overwriting
prior results or adding another charge. The doctor visit API includes result
entries and the doctor queue treats a visit as ready only when a final or
amended result exists and all its orders are complete. No reference ranges,
clinical results, or fee amounts are supplied by this module; fee amounts come
from the existing explicitly labelled demo fee catalogue. This project has no
separate result-verifier role.

Radiographers can attach multiple JPG, JPEG, PNG, or PDF files to a routed
X-ray order while it is in progress or completed. Each file is limited to
15 MB, with at most 10 files and 40 MB per request. The server checks the
extension, detected MIME type, and image/PDF signature before storing files
under random, extension-free names. Removed files are hidden from active
views but retained in storage and in the history with the responsible staff
member recorded.

The default private storage directory is
`C:\xampp\hospital-system-private\radiology`, outside the Apache document
root. Ensure the Apache account can write there. To choose another location,
set `RADIOLOGY_STORAGE_PATH` in Apache's configuration (for example,
`SetEnv RADIOLOGY_STORAGE_PATH "C:/xampp/hospital-system-private/radiology"`)
and restart Apache; the configured directory must remain outside the document
root. In XAMPP's active `php.ini`, set `upload_max_filesize=15M`,
`post_max_size=45M`, and `max_file_uploads=10`, then restart Apache so the
per-file and combined upload limits can be reached.

The Radiology imaging page uses the shared CareBridge sidebar. It shows
files with patient, visit, and original order context; radiographers can add
files or remove incorrect uploads after confirmation. Image thumbnails open
in the in-page zoom viewer, and original documents can be opened or downloaded
through an authenticated API route. General doctors can view and download
files only after the X-ray report is submitted. Radiology report drafts and
submitted reports are append-only entries with timestamp and staff identity;
only submitted reports are exposed on the existing doctor patient-results
page. DICOM is not supported.

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
| POST | `/api/radiology/orders/{id}/files` | Radiographer, administrator + CSRF | Upload 1–10 JPG, JPEG, PNG, or PDF files (maximum 15 MB each, 40 MB combined) |
| GET | `/api/radiology/files/{id}` | Radiographer, doctor after report submission, administrator | Open an authorized file |
| GET | `/api/radiology/files/{id}?download=1` | Radiographer, doctor after report submission, administrator | Download an authorized file |
| DELETE | `/api/radiology/files/{id}/delete` | Radiographer, administrator + CSRF | Soft-remove an upload while retaining file and audit history |
| POST | `/api/radiology/results` | Radiographer, administrator + CSRF | Save a preliminary draft or submit the final X-ray report |
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
existing results and does not create another test charge. Radiology accepts
preliminary drafts and a final submitted report; Cardiac accepts final results
only. Invalid input returns HTTP 422; missing
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
3. Use a valid fictional test order created by the existing nursing/doctor
   workflow. The desk routes it, then Laboratory receives it, collects a
   specimen, rejects/recollects one attempt, and moves the new attempt through
   processing. Save a preliminary entry, then a final result; verify the order
   and specimen complete and all attempts/results remain in history.
4. In Postman, retain the PHP session cookie and get `data.csrf_token` from
   `GET /api/auth/me`. Send it as `X-CSRF-Token` on `POST` requests.
5. Verify the laboratory cannot start or submit an ECG/X-ray order, radiology
   cannot process a laboratory order, anonymous requests receive HTTP 401, and
   a receptionist receives HTTP 403.
6. Confirm the doctor can view the completed result from
   `/api/doctor/visits/{id}`. Append an amended entry and verify that the
   original final entry remains visible and the bill still has only one charge
   for that order. Create a separate retest order and confirm the original
   result and charge remain attached to the original order.
7. For Radiology, create a fictional X-ray order, route it at the Testing
   Centre, receive it as a radiographer, upload several valid images and a PDF,
   save a draft report, then submit the report. Verify the patient, visit, and
   original order context on the imaging page, open and zoom an image, download
   a file, remove one upload and confirm its history remains. In the doctor's
   existing patient results page, verify only the submitted report and active
   files are available; anonymous or unauthorized requests must not return
   file content.

Do not use these modules to diagnose patients or record real medical results.
