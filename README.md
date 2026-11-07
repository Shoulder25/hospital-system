# Hospital Integrated Patient Processing System

Academic prototype for the CSC411 mini-project (functional-first, light security).

## Start here
1. Open this folder in VS Code.
2. Read `AGENTS.md` before asking the coding agent to implement anything.
3. Put the project at `C:\xampp\htdocs\hospital-system`.
4. Start Apache and MySQL in XAMPP.
5. Follow the setup and demo instructions in [`docs/DEMO.md`](./docs/DEMO.md).

Source documents are in `docs/source/`.

## Current state
The repository includes authentication and administration, reception, billing,
nursing, general doctor, testing desk, laboratory, radiology, cardiac,
specialist referral, therapy, pharmacy, visit tracking, audit-log viewing and
reports. PHP syntax has been checked across all PHP files. The full nine-path
end-to-end demo has **not** been run: this checkout has no demo accounts for
most roles, and the emergency user interface/API is not implemented. Do not
describe all workflows as end-to-end tested.

Remaining limitations: card and medical-aid approvals are simulated; fees and
medicine catalogue values are demo data; medication-only visits reuse an active
undispensed prescription from an earlier visit (the documented workflow
assumption); emergency care currently has schema support but no working
department module. This academic prototype is not for clinical or production use.

## Backend and authentication
The root `index.php` introduces the project and links to staff sign-in. The
login page is `auth/login.php`; public registration is disabled, and
`frontend/signup.php` displays that notice;
the staff dashboard is `frontend/dashboard.php`. Reception staff use
`frontend/reception/index.php`; nurses use `frontend/nursing/index.php`;
administrator pages are under `frontend/admin/`. The API entry point is
`backend/index.php`.
The API paths are provisional and have been compared with Person 3's proposed
specification; see [`docs/API_MAPPING.md`](./docs/API_MAPPING.md). Configure the
database environment and create the initial administrator as described in
[`docs/AUTHENTICATION.md`](./docs/AUTHENTICATION.md). Reception setup, API
routes, workflow status rules, and Postman tests are in
[`docs/RECEPTION.md`](./docs/RECEPTION.md). Nursing schema migration, API
contracts and test steps are in [`docs/NURSING.md`](./docs/NURSING.md).
General doctor schema migration, clinical workflow, API contracts and test
steps are in [`docs/DOCTOR.md`](./docs/DOCTOR.md).
Testing-centre routing and laboratory, radiology, and cardiac workflows,
including the Stage 5 migration and API contracts, are documented in
[`docs/STAGE5_TESTING.md`](./docs/STAGE5_TESTING.md).
The role-based demo script and its current test limitations are in
[`docs/DEMO.md`](./docs/DEMO.md).
