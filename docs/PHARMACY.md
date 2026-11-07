# Pharmacy, payment, and dispensing

The pharmacy module settles the consolidated visit bill and dispenses
prescribed medication after payment approval. It reuses `visits`, `patients`,
`prescriptions`, `prescription_items`, `medicines`, `dispensings`,
`dispensing_items`, `bills`, `bill_items`, `payments`,
`medical_aid_schemes`, `patient_medical_aid_memberships`, and `audit_log`.
Payment retry protection adds nullable `client_request_id` and
`client_request_hash` columns and a unique request-ID index to `payments`.
Fresh installs receive them from `database/hosp.sql`; existing databases must
apply `database/migrations/20261107_stage11_pharmacy_payment_idempotency.sql`.
This is an academic prototype, not a clinical system. Use fictional data only.

## Status and billing workflow

- The queue includes visits in `awaiting_pharmacy` or `awaiting_payment`.
- Opening an eligible visit creates/reuses its bill and adds any missing
  medication charges for active prescriptions on that visit. The bill item
  links to `prescription_item_id`, uses the prescribed quantity and current
  medicine catalogue price, and is not duplicated. Existing ticket, test,
  minor-treatment, emergency, therapy, and outside-referral items remain on
  the same bill.
- Payments can be partial. A bill becomes `partially_paid` while an approved
  balance remains and `paid` when the balance reaches zero. Cash is recorded
  as approved; card and medical-aid approvals are simulated. Medical-aid
  payments capture an active scheme and member number and create or reuse the
  patient's membership. Existing memberships are shown for convenient reuse.
- Every payment submission carries a UUID `client_request_id`. The server
  stores a hash of the normalized request and replays an identical retry
  instead of recording a second charge. Reusing the ID for a different
  payment is rejected with HTTP 409. The browser keeps an unfinished ID in
  session storage until success.
- A visit without a prescription becomes `completed` after its bill is paid.
  An active prescription keeps the visit in the pharmacy queue until
  dispensing.
- Dispensing requires active, undispensed prescription items and a zero
  balance covered by approved payments. One transaction locks stock rows,
  verifies sufficient inventory, creates dispensing records, decrements
  stock, completes the prescription, and completes the visit. Any failure
  rolls the operation back.

### Medication-collection-only assumption

Medication-only visits skip ticket payment and nursing. The pharmacist
selects an active, undispensed prescription from an earlier visit for the
same patient. Its medication items are billed on the current medication-only
visit, which is paid at the pharmacy. This is a working assumption to confirm.

## API endpoints

All endpoints are provisional until reconciled with Person 3's API
specification. They require an authenticated `pharmacist` or
`system_administrator`; state-changing requests require the existing
`X-CSRF-Token`.

| Method | Route | Request | Result |
|---|---|---|---|
| GET | `/api/pharmacy/queue?search=` | Optional patient number, name, or visit ID | Pharmacy queue |
| GET | `/api/pharmacy/visits/{id}` | None | Patient, prescriptions, grouped bill, payments, schemes, and eligible earlier prescriptions. Idempotently adds missing current-prescription charges. |
| GET | `/api/pharmacy/visits/{id}/dispensings` | None | Visit-linked dispensing history, with medicine, quantity, pharmacist, and date |
| POST | `/api/pharmacy/visits/{id}/prescription` | `{"prescription_id":12}` | Selects and bills a medication-only visit's earlier prescription |
| POST | `/api/pharmacy/visits/{id}/payments` | `{"payment_method":"cash","amount":"5.00","client_request_id":"<uuid>"}` | Approved partial/full payment and receipt number; identical retries do not duplicate payment |
| POST | `/api/pharmacy/visits/{id}/dispense` | `{}` | Dispensing records and updated stock; visit completed |
| GET | `/api/pharmacy/payments/{id}/receipt` | None | Payment, patient, visit, and consolidated bill lines |

Use the central `backend/index.php?route=...` entry point. Payment methods are
`cash`, `card`, and `medical_aid`. For medical aid include
`medical_aid_scheme_id` and `member_number`; `member_name` is optional and
defaults to the patient's name. If `amount` is omitted, the outstanding
balance is collected. Amounts above the balance, zero/negative amounts, and
invalid methods are rejected.

Example medical-aid payment:

```json
{
  "payment_method": "medical_aid",
  "amount": "10.00",
  "medical_aid_scheme_id": 1,
  "member_number": "DEMO-MEMBER-001",
  "member_name": "Fictional Demo Patient",
  "client_request_id": "71d2e356-4736-4f16-8a4c-a2d7328831b8"
}
```

Generate a new UUID for each intentional payment. Reuse that UUID only when
retrying the same request after an uncertain network result. Successful new
payments return HTTP 201; a matching retry returns HTTP 200 with the existing
payment. Both responses include the approved payment, balance, bill status,
and receipt number. The printable receipt includes the consolidated bill,
previous payments on the bill, and its current remaining balance. The receipt page is
`frontend/pharmacy/receipt.php?payment_id={id}`. Invalid input returns HTTP
422; missing records return HTTP 404; out-of-workflow, unpaid-dispensing,
insufficient-stock, or unavailable-prescription cases return HTTP 409.
Anonymous users receive HTTP 401 and other roles HTTP 403.

## Browser testing

1. Start Apache and MySQL in XAMPP, sign in as an active pharmacist, and open
   `http://localhost/hospital-system/frontend/pharmacy/`.
2. Select a visit awaiting pharmacy/payment and inspect the patient,
   prescription items and prescriber, grouped consolidated charges, active
   prescription count, total, approved payments, and balance.
3. For medication-only visits, select an earlier active prescription and
   choose **Select and bill**. Confirm there is no ticket charge.
4. Record a cash, simulated card, or simulated medical-aid payment. Partial
   payments leave the balance due; the receipt includes previous bill payments
   and the remaining balance. Refresh or retry an uncertain submission to
   confirm one payment is recorded.
5. Dispense only after the bill is fully covered. Confirm the prescription
   is completed, medicine stock drops, and the visit becomes completed.
   The visit panel shows saved dispensing history.
6. For a visit without a prescription, pay the outstanding amount and confirm
   it completes without dispensing.
7. Test a prescription with insufficient stock and confirm no stock,
   dispensing, or prescription status changes are committed.

## Postman testing

1. Sign in, retain the PHP session cookie, and obtain `data.csrf_token` from
   `GET /backend/index.php?route=/api/auth/me`.
2. Fetch `/backend/index.php?route=/api/pharmacy/queue`, then open a visit
   with `GET /backend/index.php?route=/api/pharmacy/visits/{id}`.
3. For a medication-only visit, select an ID from
   `available_prescriptions` with
   `POST /backend/index.php?route=/api/pharmacy/visits/{id}/prescription`.
4. Record partial or full payment using the payment endpoint, a UUID
   `client_request_id`, and `X-CSRF-Token`. Resubmit the identical payload to
   verify idempotency (same payment ID and receipt; no extra payment row).
   Fetch its receipt using
   `/backend/index.php?route=/api/pharmacy/payments/{payment_id}/receipt`.
5. Fetch `/backend/index.php?route=/api/pharmacy/visits/{id}/dispensings`
   and verify it is available only to pharmacist/admin accounts.
6. Try dispensing before payment (expect HTTP 409), then after full approved
   payment. Verify `dispensings`, `dispensing_items`, stock, prescription,
   bill, and visit status in the database.
7. With a prescription whose total stock is too low, verify dispensing
   returns HTTP 409 and makes no partial updates.

For an existing database, apply the payment idempotency migration once after
backing up the database:

```powershell
C:\xampp\mysql\bin\mysql.exe -u YOUR_DB_USER -p YOUR_DATABASE < database\migrations\20261107_stage11_pharmacy_payment_idempotency.sql
```

This local module uses simulated card and medical-aid approvals; no external
payment-provider integration is implemented.
