# Hospital system demo script

This fictional-data script describes the expected click flow and the status
values currently stored by the application. It is not evidence that the
scenarios passed. On 2026-11-07, the then-existing PHP files passed `php -l` and
the local health endpoint returned HTTP 200. The UI scenarios were not run
because demo accounts and passwords for the required roles were unavailable.
The Emergency Nurse workflow was implemented on 2026-11-08 but still requires
the migration and an authenticated workflow test; do not describe it as passed.

For normal walk-in, school-appointment, and referral arrivals, Reception now
opens the ticket-payment panel immediately after visit creation. The existing
Cashier queue remains available as an alternative payment point.

## Preparation

1. Start Apache and MySQL in XAMPP, import `database/hosp.sql` and the required
   migrations, then open `http://localhost/hospital-system/auth/login.php`.
2. Sign in with the locally created system administrator. Use **Staff
   accounts** in the admin dashboard to create the role accounts below and
   assign locally chosen passwords. Do not write passwords into this file,
   seed data, screenshots, or source control.
3. Use fictional patient data only. Keep the patient number and visit ID for
   each scenario. Open **Visit status** and search either value after each
   workflow step.
4. Ensure the demo medicine stock and fee catalogue are populated. Use the
   configured sample amounts only; do not invent real fees or drug values.

### Suggested usernames to create

These are usernames only, not credentials. Apply the emergency nursing
migration before testing the Emergency Nurse workflow.

| Role | Username |
|---|---|
| Receptionist / OPD | `reception.demo` |
| Cashier | `cashier.demo` |
| Nurse | `nurse.demo` |
| General doctor | `doctor.demo` |
| Emergency doctor | `emergency.doctor.demo` |
| Emergency nurse | `emergency.nurse.demo` |
| Testing desk clerk | `testing.desk.demo` |
| Laboratory technician | `laboratory.demo` |
| Radiographer | `radiology.demo` |
| Cardiac technician | `cardiac.demo` |
| Resident specialist | `specialist.resident.demo` |
| Locum specialist (optional) | `specialist.locum.demo` |
| Therapist | `therapist.demo` |
| Pharmacist | `pharmacist.demo` |

No second administrator is needed. The medication-only scenario needs a patient
with an active, undispensed prescription from an earlier visit.

## Scenarios

Status values below use their database spelling; the tracker displays
underscored values as words. The steps are intended test scripts, not observed
results. The app does not set an `awaiting_doctor` visit status automatically
after test results; completed test visits remain `awaiting_tests` but become
eligible in the doctor queue. A minor-illness visit remains `awaiting_nurse`
until treatment is recorded, then changes to `awaiting_payment`.

### S1 — Normal visit, diagnosis, prescription and dispensing

1. **Receptionist:** open **Reception desk**. Search/select or register a
   fictional patient. Start a standard walk-in (not emergency or
   medication-only). Status: `awaiting_ticket_payment`.
2. **Receptionist:** record the initial ticket payment in the arrival panel.
   The cashier queue remains an alternative. Status: `awaiting_nurse`; the visit
   appears in the live Nursing queue.
3. **Nurse:** click **Start assessment**, review the patient's historical
   records, save the current assessment, choose **Straight to general doctor**,
   and submit. Another nurse cannot claim the same visit. Status:
   `awaiting_doctor`.
4. **General doctor:** open the visit, record consultation, diagnosis, and an
   active prescription. Status: `awaiting_pharmacy`.
5. **Pharmacist:** open **Pharmacy**, pay the bill, then choose **Dispense**.
   Status remains `awaiting_pharmacy` while the prescription is undispensed;
   successful dispensing sets `completed`.

Path: `awaiting_ticket_payment` → `awaiting_nurse` → `awaiting_doctor` →
`awaiting_pharmacy` → `completed`.

### S2 — Minor illness

1. **Receptionist:** start a normal walk-in. Status: `awaiting_ticket_payment`.
2. **Receptionist or cashier:** pay the ticket. Status: `awaiting_nurse`.
3. **Nurse:** start the assessment, save it, choose **Minor illness**, and
   record treatment with an available low-schedule demo medicine. During
   treatment the visit still shows `awaiting_nurse`; after treatment it shows
   `awaiting_payment`.
4. **Pharmacist:** record payment for the deferred treatment charge. Status:
   `completed` once the bill is paid.

Path: `awaiting_ticket_payment` → `awaiting_nurse` (assessment/minor
treatment) → `awaiting_payment` → `completed`.

### S3 — Nurse-ordered tests

1. **Receptionist:** start a standard visit and pay in the arrival panel
   (or use the cashier queue). Path: `awaiting_ticket_payment` →
   `awaiting_nurse`.
2. **Nurse:** start and save the assessment, choose **Tests**, and select
   supported types: blood, glucose, urine, pregnancy, pap smear, X-ray, or ECG.
   Status: `awaiting_tests`.
3. **Testing desk clerk:** route each order from **Testing centre desk**.
   Status remains `awaiting_tests`.
4. **Laboratory technician**, **Radiographer**, or **Cardiac technician**:
   open the matching order, start it, and record a final result. Status remains
   `awaiting_tests`; the visit becomes eligible in the doctor queue when all
   required results are final.
5. **General doctor:** open the ready visit and record consultation and a
   diagnosis without a prescription. Status: `completed`. With a prescription,
   expect `awaiting_pharmacy` until payment and dispensing.

Path in the status tracker: `awaiting_ticket_payment` → `awaiting_nurse` →
`awaiting_tests` (through result completion) → `completed` (diagnosis only).

### S4 — Retest keeps the previous result

1. Finish S3 through a final result and open the visit as **General doctor**.
2. Record the consultation and choose **More tests**; select the completed
   test type. A new order is linked to the prior order. Tracker status:
   `awaiting_tests`.
3. **Testing desk clerk:** route the new order. The first order/result remains
   in the doctor record.
4. The matching technician starts the retest and records a final result.
   **General doctor:** verify both results and the retest link, then record a
   diagnosis-only decision. Status: `completed`.

Path: `awaiting_tests` → `awaiting_tests` (new order and result; prior result
retained) → `completed`.

### S5 — Specialist diagnosed

1. **Receptionist → Cashier → Nurse:** create a standard visit, pay the ticket,
   assess and route to the doctor. Path ends at `awaiting_doctor`.
2. **General doctor:** record consultation and refer to a resident or locum.
   Status: `awaiting_specialist`.
3. **Specialist:** open **Specialists**, accept the assigned referral, record a
   diagnosed outcome and an active prescription. Status: `awaiting_pharmacy`.
4. **Pharmacist:** pay, then dispense. Status: `completed` after dispensing.

Path: `awaiting_ticket_payment` → `awaiting_nurse` → `awaiting_doctor` →
`awaiting_specialist` → `awaiting_pharmacy` → `completed`.

### S6 — Specialist cannot diagnose; deferred outside-referral charge

1. **Receptionist → Cashier → Nurse → General doctor:** create and route a
   standard visit as in S5. Status before referral: `awaiting_doctor`.
2. **General doctor:** refer to a resident or locum. Status:
   `awaiting_specialist`.
3. **Specialist:** accept, choose **Undiagnosed — refer outside**, and enter
   provider/charge details using the configured fee or permitted manual amount.
   Status: `awaiting_payment`; confirm the charge appears on the consolidated
   bill.
4. **Pharmacist:** take payment at the pharmacy point. With no undispensed
   prescription, status becomes `completed`.

Path: `awaiting_ticket_payment` → `awaiting_nurse` → `awaiting_doctor` →
`awaiting_specialist` → `awaiting_payment` → `completed`.

### S7 — Therapy before pharmacy

1. **Receptionist → Cashier → Nurse:** create standard visit, pay ticket, save
   assessment and route to the doctor. Status: `awaiting_doctor`.
2. **General doctor:** record diagnosis, select in-house therapy, and create an
   active prescription. Status: `awaiting_therapy`.
3. **Therapist:** open **Therapy**, record one or more sessions, then complete
   the referral. With an active prescription, status: `awaiting_pharmacy`.
4. **Pharmacist:** pay and dispense. Status: `completed` after dispensing.

Path: `awaiting_ticket_payment` → `awaiting_nurse` → `awaiting_doctor` →
`awaiting_therapy` → `awaiting_pharmacy` → `completed`.

### S8 — Emergency temporary patient and deferred payment

1. Apply `database/migrations/20261108_emergency_nursing.sql` if the database
   was not freshly imported from `database/hosp.sql`.
2. **Receptionist:** register a fictional patient and create an emergency visit.
   Expect `emergency_in_progress` with no ticket-payment step.
3. **Emergency Nurse:** open **Emergency Dashboard** → **Incoming Patients**,
   acknowledge the new visit, save a draft, then reopen **Patient Assessment**
   to resume it. Record observations and request doctor handover before
   completing the assessment.
4. **Emergency Doctor:** open the existing Emergency Department page, review
   the nursing assessment/observations and accept the pending handover.
5. **Emergency Nurse:** confirm the doctor's acceptance, record another
   observation, complete the assessment, and review it in Emergency History.
6. If separately testing existing doctor completion, finish the encounter and
   verify its existing Pharmacy routing. Do not collect an emergency ticket
   payment.

Path: Reception → `emergency_in_progress` → nurse acknowledgement and draft →
pending doctor handover → accepted handover and continued observations →
emergency history. This scenario is implemented but not yet verified end to end.

### S9 — Medication collection only

1. **Prerequisite:** use a patient with an active undispensed prescription from
   an earlier visit.
2. **Receptionist:** start a visit with **Medication collection only** enabled
   and Emergency disabled. It skips ticket payment and nurse assessment.
   Status: `awaiting_pharmacy`.
3. **Pharmacist:** open the visit, select the eligible earlier prescription,
   then click **Select and bill**. Status remains `awaiting_pharmacy`.
4. Record full pharmacy payment, then dispense. Status remains
   `awaiting_pharmacy` before dispensing and becomes `completed` after.

Path: `awaiting_pharmacy` → `awaiting_pharmacy` (prescription selected/paid) →
`completed`.

## Run results for this checkout

| Scenario | Result | Status path taken / blocker |
|---|---|---|
| S1 normal | FAIL — not run | No cashier or pharmacist demo account/password available; no authenticated UI workflow performed. |
| S2 minor illness | FAIL — not run | No cashier or pharmacist demo account/password available; no authenticated UI workflow performed. |
| S3 tests | FAIL — not run | No testing-desk or lab demo account/password available; no authenticated UI workflow performed. |
| S4 retest | FAIL — not run | No testing-desk or lab demo account/password available; no authenticated UI workflow performed. |
| S5 specialist diagnosed | FAIL — not run | No specialist or pharmacist demo account/password available; no authenticated UI workflow performed. |
| S6 specialist undiagnosed | FAIL — not run | No specialist or pharmacist demo account/password available; no authenticated UI workflow performed. |
| S7 therapy | FAIL — not run | No therapist or pharmacist demo account/password available; no authenticated UI workflow performed. |
| S8 emergency | FAIL — not run | Nurse pages, API, and doctor handover are implemented; migration and authenticated end-to-end workflow still require verification. |
| S9 medication-only | FAIL — not run | No pharmacist demo account/password available; no authenticated UI workflow performed. |

The 2026-11-07 health and syntax checks predate the Emergency Nurse changes and
do not verify the current workflow. These checks do not substitute for the UI
scenarios.
