# Hospital Integrated Patient Processing System

CareBridge is an academic CSC411 prototype for coordinating a hospital visit across reception, nursing, doctors, testing departments, specialists, therapy, billing and pharmacy. Use fictional demonstration data only. This project is not for clinical or production use.

## Run locally with XAMPP

1. Put the project in `C:\xampp\htdocs\hospital-system`.
2. Start Apache and MySQL from the XAMPP Control Panel.
3. Import `database\hosp.sql` into MySQL. For an existing database, apply the required SQL files in `database\migrations\` once.
4. Configure the database for Apache using `HOSPITAL_DB_NAME`, `HOSPITAL_DB_USER`, `HOSPITAL_DB_PASSWORD`, and optionally `HOSPITAL_DB_HOST` (defaults to `127.0.0.1`) and `HOSPITAL_DB_PORT` (defaults to `3306`). Alternatively, create the ignored local file `backend\config\database.local.php` returning `host`, `port`, `database`, `username`, and `password`. Environment variables override matching local settings. Restart Apache after changing its environment.
5. Create the initial administrator from PowerShell, with the database settings available in that process:

   ```powershell
   & C:\xampp\php\php.exe C:\xampp\htdocs\hospital-system\backend\setup\create_admin.php
   ```

   The CLI script prompts for the name, username and password, hashes the password, and refuses to create another initial administrator. Use a password of at least 12 characters. Do not commit credentials or leave the setup script exposed after initial setup.
6. Open `http://localhost/hospital-system/` for the project landing page, or `http://localhost/hospital-system/auth/login.php` to sign in. Administrators create staff accounts; public registration is disabled.

The project detects its URL base path from the folder under `htdocs`, so it can also run from a differently named project subfolder. Use that folder name in the local URL. No URL rewriting or virtual-host configuration is required.

The API entry point is `http://localhost/hospital-system/backend/index.php`. URL rewriting is not required; API requests use the `route` query parameter. A health check is available at:

```text
http://localhost/hospital-system/backend/index.php?route=%2Fapi%2Fhealth
```

## Project layout

- `backend/` - PHP API, controllers, routes, middleware, models and configuration
- `frontend/` - role-oriented department pages
- `assets/` - shared styles and scripts
- `database/` - schema, demo seed data and migrations
- `auth/` - staff sign-in
- `patient hospital system report.pdf` - consolidated CSC411 assignment report

## Scope and limitations

The repository includes modules for administration, reception, billing, nursing, general doctor, testing desk, laboratory, radiology, cardiac, specialists, therapy, pharmacy, visit tracking, audit viewing and reports. Module presence does not mean each workflow has passed an end-to-end test. Create role-specific demo accounts and follow the relevant pages to demonstrate individual paths.

Card and medical-aid approvals are simulated. Fees and medicine catalogue values are demo data. The medication-only flow uses an active undispensed prescription from an earlier visit as a documented assumption. API paths remain provisional. Emergency and cardiac changes may require migrations before use; complete authenticated workflows have not been verified in this checkout.

## Development

Use Visual Studio Code and Git. API requests use JSON over HTTP; retain the PHP session cookie and obtain a CSRF token from the authentication API for state-changing requests. Postman can be used to exercise individual endpoints. Use only fictional patient records and locally chosen staff passwords.
