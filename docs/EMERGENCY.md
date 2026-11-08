# Emergency Department

The Emergency Department records emergency care before payment. Reception
creates emergency visits with `is_emergency = 1` and status
`emergency_in_progress`; these visits are immediately available in the
Emergency Nurse incoming queue. No ticket payment is required before
acknowledgement, assessment, handover, or emergency observations.

## Emergency Nurse activities

The nurse workflow has separate pages in `frontend/emergency/`:

| Page | Purpose |
|---|---|
| `index.php` | Emergency nursing dashboard and activity counts |
| `queue.php` | New emergency arrivals from Reception |
| `assessment.php` | Patient details, available history, assessment and observations |
| `active.php` | Acknowledged patients receiving emergency care |
| `handover.php` | Pending and accepted Emergency Doctor handovers |
| `history.php` | Completed encounters and previous nursing assessments |

The nurse acknowledges the patient before saving an assessment or observation.
Assessment drafts are updated in place and can be reopened. Escalation is
available before an assessment is complete. Observation entries are retained
separately. After a doctor accepts the handover, the assigned nurse can continue
recording observations while the emergency encounter remains active.
Emergency nurse assignments are timestamped when acknowledged or assigned.
The doctor handover request and acceptance times are shown separately; old
assignments with no known timestamp remain blank rather than being backdated.

The current database has no configured approved triage-priority options and
does not track doctor availability. The nurse form therefore preserves the
existing free-text triage convention, labels the missing configuration, and
does not invent options or show an unverified doctor-availability list.

Emergency Nurses use the Nurse dashboard, incoming queue, assessment, active
patients, handover, and history pages. Emergency Doctors use the separate
Doctor dashboard, awaiting-doctor queue, handover list, clinical assessment,
treatment, investigations and prescriptions, referrals, outcomes, and history
pages. Both page sets use the shared CareBridge staff sidebar. System
administrators have read-only oversight access.

## Emergency Doctor workflow

Reception-created emergency visits enter the Nurse incoming queue immediately
with status `emergency_in_progress`; they do not wait for a ticket payment. A
nurse acknowledgement creates or reuses the encounter and records the
authenticated nurse. The Doctor “Awaiting Doctor” queue contains only pending
nurse handovers. An Emergency Doctor must explicitly accept a handover before
recording clinical care; that acceptance records the authenticated doctor and
timestamp. Doctor availability is not tracked or inferred, and no doctor is
assigned automatically. Emergency treatment does not depend on a ticket,
cashier approval, bill creation, or configured emergency fee. When Pharmacy
opens a finished visit, it adds the active `EMERGENCY_CARE_DEMO` fee item linked
to the encounter to the consolidated bill. The Emergency Department never
collects payment and does not add a ticket fee.

The doctor can assign active users with the `emergency_nurse` role to an
encounter; duplicate assignments are rejected. The doctor may also create one
active prescription through the existing doctor prescription validation and
insertion functions. It is not dispensed in the Emergency Department.

Finishing an encounter records `ended_at` and updates the visit in one
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

Nursing reads allow `emergency_nurse` and read-only `system_administrator`
access. Nursing writes require the authenticated `emergency_nurse` role.
Emergency Doctor writes require `emergency_doctor`. All state-changing requests
require the existing `X-CSRF-Token`.

| Method | Route | Access / request |
|---|---|---|
| GET | `/api/emergency/nurse/dashboard` | Emergency nurse or administrator |
| GET | `/api/emergency/nurse/queue?section=incoming` | `section`: `incoming`, `active`, `handover`, or `history`; optional `search` |
| GET | `/api/emergency/nurse/visits/{visit_id}` | Patient, available previous history, assessment, and observations |
| POST | `/api/emergency/nurse/visits/{visit_id}/acknowledge` | Emergency nurse; empty JSON object; creates/reuses an encounter and assigns the authenticated nurse |
| POST | `/api/emergency/nurse/visits/{visit_id}/assessment` | Emergency nurse; `symptoms`, `blood_pressure`, `pulse`, `respiratory_rate`, `temperature`, `oxygen_saturation`, `consciousness_level`, `pain_score`, `allergies`, `visible_injuries`, `triage_priority`, `nursing_notes`, `immediate_care`, and `status` (`draft` or `complete`) |
| POST | `/api/emergency/nurse/visits/{visit_id}/observations` | Emergency nurse; observation fields and/or `nursing_notes` / `immediate_care` |
| POST | `/api/emergency/nurse/visits/{visit_id}/handover` | Emergency nurse; empty JSON object; requests doctor handover |
| GET | `/api/emergency/doctor/dashboard` | Emergency doctor or administrator; activity counts |
| GET | `/api/emergency/doctor/queue?section=queue` | `section`: `queue`, `handovers`, `active`, or `history`; optional `search` |
| GET | `/api/emergency/doctor/visits/{visit_id}` | Emergency visit, nurse assessment, assigned staff, clinical records, orders, results, prescriptions, and referrals |
| POST | `/api/emergency/doctor/visits/{visit_id}/assessment` | Emergency doctor; clinical complaint, observations, findings, and notes |
| POST | `/api/emergency/doctor/visits/{visit_id}/diagnosis` | Emergency doctor; `consultation_id`, `diagnosis_made`, diagnosis stage, description, findings, treatment plan, and notes |
| POST | `/api/emergency/doctor/visits/{visit_id}/treatment` | Emergency doctor; treatment and/or nurse instructions |
| POST | `/api/emergency/doctor/visits/{visit_id}/orders` | Emergency doctor; `test_types` limited to the seven configured in-house tests; routes to existing Lab, Radiology, and Cardiac queues |
| POST | `/api/emergency/doctor/visits/{visit_id}/referrals` | Emergency doctor; existing specialist or outside-referral records |
| POST | `/api/emergency/doctor/visits/{visit_id}/outcome` | Emergency doctor; `observation`, `admission`, `transfer`, or `discharge`; discharge sends the visit to Pharmacy/payment |
| GET | `/api/emergency/queue` | Emergency doctor, nurse, or administrator; optional `search` |
| GET | `/api/emergency/visits/{visit_id}` | Emergency visit, nurse assessment, and observations |
| POST | `/api/emergency/visits/{visit_id}/accept` | Emergency doctor; accepts a pending nurse handover |
| POST | `/api/emergency/visits/{visit_id}/encounter` | Emergency doctor; `{"triage_level":"...","stabilization_notes":"..."}` |
| POST | `/api/emergency/visits/{visit_id}/nurses` | Emergency doctor; `{"nurse_user_id":123}` |
| POST | `/api/emergency/visits/{visit_id}/prescription` | Emergency doctor; prescription item data |
| POST | `/api/emergency/visits/{visit_id}/finish` | Emergency doctor; empty JSON object |

Prescriptions use the existing medicine catalogue and are queued for Pharmacy.
Investigation orders appear in their existing department queues, and completed
test charges are added by the existing testing workflow. Emergency care and
prescription charges remain deferred until the Pharmacy payment workflow.

These paths are provisional pending reconciliation with Person 3's API
specification. On an existing database, apply
`database/migrations/20261108_emergency_nursing.sql`,
`database/migrations/20261108_emergency_doctor_workflow.sql`, and then
`database/migrations/20261108_emergency_staff_assignment_timestamps.sql` once
each. The final migration adds nurse assignment timestamps without inventing
times for existing records. `database/hosp.sql` includes the fresh-install
schema.

## Browser testing

1. Start Apache and MySQL in XAMPP, import `database/hosp.sql` or apply the
   migration above, and create active Reception, Emergency Nurse, and Emergency
   Doctor accounts with fictional details.
2. Register a fictional temporary patient in Reception and create an emergency
   visit. Confirm its status is `emergency_in_progress`, then open the Emergency
   Nurse dashboard and incoming queue. No payment step should appear.
3. Open and acknowledge the patient. Save a draft, navigate away, and reopen
   the assessment. Confirm the saved values return.
4. Record at least two observations and verify both entries remain in the
   observation history.
5. Before completing the assessment, request an Emergency Doctor handover.
   Confirm it appears as pending in the doctor's existing Emergency Department
   queue with the nursing details visible.
6. As the doctor, accept the handover. Confirm the nurse sees the accepting
   doctor and can still add an observation while the encounter is active.
7. Mark the assessment complete and open Emergency History. Confirm the saved
   assessment and previous emergency record are visible.
8. Confirm the visit was never blocked by or charged a ticket fee. Finish via
   the existing doctor workflow only if testing its existing pharmacy routing.

## Postman testing

Sign in through `/backend/index.php?route=/api/auth/login`, retain the session
cookie, and obtain `data.csrf_token` from `/backend/index.php?route=/api/auth/me`.
Use that cookie and token on each POST. As an emergency nurse, call the queue,
visit, acknowledge, assessment, observation, and handover routes above. As an
Emergency Doctor, call the existing visit read and `accept` routes. Confirm a
duplicate nurse assignment or a second handover request returns HTTP 409, and
that an unrelated role or administrator cannot write nursing data.

Only fictional patient data and sample/demo catalogue values should be used.
The system is an academic prototype, not a production clinical system.
