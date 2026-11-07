# Visit status, audit log and reports

These are read-only additions for the academic prototype. API paths are
provisional until Person 3's API specification is reconciled. Dates use the
MySQL server's local time.

## Visit status tracker

`GET /api/visits/status?search=<patient-number-or-visit-id>` requires a signed-in
clinical or administrator role. It returns the latest visit for an exact patient
number, or the matching visit ID (numeric visit IDs take precedence), with the
current `visits.status` and a timeline built from arrival, assessments, test
orders and results, consultations, specialist/outside referrals, therapy
referrals and sessions, prescriptions, approved payments, and dispensing
records. A ticket-paid milestone appears once the approved payments recorded for
the visit cover its ticket line item.

The page is `frontend/visit-status.php`; it is linked from the staff workspace
for supported clinical roles and from the administrator dashboard. Reception
and cashier roles are not allowed by the status endpoint.

## Administrator audit log

`GET /api/admin/audit-log` is system-administrator-only. Optional query fields:

| Field | Meaning |
|---|---|
| `user` | Partial display name, username, or user ID |
| `patient` | Partial patient number, name, or patient ID |
| `from` | Inclusive start date (`YYYY-MM-DD`) |
| `to` | Inclusive end date (`YYYY-MM-DD`) |

Supply both date fields or neither. Results are newest first and limited to 500
matching entries. The dashboard viewer is read-only.

## Administrator reports

`GET /api/admin/reports` is system-administrator-only. Optional `from` and `to`
dates use the same inclusive range and must be supplied together.

The response contains:

* **Patients per day:** distinct patients with visits arriving on each date.
* **Tests by type:** non-cancelled test orders by the seven supported test types.
* **Specialist and outside referrals:** counts created in the selected range.
* **Revenue by date and payment method:** sums of approved payments only, based
  on `payments.paid_at`.

No values are fabricated or seeded by these reports. Without a date range, the
reports cover all available records.

## Quick XAMPP / browser test

1. Import or migrate `database/hosp.sql`, start Apache and MySQL, and sign in
   using an existing account.
2. From a clinical/admin account open `frontend/visit-status.php`. Search for a
   patient number or visit ID. The visit status and a timestamped timeline
   should appear; an unknown search should show a not-found message.
3. Sign in as `system_administrator` and open the admin dashboard. Filter the
   audit log by a user, patient, or date range, then refresh reports for a date
   range. Audit rows should match `audit_log`; revenue rows should include only
   approved payments.
4. As a receptionist or cashier, the status API should return HTTP 403. As a
   non-admin, the audit and report APIs should return HTTP 403.

The report endpoints and dashboard are read-only. Report totals reflect the
current database records and are intended for demonstration, not financial or
clinical reconciliation.
