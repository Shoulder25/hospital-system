# Billing and Staff Sidebar Audit

Audit date: 2026-11-08

## Scope and evidence

The working tree is on branch `main`, at `3b324cb` (`origin/main` is aligned). There are many unstaged and untracked changes across the backend, database, documentation, and frontend. They were present before this task; none were reverted. Billing, fee management, sidebar assets, and staff page wiring already exist in those changes.

This audit reflects source inspection only. The existing `BILLING.md` and `RECEPTION.md` contain manual test instructions, not evidence that those tests were run. No browser pages were shared, and no database/API test has yet been run in this task. PHP language-server checks found no blocking route/controller errors, but reported existing diagnostics in the billing and admin controllers; these are not proof of runtime behavior.

## Existing implementation inventory

- Billing: `backend/controllers/billing.php`, `backend/helpers/billing.php`, `backend/routes/api.php`, `backend/middleware/billing_page.php`, `frontend/billing/index.php`, `frontend/billing/receipt.php`, `frontend/assets/js/billing.js`, and `frontend/assets/css/billing.css`.
- Fee administration: `frontend/admin/fees.php`, `frontend/assets/css/fees.css`, `frontend/assets/js/admin.js`, admin fee handlers in `backend/controllers/admin.php`, and fee routes in `backend/routes/api.php`.
- Fee schema: `fee_catalog`, one-bill-per-visit uniqueness, bill item snapshots and cashier request IDs, payment status and idempotency fields are present in `database/hosp.sql`. Existing-database changes are in `database/migrations/20261108_fee_management_and_cashier_services.sql`.
- Sidebar behavior: `frontend/assets/js/staff-sidebar.js` and `frontend/assets/css/staff-layout.css`. The assets are linked by the staff page layouts listed in the checklist below. Reception's eight routes render through `frontend/reception/workspace.php`.
- Relevant current docs: `docs/BILLING.md`, `docs/RECEPTION.md`, and `docs/API_MAPPING.md`.

The repository-wide status includes additional modified and untracked clinical/reception files unrelated to this audit. They remain untouched unless a directly required integration is identified.

## Requirement status before this task's implementation changes

| Requirement | Status | Source evidence / gap |
|---|---|---|
| Search patient number, name, or visit ID and show visit-specific bill totals/status | IMPLEMENTED BUT NOT VERIFIED | `/api/billing/patients` joins visits to their unique bill and calculates non-voided charges less approved payments; billing UI renders the result. No API/browser data test was run. |
| Retrieve itemized charges and preserve separate visits | IMPLEMENTED BUT NOT VERIFIED | `GET /api/billing/visits/{id}/bill`; schema has `UNIQUE (visit_id)` on bills. No live database comparison was run. |
| Add eligible services using active catalog prices only | IMPLEMENTED BUT NOT VERIFIED | Cashier submits `fee_id`, quantity, and request UUID. Server checks active/addable fee and reads `default_amount`; item stores its own unit amount. Workflow-managed fee codes are blocked from standalone cashier addition. |
| Prevent duplicate charges while allowing separate legitimate services | IMPLEMENTED BUT NOT VERIFIED | Cashier request UUID has a unique DB key; workflow bill items are linked to source test/session/etc. records and guarded against duplicates. No retry/concurrency test was run. |
| Admin fee catalog, search, edit, activate/deactivate, updater metadata and audit | IMPLEMENTED BUT NOT VERIFIED | Existing admin page and handlers use `fee_catalog`; only administrator guards the UI/API; no delete route exists. No role/API test was run. |
| Payment methods, amount/balance validation, overpayment prevention, unique receipt, staff attribution and audit | IMPLEMENTED BUT NOT VERIFIED | General bill payment is transactional and idempotent; amount is checked against locked bill balance; authenticated user is recorded. Ticket payment is separately transactional and advances only eligible visits. No payment test was run. |
| Simulated card and medical-aid approval | IMPLEMENTED BUT NOT VERIFIED | Non-cash payments are marked simulated and approved; UI/docs label them simulated. No live test was run. |
| Simulated medical-aid rejection | PARTIALLY IMPLEMENTED | `payments.status` supports `rejected`, but the general cashier payment handler always inserts `approved`; no user-selectable simulated rejection exists. |
| Receipt uses saved payment values and is printable | PARTIALLY IMPLEMENTED | Receipt endpoint returns saved payment, cashier, bill, visit, patient and item data; print control exists. However, receipt item rows include later bill additions even though the balance calculation excludes items created after the payment. |
| Preserve clinical workflows and do not finish visits on ordinary bill payment | IMPLEMENTED BUT NOT VERIFIED | Cashier bill payment changes bill state only. Ticket payment advances eligible visits to nursing; no general cashier payment code completes a visit. Workflow charges remain in department controllers. |
| One reusable collapse/expand behavior, desktop preference and 250-350ms transition | IMPLEMENTED BUT NOT VERIFIED | Shared JS/CSS provides toggle, `localStorage`, and 300ms transitions. Sidebar markup/content remains page-specific for role-specific links. |
| Mobile drawer, overlay, outside click, Escape and link-close | IMPLEMENTED BUT NOT VERIFIED | Shared script and CSS implement these behaviors; no mobile browser test was run. |
| Vertical navigation scrolling, touch and keyboard access | IMPLEMENTED BUT NOT VERIFIED | `.side-nav` is the overflow region, has touch scrolling and `tabindex`; no browser/device test was run. |
| Shared sidebar behavior on all existing staff pages/subpages | IMPLEMENTED BUT NOT VERIFIED | 19 staff layouts load the shared assets. Eight Reception URLs share one workspace layout. Sidebar interactions have not been tested on any page in this task. |
| Dynamic staff identity and backend role enforcement | IMPLEMENTED BUT NOT VERIFIED | Pages render the session user's display name/role; page middleware and API handlers enforce roles. No session/permission test was run. |

## Sidebar page test checklist

Status for every entry is **NOT TESTED**. Asset wiring was checked in source; this is not a browser pass. For each page, the requested open, scroll top/bottom, collapse/expand, cross-page persistence, mobile drawer, overlay, Escape, link-close, and horizontal-overflow checks remain outstanding.

| Staff page | Browser status |
|---|---|
| `frontend/dashboard.php` | NOT TESTED |
| `frontend/reception/index.php` | NOT TESTED |
| `frontend/reception/find-patient.php` | NOT TESTED |
| `frontend/reception/register-patient.php` | NOT TESTED |
| `frontend/reception/appointments.php` | NOT TESTED |
| `frontend/reception/arrivals.php` | NOT TESTED |
| `frontend/reception/patient-records.php` | NOT TESTED |
| `frontend/reception/queue.php` | NOT TESTED |
| `frontend/reception/referrals.php` | NOT TESTED |
| `frontend/nursing/index.php` | NOT TESTED |
| `frontend/doctor/index.php` | NOT TESTED |
| `frontend/emergency/index.php` | NOT TESTED |
| `frontend/specialists/index.php` | NOT TESTED |
| `frontend/specialists/outside-referral.php` | NOT TESTED |
| `frontend/testing-desk/index.php` | NOT TESTED |
| `frontend/laboratory/index.php` | NOT TESTED |
| `frontend/radiology/index.php` | NOT TESTED |
| `frontend/cardiac/index.php` | NOT TESTED |
| `frontend/therapy/index.php` | NOT TESTED |
| `frontend/billing/index.php` | NOT TESTED |
| `frontend/billing/receipt.php` | NOT TESTED |
| `frontend/pharmacy/index.php` | NOT TESTED |
| `frontend/pharmacy/receipt.php` | NOT TESTED |
| `frontend/admin/dashboard.php` | NOT TESTED |
| `frontend/admin/users.php` | NOT TESTED |
| `frontend/admin/fees.php` | NOT TESTED |
| `frontend/visit-status.php` | NOT TESTED |

## Exact remaining work identified before edits

1. Add an explicit, clearly labelled simulated approval/rejection choice to the cashier medical-aid payment flow; save rejected attempts with `status='rejected'`, keep them out of approved balances, and preserve idempotency/audit behavior.
2. Show only bill items that existed at the payment timestamp on that payment's receipt.
3. Run focused PHP and JavaScript syntax checks, then available browser/API/database checks without applying non-idempotent migrations or changing unrelated data.
4. Update this audit with the final code changes and actual checks/results. Keep untested sidebar pages marked NOT TESTED.
