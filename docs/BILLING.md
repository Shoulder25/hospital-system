# Cashier / Ticket Billing

The cashier UI is split into four authenticated pages: Billing Overview
(`frontend/billing/index.php`), Find Patient / Visit
(`frontend/billing/find.php`), Initial Ticket Payments
(`frontend/billing/ticket-payments.php`), and Recent Payments
(`frontend/billing/recent-payments.php`). They share the Billing layout and
sidebar; receipts remain at `frontend/billing/receipt.php`.

The cashier module records ticket fees for visits in `awaiting_ticket_payment`.
It uses the shared `fee_catalog`, `bills`, `bill_items`, `payments`, and
`audit_log` tables. Existing databases must apply
`database/migrations/20261108_fee_management_and_cashier_services.sql`;
fresh installs receive the same fields from `database/hosp.sql`.

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

The ticket queue is restricted to authenticated `cashier` and
`system_administrator` users. Ticket-visit details, ticket-payment creation,
and receipt viewing also allow `receptionist` so initial payment can be
completed inside the Reception arrival flow. Changing requests require the
existing `X-CSRF-Token` header. Amounts are server-calculated from
`fee_catalog`; clients cannot supply the charge amount. The recorded staff
member is always taken from the authenticated session.

## Fee management and cashier services

Only a `system_administrator` can use `frontend/admin/fees.php` or the
`/api/admin/fees` endpoints. Fee codes are unique and cannot be deleted through
the interface; deactivate them instead. The updater and timestamp are stored
on each fee and changes are written to `audit_log`. The catalog currency is
stored as an ISO currency code (`SZL` by default, matching the existing `E`
display); the billing UI formats prices using the currency returned by the API.

Cashiers see active catalog prices as read-only. A service can be selected in a
visit bill only if an administrator explicitly enables its standalone cashier
addition. Workflow-managed fee codes (ticket, test, therapy, medication, minor
treatment, emergency, and outside referral) cannot be marked for cashier
addition and remain charged by their existing department workflows. The
backend retrieves the current fee amount and description; browser-submitted
prices are ignored. Charges store the unit amount at creation, so fee updates
do not change historical bills.

Cashier add requests use a unique client request ID for safe retries. Direct
cashier-added items can be voided only before any approved payment; voiding
retains the bill item, timestamp, actor and audit entry. Department-managed
items cannot be removed through billing. Bill totals, paid amounts and balances
are calculated from non-voided bill items and approved payments.

For general cashier bill payments, medical aid has a clearly labelled simulated
outcome (`medical_aid_outcome: "approved"` or `"rejected"`). A rejected attempt
is retained with a unique reference and audit entry but does not reduce the
outstanding balance or produce an approved-payment receipt. Retrying the same
client request ID returns the existing result and current balance. This is a
local demonstration control, not a medical-aid provider integration. Receipt
line items are limited to charges recorded by the payment timestamp.

| Method | Route | Access | Request / result |
|---|---|---|---|
| GET | `/api/admin/fees?search=...` | System administrator | Fee catalog, status, currency, last update and updater |
| POST | `/api/admin/fees` | System administrator + CSRF | `fee_code`, `description`, `amount`, `is_active`, `cashier_addable` |
| PATCH | `/api/admin/fees/{fee_id}` | System administrator + CSRF | Updates description, configured amount and status; fee code is immutable |
| POST | `/api/billing/bills/{bill_id}/items` | Cashier or system administrator + CSRF | `fee_id`, `quantity`, `client_request_id`; backend selects price |
| DELETE | `/api/billing/bills/{bill_id}/items/{item_id}` | Cashier or system administrator + CSRF | Voids an eligible direct cashier-added item; no financial row is deleted |
| GET | `/api/billing/visits/{visit_id}/bill` | Cashier or system administrator | Bill items, active cashier-addable services and current balance |

| Method | Route | Request | Result |
|---|---|---|---|
| GET | `/api/billing/ticket-queue` | Optional `search` query (patient number, name, or visit ID) | Eligible visits and current ticket fee |
| GET | `/api/billing/visits/{visit_id}` | Cashier, receptionist, administrator | Patient, visit, source organization, status, and active ticket fee |
| POST | `/api/billing/visits/{visit_id}/ticket-payment` | Cashier, receptionist, administrator + CSRF; `{"payment_method":"cash"}`; also accepts `card` or `medical_aid` | Payment, receipt number, bill state, and visit state |
| GET | `/api/billing/payments/{payment_id}/receipt` | Cashier, receptionist, administrator | Approved payment, bill, visit, patient, and bill items |

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
   `http://localhost/hospital-system/frontend/billing/` as a cashier (or
   system administrator), or open Reception arrivals as a receptionist.
2. Create a normal walk-in, school-appointment, or referral visit at Reception.
   Reception shows the ticket payment panel immediately; the cashier can
   continue using the existing ticket-payment queue.
3. Confirm the visit appears in the cashier queue, with its source and source
   organization where present.
4. Click **Open visit** in Cashier (or use the Reception panel), confirm the
   patient, source, status, and ticket fee,
   select a payment method, and click **Take payment**. Confirm the receipt
   link appears and the visit disappears from the queue.
5. Open/print the receipt. Confirm its payment, patient, visit, and ticket
   charge details.
6. Open Nursing and confirm that visit appears in its queue.
7. Confirm emergency and medication-collection-only visits do not appear in
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
