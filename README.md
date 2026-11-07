# Hospital Integrated Patient Processing System

Starter scaffold for the CSC411 mini-project (school project, functional-first, light security).

## Start here
1. Open this folder in VS Code.
2. Read `AGENTS.md` before asking the coding agent to implement anything.
3. Put the project at `C:\xampp\htdocs\hospital-system`.
4. Start Apache and MySQL in XAMPP.
5. Follow the remaining implementation milestones in `AGENTS.md`.

Source documents are in `docs/source/`.

## Important
The database schema, PDO health check, authentication, administration,
reception/patient registration, nursing assessment, general doctor, and testing
centre/laboratory/radiology/cardiac modules are implemented. Other hospital
workflow and department modules remain in progress; `.gitkeep` files only
preserve empty folders.

## Backend and authentication
The root `index.php` introduces the project and links to staff sign-in. The
login page is `auth/login.php`; public registration is disabled, and
`frontend/signup.php` displays that notice;
the staff dashboard is `frontend/dashboard.php`. Reception staff use
`frontend/reception/index.php`; nurses use `frontend/nursing/index.php`;
administrator pages are under `frontend/admin/`. The API entry point is
`backend/index.php`.
API endpoints are provisional
until reconciled with Person 3's specification. Configure the database
environment and create the initial administrator as described in
[`docs/AUTHENTICATION.md`](./docs/AUTHENTICATION.md). Reception setup, API
routes, workflow status rules, and Postman tests are in
[`docs/RECEPTION.md`](./docs/RECEPTION.md). Nursing schema migration, API
contracts and test steps are in [`docs/NURSING.md`](./docs/NURSING.md).
General doctor schema migration, clinical workflow, API contracts and test
steps are in [`docs/DOCTOR.md`](./docs/DOCTOR.md).
Testing-centre routing and laboratory, radiology, and cardiac workflows,
including the Stage 5 migration and API contracts, are documented in
[`docs/STAGE5_TESTING.md`](./docs/STAGE5_TESTING.md).
