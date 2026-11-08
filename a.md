# Staff Account Management Improvements

## Purpose

Make staff account creation and administration safer, easier to use, and more
accountable.

## Current behavior

- Administrators can create staff accounts and select a role.
- Passwords must be at least 12 characters and are stored as hashes.
- Administrators can activate or deactivate accounts.
- Public registration is disabled.

## Proposed improvements

### 1. Enforce role restrictions in the API

The staff account page says administrator accounts cannot be created there, but
the API accepts any existing role and the role list appears to include every
role. Filter administrator roles from the selection and, more importantly,
reject unauthorized administrator-role assignments on the server.

### 2. Require a password change at first sign-in

Have the administrator set or generate a temporary password, then require the
staff member to choose a new password at their first sign-in. This reduces the
need for administrators to know or retain staff members' permanent passwords.

### 3. Audit account changes

Record which administrator created, activated, or deactivated an account and
when the action occurred. Keep the audit records available for review.

### 4. Improve account recovery and feedback

Provide a safe password-reset process and make success and validation errors
clear in the staff directory. Confirm status changes so administrators can
catch accidental deactivation.

## Suggested priority

1. Enforce role restrictions in the API.
2. Add first-sign-in password changes.
3. Audit account creation and status changes.
4. Add account recovery and refine interface feedback.
