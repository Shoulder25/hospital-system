# AGENTS.md — Hospital Integrated Patient Processing System

## 1. Project purpose and scope level

Local **Hospital Integrated Patient Processing System** for the CSC411 mini-project.
It supports the patient journey: arrival/registration → assessment → tests → diagnosis/referral → prescription → dispensing → payment.

**Stack:** XAMPP (Windows), PHP, MySQL, HTML/CSS/JavaScript, JSON over HTTP via `fetch()`, Postman for API testing.

### THIS IS A SCHOOL PROJECT — FUNCTIONAL FIRST (read this before anything else)

- The project is **due soon**. Priority is a **working, demo-able end-to-end flow**, not production-grade hardening.
- **Do NOT spend effort on 100% security.** Keep security at "basic and working" level (see section 5). Do not add CSRF tokens, rate limiting, account lockout, HTTPS setup, encryption of fields, `.htaccess` hardening, or complex validation libraries.
- Do not over-engineer. No frameworks, no Composer, no ORMs, no extra services.
- Build **Must** requirements first (section 3). Only do **Should/Could** if time remains.
- A feature is "done" when it works in the browser/Postman for the normal path and the main error cases. Do not gold-plate.
- Do not claim production readiness or regulatory compliance. Mention "academic prototype" in docs.

Do not assume functionality exists until it has been implemented and tested. This
repository began as a scaffold; use the current-state note below for what is
present and what has actually been verified.

### Current implementation and verification state (2026-11-07)

The codebase contains page/API modules for administration, reception, billing,
nursing, general doctor, testing desk, laboratory, radiology, cardiac,
specialist referral, therapy, pharmacy, visit status, audit viewing and reports.
This does not mean every workflow has passed an end-to-end browser test. The
current local database has no demo accounts for several departments, and the
emergency department has no working page/controller/API workflow. Do not claim
that all modules or nine patient scenarios have been fully tested. See
`docs/DEMO.md` for the step-by-step script and validation status.

## 2. Source-of-truth documents (in `docs/source/`)

Read these before implementing or changing any workflow:

| File | What it is |
|---|---|
| `Person1_Requirements_and_Stakeholder_Analysis.docx` | Stakeholders, process steps, decisions D1–D8, business rules BR1–BR12, functional requirements FR-01 to FR-46 |
| `Person2_Process_Modelling_and_DFD.docx` | DFD Level 0/1 (7 processes, 4 data stores D1–D4) and UML class diagram |
| `Person3_System_Design_and_Architecture.docx` | System architecture and proposed API endpoint paths |
| `Team_Patient_Flowchart.jpeg` | Team's patient-processing flowchart |

Person 3's endpoint list is proposed, not final. Keep endpoint documentation
provisional and use `docs/API_MAPPING.md` to track current paths and gaps until
the team confirms the final specification.

**Precedence when sources disagree:** Person 1 requirements + flowchart win over the UML class diagram. If still unclear, ask the user; do not invent business rules.

## 3. Requirements to implement (with priority)

Priorities are the team's MoSCoW ratings from Person 1.

### Workflow rules (BR1–BR12 summary)
- Emergency patients are treated first and pay later; emergency doctor and nurses are recorded (BR1, BR2).
- Medication-collection-only patients skip ticket fee and nurse assessment and go to the pharmacy (BR3).
- All other patients pay the ticket fee before nurse assessment; school appointments and referrals also pay it (BR4).
- Nurse decides: **minor illness / tests / straight to general doctor**. Minor illness uses the low-schedule drug list only (BR5).
- Only in-house tests: **blood, glucose, urine, pregnancy, pap smear, X-ray, ECG**. Reject anything else (BR6).
- Retests create a **new order** on the same visit; previous results are kept (BR7).
- General doctor: diagnose, or order more tests, or refer to resident/locum specialist. If the specialist cannot diagnose → outside referral; patient pays for outside tests (BR8).
- Doctor/specialist may refer for in-house therapy after diagnosis, with or without a prescription (BR12).
- Deferred amounts (emergency, minor illness, outside referral) are settled at the pharmacy payment point (BR9).
- Payment by cash, card or medical aid (BR10). Medication is dispensed only if prescribed (BR11) and only after payment/medical-aid approval is recorded (FR-40).
- One bill per visit combining ticket, tests, medication, therapy and deferred charges (FR-13).

### Must-have requirements checklist
| Area | Must FRs |
|---|---|
| Arrival / reception | FR-01, FR-02, FR-03, FR-04 |
| Emergency | FR-06, FR-07, FR-08, FR-10 |
| Billing / payment | FR-12, FR-13, FR-14, FR-15 |
| Nurse | FR-18, FR-19, FR-20 |
| Testing | FR-21 to FR-26 |
| Doctor / referral / therapy | FR-27 to FR-31, FR-33 to FR-36 |
| Pharmacy | FR-37 to FR-40 |
| Integration / access | FR-42 (JSON REST API), FR-43 (login + roles), FR-44 (audit trail) |

**Should** (do after Musts): FR-05, FR-09, FR-11, FR-16, FR-17, FR-32, FR-41, FR-45.
**Could**: FR-46 (reports).

### DFD data-store mapping
Logical groupings, not necessarily four SQL tables:
- **D1** Patient / Visit / Clinical Records: patients, visits, nurse assessments, consultations, diagnoses, referrals, therapy sessions.
- **D2** Test Orders & Results.
- **D3** Prescriptions / Dispensing: prescriptions, prescription items, medicine catalogue/stock, dispensing records.
- **D4** Bills / Payments: bills, bill items, payments.

The 7 DFD processes: 1.0 arrival/registration, 2.0 emergency care/deferred charges, 3.0 visit/ticket fee/nurse assessment, 4.0 in-house tests/results, 5.0 diagnosis/referrals, 6.0 prescriptions/pharmacy dispensing, 7.0 billing/payment.

## 4. Source review notes (gaps and conflicts already found)

Follow these decisions unless the user says otherwise:

1. **Test types:** the UML lists extra tests (throat culture, urea, electrolytes). Person 1 and the flowchart allow only the 7 in-house tests. **Use the 7 only.**
2. **UML is missing entities** the requirements need: users/roles, referrals (specialist and outside), therapy referral/sessions, audit log, medicine stock/dispensing records, fee list, visit status. Add them to the schema.
3. **Emergency temporary record (FR-06, FR-11):** allow a patient/visit with minimal details (e.g. unknown name) flagged as temporary, completed later.
4. **Medication-only payment (unclear in sources):** the flowchart skips the ticket fee and goes to pharmacy; FR-40 still requires payment before dispensing. **Working assumption:** skip ticket fee + nurse, but the medication charge is billed and paid at the pharmacy. Mark as "assumption to confirm".
5. **Low-schedule drug list and fee amounts are not given** in the sources. Do not claim real values. Use a `is_low_schedule` flag on medicines and a fee table, seeded with clearly labelled **sample/demo** values.
6. **Outside referral charge:** the flowchart shows "pay for tests" deferred. Record an outside-referral item on the bill so it appears at pharmacy payment (BR9). Amount comes from the fee table or is entered manually; do not invent it.
7. **Card and medical aid (FR-16, FR-17):** no real provider integration. Use a **simulated** approval and label it "simulated" in the UI and docs.
8. **Duplicate warning (FR-03):** a simple check on national ID and/or name + date of birth is enough.
9. **Specialists:** resident and locum are both "specialist" role with a type flag (and organisation for locums if time allows, FR-32).

## 5. Security and privacy (kept deliberately light)

Required because the course requirements say so (FR-43, FR-44) or because it is nearly free:
- **Login + simple role-based access:** PHP session login; one shared helper that checks the user's role before each protected API route. Roles stored in the database.
- **Passwords:** `password_hash()` / `password_verify()`. Never store plaintext.
- **PDO prepared statements** for all queries (no string-concatenated SQL).
- **Basic server-side checks:** required fields present, IDs numeric, enums valid (e.g. test type in the 7 allowed). Keep it simple.
- **Basic audit trail:** a single `audit_log` table (user, action, patient/visit, timestamp). Insert a row on patient record views and changes. Simple helper function, no framework.
- **Do not show raw SQL errors or stack traces** in API responses; return a generic message.
- **Fictional sample data only.** No real patients, ever.

**Explicitly out of scope** (do not implement, do not nag about): CSRF tokens, rate limiting, lockouts, HTTPS, cookie hardening beyond PHP defaults, input sanitisation libraries, file-upload validation (avoid uploads entirely), encryption at rest, penetration-style hardening. If something is skipped, a one-line "not implemented (school project)" note in docs is enough.

## 6. Architecture and folders

- `backend/config/` database and app config (DB settings in one file, easy to change).
- `backend/controllers/` request handling; validate input and return JSON.
- `backend/models/` database access.
- `backend/middleware/` login and role check.
- `backend/services/` workflows spanning several tables (billing, dispensing, visit routing).
- `backend/routes/` route definitions (`api.php`).
- `backend/helpers/` JSON response, validation, audit helper.
- `frontend/<department>/` HTML pages: admin, billing, cardiac, doctor, emergency, laboratory, nursing, pharmacy, radiology, reception, specialists, therapy.
- `assets/css`, `assets/js` (shared `api.js` fetch helper), `assets/images`.
- `database/` schema SQL and seed (fictional) data.
- `docs/` setup, API notes, and `docs/source/` (source documents).

Rules: keep SQL out of HTML/JS. Do not do broad refactors. Keep structure simple; no autoloaders or frameworks unless the user approves.

## 7. Coding rules

### PHP
- PHP version that ships with the user's XAMPP; no Composer packages.
- One API entry point under `backend/`, routes defined in `backend/routes/api.php`.
- Consistent JSON: success `{"success": true, "message": "...", "data": {...}}`; error `{"success": false, "message": "...", "errors": {...}}`.
- Use sensible status codes (200, 201, 400, 401, 403, 404, 409, 500).
- Comments only where they explain a business rule.

### JavaScript / HTML
- Semantic HTML with labelled form fields.
- All API calls go through `assets/js/api.js`.
- Show loading, success and error messages; handle failed responses.
- Hiding buttons by role in the UI is fine for UX, but the backend still checks the role.

### Database
- InnoDB, primary keys, foreign keys where sensible, indexes on patient search fields and visit status.
- **Transactions** for: creating a bill with items, recording a payment, dispensing + stock update. (This is for correctness, not security.)
- Prefer status changes over deleting clinical or financial records.
- Store timestamps consistently (document the timezone, e.g. local time of the server).
- Visit status field to support FR-45 (e.g. awaiting nurse, awaiting tests, awaiting doctor, awaiting payment, completed).

### API docs
- Keep a short endpoint list in `docs/` (method, path, roles allowed, request fields, response). Mark provisional until Person 3's spec is reconciled.
- Example endpoints: `POST /api/auth/login`, `POST /api/auth/logout`, `GET /api/patients`, `POST /api/patients`, `GET /api/patients/{id}`, `POST /api/visits`, `GET /api/visits/{id}`.

## 8. Roles

- System administrator
- Receptionist/OPD
- Cashier
- Emergency doctor, emergency nurse
- Nurse
- General doctor
- Resident specialist, locum specialist
- Testing centre desk clerk
- Laboratory technician, radiographer, cardiac technician
- Therapist
- Pharmacist

Roles and permissions live in the database. Groups may combine roles if the team agrees (e.g. one "doctor" role) to save time.

## 9. Development workflow (fast and incremental)

Do not generate the whole application in one pass. Milestones, in order:

1. Inspect repo and source documents.
2. MySQL schema + fictional seed data (users, roles, medicines, fee list).
3. PDO config + connection test.
4. JSON response helper + API router.
5. Login, sessions, simple role check.
6. Login page + role-based landing pages.
7. Patient search, duplicate warning, registration, visit creation.
8. Emergency and medication-only paths.
9. Ticket fee + nurse assessment and decision.
10. Test orders, results, retests.
11. Doctor consultation, diagnosis, specialist and outside referral.
12. Therapy, prescriptions, pharmacy dispensing + stock.
13. Consolidated bill, payment (cash / simulated card / simulated medical aid), receipt.
14. Audit log, visit status, basic reports (if time).
15. End-to-end test and demo script.

For each milestone: state which files change and why, make the smallest working change, give quick Postman/browser test steps and the expected result, and update docs if routes or schema change. Do not mark anything complete until tested.

## 10. XAMPP setup

- Project path: `C:\xampp\htdocs\hospital-system`
- Frontend: `http://localhost/hospital-system/frontend/`
- API: `http://localhost/hospital-system/backend/`
- Do not assume URL rewriting is on; check Apache config before adding `.htaccess` rules.
- DB host/user/password configurable in `backend/config/`. Do not assume an empty root password; confirm with the user.

## 11. What the agent must not do

- Do not replace the hospital workflow with a generic clinic workflow.
- Do not remove: emergency, medication-only, retest, specialist referral, outside referral, therapy or deferred-payment paths.
- Do not invent fees, drug schedules, medical-aid integrations or provider APIs. Simulated approvals must be labelled "simulated".
- Do not create real credentials or real patient data.
- Do not install frameworks/packages/services without asking.
- Do not add extra security layers listed as out of scope in section 5.
- Do not make unrelated refactors.

## 12. Communication style

The user is a fourth-year CS student who writes casually and wants **practical, ready-to-use output** with short explanations. For each change give:
1. File changed and what it does.
2. Why it is needed (one or two lines).
3. How to run/test it (VS Code + XAMPP/Postman).
4. What a successful result looks like.

If a requirement is unclear or contradictory, ask instead of guessing.
