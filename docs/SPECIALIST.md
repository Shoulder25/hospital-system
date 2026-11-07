# Specialist referrals

The specialist module uses the existing `specialist_referrals`,
`consultations`, `diagnoses`, `therapy_referrals`, `outside_referrals`, `bills`,
`bill_items`, and `audit_log` tables. The additive migration
`database/migrations/20261107_stage9_specialist_referrals.sql` adds a nullable,
unique link from an outside referral to the specialist referral it resolves;
existing outside referrals remain valid. Apply it once to an existing database.
Fresh databases receive the same column and constraint from `database/hosp.sql`.
This is an academic prototype; outside referral charges are manually entered
and are not connected to an outside provider.

## Status transitions

- Accept: referral `pending` → `accepted`; visit remains `awaiting_specialist`.
- Diagnosed: referral `accepted` → `completed`; visit becomes
  `awaiting_therapy` if therapy is selected, otherwise `awaiting_pharmacy` if a
  prescription is recorded, otherwise `completed`.
- Undiagnosed: referral `accepted` → `completed`; an outside referral and
  unpaid bill item are created; visit becomes `awaiting_payment`.

The diagnosis or outside referral, any prescription/therapy records, referral
completion, visit transition, and audit row are saved in one transaction.
Therapy takes priority over pharmacy so the prescription remains active until
the therapy stage is completed.

## API endpoints

All endpoints require an authenticated `resident_specialist`, `locum_specialist`,
or `system_administrator`. Non-admin specialists see and update only referrals
assigned to their own user ID. POST requests require the existing
`X-CSRF-Token`.

| Method | Route | Request |
|---|---|---|
| GET | `/api/specialists/referrals` | None; returns assigned pending/accepted referrals with visit history and active medicines |
| GET | `/api/specialists/referrals/{id}` | None; returns one assigned pending/accepted referral with its visit history |
| POST | `/api/specialists/referrals/{id}/accept` | `{}` |
| POST | `/api/specialists/referrals/{id}/outcome` | Diagnosed or undiagnosed outcome (fields below) |
| GET | `/api/specialists/outside-referrals/{id}` | None; returns printable referral details |

Diagnosed example:

```json
{
  "outcome": "diagnosed",
  "presenting_complaint": "Fictional complaint",
  "clinical_observations": "Fictional observations",
  "examination_findings": "Fictional findings",
  "notes": "Specialist notes",
  "diagnosis_description": "Fictional diagnosis",
  "clinical_findings": "Supporting findings",
  "treatment_plan": "Treatment plan",
  "refer_for_therapy": true,
  "therapy_type": "DEMO therapy",
  "therapy_reason": "Fictional referral reason",
  "prescription_items": [
    {
      "medicine_id": 1,
      "quantity": 1,
      "dosage": "As directed",
      "frequency": "Once daily",
      "duration": "1 day",
      "instructions": "Fictional test data"
    }
  ]
}
```

`prescription_items` and therapy are optional. If therapy is selected, it is
scheduled before pharmacy even when a prescription is present.

Undiagnosed example:

```json
{
  "outcome": "undiagnosed",
  "presenting_complaint": "Fictional complaint",
  "clinical_observations": "Fictional observations",
  "examination_findings": "Fictional findings",
  "notes": "Specialist notes",
  "provider_name": "DEMO Outside Hospital",
  "clinical_summary": "Fictional summary for the outside clinician.",
  "outside_tests_amount": "25.00"
}
```

The outside charge amount is entered manually and stored as one bill item using
the active `OUTSIDE_TESTS_MANUAL` fee-catalog entry. The item links to the
outside-referral record, and duplicate charges for the same referral are
prevented. It is not a real tariff.

## Browser testing

1. Start Apache and MySQL in XAMPP, then sign in as an assigned resident/locum
   specialist or system administrator.
2. Open `http://localhost/hospital-system/frontend/specialists/`.
3. Use a doctor account to create a specialist referral and assign it to the
   logged-in specialist. Refresh the specialist queue.
4. Select the referral and review the nursing assessment, test results,
   referring consultation, diagnosis, and referral reason.
5. Accept the referral and submit a diagnosed outcome. Confirm the configured
   visit status and any active prescription.
6. Repeat for an undiagnosed visit. Confirm the visit is awaiting payment and
   the outside-referral link opens a printable summary.

## Postman testing

1. Sign in with the appropriate role and retain its session cookie.
2. Get the token from `GET /backend/index.php?route=/api/auth/me`.
3. Fetch `GET /backend/index.php?route=/api/specialists/referrals`.
4. Accept with `POST /backend/index.php?route=/api/specialists/referrals/{id}/accept`
   and header `X-CSRF-Token`.
5. Submit either outcome to
   `POST /backend/index.php?route=/api/specialists/referrals/{id}/outcome`.
6. For an outside referral, retrieve
   `GET /backend/index.php?route=/api/specialists/outside-referrals/{id}`.
7. Check HTTP 403 for another specialist's referral, 409 for an unaccepted or
   completed referral, and 422 for invalid outcome fields or amount.

All patient and clinical data used for testing must be fictional.
