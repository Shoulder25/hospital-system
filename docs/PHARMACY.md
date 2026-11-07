# Pharmacy, payment, and dispensing

The pharmacy module settles the consolidated visit bill and dispenses
prescribed medication after payment approval. It reuses `visits`, `patients`,
`prescriptions`, `prescription_items`, `medicines`, `dispensings`,
`dispensing_items`, `bills`, `bill_items`, `payments`,
`medical_aid_schemes`, `patient_medical_aid_memberships`, and `audit_log`.
The existing schema includes all required relationships; no migration is
needed. This is an academic prototype, not a clinical system. Use fictional
data only.

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
  patient's membership.
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
| POST | `/api/pharmacy/visits/{id}/prescription` | `{"prescription_id":12}` | Selects and bills a medication-only visit's earlier prescription |
| POST | `/api/pharmacy/visits/{id}/payments` | `{"payment_method":"cash","amount":"5.00"}` | Approved partial/full payment and receipt number |
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
  "member_name": "Fictional Demo Patient"
}
```

Successful payment returns HTTP 201, an approved payment, balance, bill
status, and a unique receipt number. The receipt page is
`frontend/pharmacy/receipt.php?payment_id={id}`. Invalid input returns HTTP
422; missing records return HTTP 404; out-of-workflow, unpaid-dispensing,
insufficient-stock, or unavailable-prescription cases return HTTP 409.
Anonymous users receive HTTP 401 and other roles HTTP 403.

## Browser testing

1. Start Apache and MySQL in XAMPP, sign in as an active pharmacist, and open
   `http://localhost/hospital-system/frontend/pharmacy/`.
2. Select a visit awaiting pharmacy/payment and inspect the patient,
   prescription items, grouped consolidated charges, total, approved
   payments, and balance.
3. For medication-only visits, select an earlier active prescription and
   choose **Select and bill**. Confirm there is no ticket charge.
4. Record a cash, simulated card, or simulated medical-aid payment. Partial
   payments leave the balance due; the receipt link opens the printable
   receipt page.
5. Dispense only after the bill is fully covered. Confirm the prescription
   is completed, medicine stock drops, and the visit becomes completed.
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
4. Record partial or full payment using the payment endpoint and
   `X-CSRF-Token`. Fetch its receipt using
   `/backend/index.php?route=/api/pharmacy/payments/{payment_id}/receipt`.
5. Try dispensing before payment (expect HTTP 409), then after full approved
   payment. Verify `dispensings`, `dispensing_items`, stock, prescription,
   bill, and visit status in the database.
6. With a prescription whose total stock is too low, verify dispensing
   returns HTTP 409 and makes no partial updates.

This local module uses simulated card and medical-aid approvals; no external
payment-provider integration is implemented.
