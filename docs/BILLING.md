# Cashier / Ticket Billing

The cashier module records ticket fees for visits in `awaiting_ticket_payment`.
It uses the shared `fee_catalog`, `bills`, `bill_items`, `payments`, and
`audit_log` tables. No schema migration is required.

Ticket amounts are read from the active `TICKET_DEMO` fee entry; the fee is a
sample/demo value, not a real hospital tariff. Cash payments are recorded as
approved. Card and medical-aid payments are simulated approvals and are labelled
as simulated in the interface and receipt.

## Status transition

`awaiting_ticket_payment` → `awaiting_nurse`

In one database transaction the API creates or reuses the visit bill, adds the
ticket bill item if it is missing, records one approved payment and unique
receipt number, updates the bill to `paid` if the approved amount covers its
full balance (otherwise `partially_paid`), advances the visit, and writes an
audit entry. Emergency and medication-collection-only visits cannot pay the
ticket fee here. Medication-collection-only visits skip the ticket fee and
nursing; their medication charge is still expected to be paid at the pharmacy
payment point (working assumption to confirm).

## API endpoints

All endpoints require an authenticated `cashier` or `system_administrator`.
Changing requests require the existing `X-CSRF-Token` header. Amounts are
server-calculated from `fee_catalog`; clients cannot supply the charge amount.

| Method | Route | Request | Result |
|---|---|---|---|
| GET | `/api/billing/ticket-queue` | Optional `search` query (patient number, name, or visit ID) | Eligible visits and current ticket fee |
| GET | `/api/billing/visits/{visit_id}` | None | Patient, visit, source organization, status, and active ticket fee |
| POST | `/api/billing/visits/{visit_id}/ticket-payment` | `{"payment_method":"cash"}`; also accepts `card` or `medical_aid` | Payment, receipt number, bill state, and visit state |
| GET | `/api/billing/payments/{payment_id}/receipt` | None | Approved payment, bill, visit, patient, and bill items |

`GET /api/billing/queue` remains as a backwards-compatible alias for
`/api/billing/ticket-queue`.

With XAMPP, the endpoints are called using the existing entry point and `route`
query parameter. For example:

`GET http://localhost/hospital-system/backend/index.php?route=/api/billing/ticket-queue`

Successful POST response example:

```json
{
  "success": true,
  "message": "Ticket payment recorded; visit sent to nursing.",
  "data": {
    "payment_id": 41,
    "receipt_number": "RCT-20261107-A1B2C3D4E5F6",
    "bill_id": 12,
    "bill_number": "BILL-20261107-A1B2C3D4E5F6",
    "bill_total": "10.00",
    "amount": "10.00",
    "payment_method": "cash",
    "is_simulated": false,
    "payment_status": "approved",
    "bill_status": "paid",
    "visit_id": 25,
    "visit_status": "awaiting_nurse"
  }
}
```

IDs and fee amounts vary with the local database. API errors use the existing
JSON error shape and HTTP status codes. Invalid payment methods return 422;
ineligible or already-paid visits return 409; missing visits or receipts return
404. The example assumes the ticket is the bill's only item; if other charges
remain, the bill status is `partially_paid`.

## Browser testing

1. Start Apache and MySQL in XAMPP and open
   `http://localhost/hospital-system/frontend/billing/`.
2. Sign in with an active cashier account (or system administrator).
3. Create a normal walk-in, school-appointment, or referral visit at Reception.
4. Confirm the visit appears in the queue, with its source and source
   organization where present.
5. Click **Open visit**, confirm the patient, source, status, and ticket fee,
   select a payment method, and click **Take payment**. Confirm the receipt
   link appears and the visit disappears from the queue.
6. Open/print the receipt. Confirm its payment, patient, visit, and ticket
   charge details.
7. Open Nursing and confirm that visit appears in its queue.
8. Confirm emergency and medication-collection-only visits do not appear in
   Cashier. A direct payment attempt for either returns HTTP 409.

## Postman testing

1. Sign in using the existing login endpoint and retain the session cookie.
2. Call `GET /backend/index.php?route=/api/auth/me` with that cookie; copy
   `data.csrf_token`.
3. Call `GET /backend/index.php?route=/api/billing/ticket-queue` with the same cookie.
   To search, append `&search=<patient-number-or-name-or-visit-id>`.
4. Open an eligible visit using
   `GET /backend/index.php?route=/api/billing/visits/{visit_id}`.
5. Post to
   `/backend/index.php?route=/api/billing/visits/{visit_id}/ticket-payment`
   with the same cookie, `Content-Type: application/json`,
   `X-CSRF-Token: <token>`, and body `{"payment_method":"cash"}`.
6. Verify the response is HTTP 201, the bill is `paid` when the ticket settles
   the full bill or `partially_paid` while a balance remains, and the visit is
   `awaiting_nurse`. Fetch the receipt with
   `/backend/index.php?route=/api/billing/payments/{payment_id}/receipt`.
6. Repeat with `card` or `medical_aid` on separate eligible visits and confirm
   `is_simulated: true`. Try an invalid method, a completed/paid visit, an
   emergency visit, and a medication-only visit; they must not be charged.

The project is an academic prototype; payment-provider integrations are not
implemented.
