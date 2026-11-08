# Authentication and initial staff access

The authentication endpoints are provisional until they are reconciled with
Person 3's API specification. The pages and API use the existing `users` and
`roles` tables in `database/hosp.sql`; no schema changes or default accounts
are required.

## Run with XAMPP

1. Import `database/hosp.sql` into MySQL if it has not already been imported.
2. Start Apache and MySQL in the XAMPP Control Panel.
3. Configure the environment variables available to Apache:
   - `HOSPITAL_DB_NAME` (required for the project database)
   - `HOSPITAL_DB_USER` (required for the MySQL account used by the app)
   - `HOSPITAL_DB_PASSWORD` (required if your MySQL account has a password)
   - `HOSPITAL_DB_HOST` (optional; defaults to `127.0.0.1`)
   - `HOSPITAL_DB_PORT` (optional; defaults to `3306`)
   Alternatively, create `backend/config/database.local.php` returning an
   array with `host`, `port`, `database`, `username`, and `password`. This
   machine-local file is ignored by Git; do not commit it. Environment
   variables override matching local settings.
4. Keep database credentials out of the repository and any public web file.
   Restart Apache after changing environment variables.
5. Public registration is disabled in the application configuration. Existing
   accounts and role assignments are unchanged. Administrators create staff
   accounts through the protected management module.
6. Open the login page from your local project URL (for example, the site root
   with `/auth/login.php` appended) to sign in.

The API entry point does not depend on Apache URL rewriting. It uses
`backend/index.php?route=...`.

## Create the initial administrator once

The setup script is CLI-only. It prompts for the administrator's full name,
username, and password and hashes the password with `password_hash()`. It
refuses to create an account if a system administrator already exists.

Run it from PowerShell:

```powershell
& C:\xampp\php\php.exe C:\xampp\htdocs\hospital-system\backend\setup\create_admin.php
```

The database environment variables must be available to that PowerShell
process. Use a password of at least 12 characters. After successful setup,
delete `backend/setup/create_admin.php` or move it outside the web project.
The script also refuses subsequent initial setup attempts. Password input is
read in the CLI and may be visible in the terminal while typed; do not run the
script in a shared or recorded terminal.

## Provisional API

All responses use the common JSON envelope. Send JSON request bodies with
`Content-Type: application/json`. State-changing requests require the
`X-CSRF-Token` header. The token is obtained from `GET /api/auth/csrf`, or
returned by successful login and `GET /api/auth/me`.

| Method | Route | Access | Purpose |
|---|---|---|---|
| GET | `/api/health` | Public | Confirms backend and database connectivity |
| GET | `/api/auth/csrf` | Public | Starts a session and returns a CSRF token |
| POST | `/api/auth/login` | Public + CSRF | Accepts `username`, `password` |
| POST | `/api/auth/logout` | Logged in + CSRF | Destroys the current session |
| GET | `/api/auth/me` | Logged in | Returns current user, role, and CSRF token |
| GET | `/api/auth/roles` | System administrator | Returns assignable staff roles, excluding administrator |
| POST | `/api/auth/register` | System administrator + CSRF | Accepts `display_name`, `username`, `password`, `confirm_password`, `role` |
| GET | `/api/admin/users` | System administrator | Lists staff accounts; optional `search` query |
| GET | `/api/admin/roles` | System administrator | Lists roles for staff management |
| POST | `/api/admin/users` | System administrator + CSRF | Creates a user with `display_name`, `username`, `password`, `role_id` |
| PATCH | `/api/admin/users/{id}/status` | System administrator + CSRF | Updates `is_active` to `true` or `false` |

Public signup is disabled in application configuration and is not controlled
by an environment-variable override. The signup page displays a closed
registration message. Both `/api/auth/register` and `/api/admin/users` require
an authenticated system administrator; only the administrator-managed
endpoints can assign staff roles. Registration requires a password of at
least 12 characters and a matching confirmation. Password hashes are never
returned.

## Test checklist

- **Health:** open
  `http://localhost/hospital-system/backend/index.php?route=%2Fapi%2Fhealth`.
  Expect HTTP 200 and `"database":"connected"`.
- **Login:** use the login page with the initial admin account; expect a
  redirect to `frontend/dashboard.php` and the administrator name/role in the header.
- **Incorrect password:** retry with an incorrect password; expect a useful
  message and HTTP 401 in the API response.
- **Session:** request `GET /api/auth/me` in Postman with the login cookies;
  expect the account and role. Without a session, expect HTTP 401.
- **Logout:** send `GET /api/auth/csrf`, then `POST /api/auth/logout` with
  the session cookie and `X-CSRF-Token`; expect HTTP 200. A subsequent `/me`
  request should return HTTP 401.
- **Public registration:** open `frontend/signup.php`; it should show that
  public registration is disabled, with no registration form. An
  unauthenticated `POST /api/auth/register` must return HTTP 401.
- **Registration as administrator:** sign in as the initial administrator,
  open `frontend/admin/users.php`, select a database-provided staff role, and
  submit valid details. Expect HTTP 201.
- **Registration as ordinary staff:** sign in as a non-admin user and submit
  a request to `POST /api/admin/users` with that session's CSRF token. Expect HTTP 403.
- **Administrator role restriction:** submit `role: "system_administrator"`
  to the staff registration endpoint. Expect HTTP 403; the role is also
  omitted from the role-list endpoint.
- **Dashboard without login:** open `frontend/dashboard.php` in a fresh session.
  Expect redirect to `auth/login.php`.
- **Administration without login:** open `frontend/admin/dashboard.php` or
  `frontend/admin/users.php` in a fresh session. Expect redirect to
  `auth/login.php`.
- **Initial setup script over HTTP:** requesting
  `backend/setup/create_admin.php` in a browser should return 404. Run it only
  using the PHP CLI command above.
