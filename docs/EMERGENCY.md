# Emergency Department

The Emergency Department records emergency care before payment. Reception
creates emergency visits with `is_emergency = 1` and status
`emergency_in_progress`; this module does not change Reception's registration
workflow.

## Workflow

An emergency doctor opens an active emergency visit and starts one encounter
with a triage level and stabilization notes. In that transaction, the system
records the authenticated doctor and writes an audit entry. Starting treatment
does not depend on a ticket, cashier approval, bill creation, or configured
emergency fee. When Pharmacy opens the finished visit, it adds the active
`EMERGENCY_CARE_DEMO` fee item linked to the encounter to the consolidated
bill. The Emergency Department never collects payment and does not add a ticket
fee.

The emergency doctor can assign active users with the `emergency_nurse` role.
Duplicate assignments are rejected. The doctor may also create one active
prescription through the existing doctor prescription validation and insertion
functions. It is not dispensed in the Emergency Department.

Finishing the encounter records `ended_at` and updates the visit in one
transaction:

- Active prescription present: `awaiting_pharmacy`
- No active prescription: `awaiting_payment`

Both statuses are supported by the existing Pharmacy module. Emergency care,
and any prescription charges added by Pharmacy, remain on the consolidated
visit bill for payment there.

Temporary patients are shown in the queue. Use the Reception link on the visit
to open the existing completion workflow. Reception or administrator access is
required to update patient details; the existing patient and visit are retained.

## Access and API

The page and read endpoints are available to `emergency_doctor`,
`emergency_nurse`, and `system_administrator`. Encounter creation, nurse
assignment, prescription creation, and finishing are restricted to
`emergency_doctor`. The doctor ID is taken from the authenticated session.
Every state-changing API request requires the existing `X-CSRF-Token`.

| Method | Route | Request |
|---|---|---|
| GET | `/api/emergency/queue` | Optional `search` query for patient number, name, or visit ID |
| GET | `/api/emergency/visits/{visit_id}` | None; active emergency visit and encounter details |
| POST | `/api/emergency/visits/{visit_id}/encounter` | `{"triage_level":"...","stabilization_notes":"..."}` |
| POST | `/api/emergency/visits/{visit_id}/nurses` | `{"nurse_user_id":123}` |
| POST | `/api/emergency/visits/{visit_id}/prescription` | `{"items":[{"medicine_id":1,"quantity":1,"dosage":"...","frequency":"...","duration":"...","instructions":"..."}]}` |
| POST | `/api/emergency/visits/{visit_id}/finish` | Empty JSON object |

These paths are provisional pending reconciliation with Person 3's API
specification. No database migration is required: `emergency_encounters`,
`emergency_nurses`, prescription fields, bill-item encounter linkage, and the
demo emergency fee already exist in the schema.

## Browser testing

1. Start Apache and MySQL in XAMPP and sign in with an active
   `emergency_doctor` account.
2. Register a fictional temporary patient in Reception and create an emergency
   visit. Confirm its status is `emergency_in_progress`.
3. Open Emergency Department from the dashboard. Confirm the visit is in the
   queue and temporary patients are labelled.
4. Open the visit, enter a triage level and stabilization notes, then start the
   encounter. Confirm the doctor and encounter are saved without a ticket or
   payment and treatment can continue.
5. Assign an active emergency nurse. Try assigning the same nurse again and
   confirm the duplicate is rejected.
6. Optionally select an active demo medicine and save a prescription. Confirm
   the UI says it is for Pharmacy and was not dispensed.
7. Finish the encounter. Confirm the status is `awaiting_pharmacy` with an
   active prescription, or `awaiting_payment` without one.
8. Sign in as Pharmacist and open the finished visit. Confirm its configured
   emergency charge is added to the bill at the pharmacy payment point. If the
   fee is unavailable, encounter creation and emergency care still work; the
   pharmacy cannot settle the missing charge until it is configured.
9. Confirm no ticket charge or Emergency Department payment was recorded.

## Postman testing

1. Sign in through `/backend/index.php?route=/api/auth/login` and retain the
   session cookie.
2. Call `/backend/index.php?route=/api/auth/me` using that cookie and copy
   `data.csrf_token`.
3. Call `GET /backend/index.php?route=/api/emergency/queue` with the cookie.
4. Fetch a visit using
   `GET /backend/index.php?route=/api/emergency/visits/{visit_id}`.
5. Start the encounter using
   `POST /backend/index.php?route=/api/emergency/visits/{visit_id}/encounter`,
   `Content-Type: application/json`, the session cookie, and
   `X-CSRF-Token: <token>`. Send triage level and stabilization notes.
6. Assign a nurse at `/api/emergency/visits/{visit_id}/nurses`; assign the same
   nurse a second time and expect HTTP 409.
7. To test prescribing, POST a valid active medicine item to
   `/api/emergency/visits/{visit_id}/prescription`.
8. Finish at `/api/emergency/visits/{visit_id}/finish`. Verify the response
   status is `awaiting_pharmacy` or `awaiting_payment` according to whether an
   active prescription exists.
9. Confirm anonymous requests return HTTP 401, unrelated roles return HTTP
   403, missing CSRF tokens reject state changes, and requests from other roles
   cannot create encounters, assign nurses, prescribe, or finish.

Only fictional patient data and sample/demo catalogue values should be used.
The system is an academic prototype, not a production clinical system.
