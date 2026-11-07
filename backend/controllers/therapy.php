<?php

declare(strict_types=1);

function requireTherapyAccess(PDO $database): array
{
    $user = getAuthenticatedUser($database);
    if ($user === null) {
        jsonError('Authentication is required.', [], 401);
    }

    if (!in_array($user['role_name'], ['therapist', 'system_administrator'], true)) {
        jsonError('Therapist or system administrator access is required.', [], 403);
    }

    return $user;
}

function recordTherapyAudit(
    PDO $database,
    int $userId,
    string $action,
    int $patientId,
    int $visitId
): void {
    $statement = $database->prepare(
        'INSERT INTO audit_log (user_id, patient_id, visit_id, action)
         VALUES (:user_id, :patient_id, :visit_id, :action)'
    );
    $statement->execute([
        'user_id' => $userId,
        'patient_id' => $patientId,
        'visit_id' => $visitId,
        'action' => $action,
    ]);
}

function therapyReferralSelectSql(): string
{
    return 'SELECT referral.therapy_referral_id, referral.visit_id,
                referral.therapy_type, referral.reason, referral.status,
                referral.created_at, visit.patient_id,
                visit.status AS visit_status, patient.patient_number,
                patient.first_name, patient.last_name,
                diagnosis.diagnosis_description, diagnosis.clinical_findings,
                diagnosis.treatment_plan,
                source.presenting_complaint, source.clinical_observations,
                source.examination_findings, source.notes AS consultation_notes,
                referrer.display_name AS referrer_name,
                referrer_role.role_name AS referrer_role
         FROM therapy_referrals referral
         INNER JOIN visits visit ON visit.visit_id = referral.visit_id
         INNER JOIN patients patient ON patient.patient_id = visit.patient_id
         LEFT JOIN diagnoses diagnosis
            ON diagnosis.diagnosis_id = referral.diagnosis_id
         INNER JOIN consultations source
            ON source.consultation_id = referral.source_consultation_id
         INNER JOIN users referrer
            ON referrer.user_id = referral.referred_by_user_id
         INNER JOIN roles referrer_role
            ON referrer_role.role_id = referrer.role_id';
}

function therapyReferralData(array $row): array
{
    return [
        'therapy_referral_id' => (int) $row['therapy_referral_id'],
        'visit_id' => (int) $row['visit_id'],
        'patient_id' => (int) $row['patient_id'],
        'patient_number' => $row['patient_number'],
        'patient_name' => trim(
            (string) $row['first_name'] . ' ' . (string) $row['last_name']
        ),
        'therapy_type' => $row['therapy_type'],
        'referral_reason' => $row['reason'],
        'status' => $row['status'],
        'visit_status' => $row['visit_status'],
        'created_at' => $row['created_at'],
        'referrer' => [
            'name' => $row['referrer_name'],
            'role' => $row['referrer_role'],
        ],
        'consultation' => [
            'presenting_complaint' => $row['presenting_complaint'],
            'clinical_observations' => $row['clinical_observations'],
            'examination_findings' => $row['examination_findings'],
            'notes' => $row['consultation_notes'],
        ],
        'diagnosis' => [
            'description' => $row['diagnosis_description'],
            'clinical_findings' => $row['clinical_findings'],
            'treatment_plan' => $row['treatment_plan'],
        ],
        'sessions' => [],
    ];
}

function therapySessionsForReferrals(PDO $database, array $referralIds): array
{
    if ($referralIds === []) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($referralIds), '?'));
    $statement = $database->prepare(
        'SELECT session.therapy_session_id, session.therapy_referral_id,
                session.session_notes, session.session_at,
                therapist.display_name AS therapist_name
         FROM therapy_sessions session
         INNER JOIN users therapist
            ON therapist.user_id = session.therapist_user_id
         WHERE session.therapy_referral_id IN (' . $placeholders . ')
         ORDER BY session.session_at, session.therapy_session_id'
    );
    $statement->execute($referralIds);
    $sessions = [];
    foreach ($statement->fetchAll() as $row) {
        $referralId = (int) $row['therapy_referral_id'];
        $sessions[$referralId][] = [
            'therapy_session_id' => (int) $row['therapy_session_id'],
            'session_notes' => $row['session_notes'],
            'session_at' => $row['session_at'],
            'therapist_name' => $row['therapist_name'],
        ];
    }

    return $sessions;
}

function handleTherapyQueueGet(): void
{
    $database = getDatabaseConnection();
    $user = requireTherapyAccess($database);
    $statement = $database->query(
        therapyReferralSelectSql()
            . " WHERE referral.status IN ('referred', 'in_progress', 'completed')
               ORDER BY CASE WHEN referral.status = 'completed' THEN 1 ELSE 0 END,
                        referral.created_at, referral.therapy_referral_id
               LIMIT 200"
    );
    $referrals = [];
    foreach ($statement->fetchAll() as $row) {
        $referralId = (int) $row['therapy_referral_id'];
        $referrals[$referralId] = therapyReferralData($row);
        recordTherapyAudit(
            $database,
            (int) $user['user_id'],
            'therapy.referral.viewed',
            (int) $row['patient_id'],
            (int) $row['visit_id']
        );
    }

    $sessions = therapySessionsForReferrals(
        $database,
        array_map('intval', array_keys($referrals))
    );
    foreach ($sessions as $referralId => $referralSessions) {
        if (isset($referrals[$referralId])) {
            $referrals[$referralId]['sessions'] = $referralSessions;
        }
    }

    $referrals = array_values($referrals);
    jsonSuccess('Therapy queue loaded.', [
        'referrals' => $referrals,
        'count' => count($referrals),
    ]);
}

function handleTherapyReferralGet(string $referralId): void
{
    if (!ctype_digit($referralId) || (int) $referralId < 1) {
        jsonError('A valid therapy referral ID is required.', [
            'therapy_referral_id' => 'Enter a positive referral ID.',
        ], 422);
    }

    $database = getDatabaseConnection();
    $user = requireTherapyAccess($database);
    $statement = $database->prepare(
        therapyReferralSelectSql()
            . " WHERE referral.therapy_referral_id = :referral_id
                 AND referral.status IN ('referred', 'in_progress', 'completed')
               LIMIT 1"
    );
    $statement->execute(['referral_id' => (int) $referralId]);
    $row = $statement->fetch();
    if ($row === false) {
        jsonError('Therapy referral not found.', [], 404);
    }

    $referral = therapyReferralData($row);
    $sessions = therapySessionsForReferrals($database, [(int) $referralId]);
    $referral['sessions'] = $sessions[(int) $referralId] ?? [];
    recordTherapyAudit(
        $database,
        (int) $user['user_id'],
        'therapy.referral.viewed',
        (int) $row['patient_id'],
        (int) $row['visit_id']
    );

    jsonSuccess('Therapy referral loaded.', ['referral' => $referral]);
}

function therapyReferralForUpdate(PDO $database, int $referralId): array|false
{
    $statement = $database->prepare(
        'SELECT referral.therapy_referral_id, referral.visit_id,
                referral.status AS referral_status, visit.patient_id,
                visit.status AS visit_status
         FROM therapy_referrals referral
         INNER JOIN visits visit ON visit.visit_id = referral.visit_id
         WHERE referral.therapy_referral_id = :referral_id
         LIMIT 1
         FOR UPDATE'
    );
    $statement->execute(['referral_id' => $referralId]);

    return $statement->fetch();
}

function handleTherapySessionCreate(string $referralId): void
{
    if (!ctype_digit($referralId) || (int) $referralId < 1) {
        jsonError('A valid therapy referral ID is required.', [
            'therapy_referral_id' => 'Enter a positive referral ID.',
        ], 422);
    }

    $database = getDatabaseConnection();
    $user = requireTherapyAccess($database);
    requireCsrfRequest();
    $input = readJsonRequest();
    $requestId = $input['client_request_id'] ?? null;
    if (!is_string($requestId)
        || !preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $requestId
        )) {
        jsonError('A valid session request ID is required.', [
            'client_request_id' => 'Send a UUID for this session submission.',
        ], 422);
    }
    $sessionNotes = $input['session_notes'] ?? '';
    if (!is_string($sessionNotes) || trim($sessionNotes) === ''
        || strlen(trim($sessionNotes)) > 5000) {
        jsonError('Session notes are required and must be 5000 characters or fewer.', [
            'session_notes' => 'Enter notes describing the therapy session.',
        ], 422);
    }
    $sessionNotes = trim($sessionNotes);

    $sessionId = null;
    $billItemId = null;
    $billTotal = null;
    $created = false;
    try {
        $database->beginTransaction();
        $referral = therapyReferralForUpdate($database, (int) $referralId);
        if ($referral === false) {
            $database->rollBack();
            jsonError('Therapy referral not found.', [], 404);
        }

        $existingSession = $database->prepare(
            'SELECT therapy_session_id, therapy_referral_id, therapist_user_id
             FROM therapy_sessions
             WHERE client_request_id = :client_request_id
             LIMIT 1
             FOR UPDATE'
        );
        $existingSession->execute(['client_request_id' => $requestId]);
        $existing = $existingSession->fetch();
        if ($existing !== false) {
            if ((int) $existing['therapy_referral_id'] !== (int) $referralId
                || (int) $existing['therapist_user_id'] !== (int) $user['user_id']) {
                $database->rollBack();
                jsonError('This session request ID has already been used.', [], 409);
            }
            $sessionId = (int) $existing['therapy_session_id'];
            $billStatement = $database->prepare(
                'SELECT bill_item_id, bill_id
                 FROM bill_items
                 WHERE therapy_session_id = :therapy_session_id
                 LIMIT 1'
            );
            $billStatement->execute(['therapy_session_id' => $sessionId]);
            $existingBillItem = $billStatement->fetch();
            if ($existingBillItem === false) {
                throw new RuntimeException('The saved therapy session is missing its bill item.');
            }
            $billItemId = (int) $existingBillItem['bill_item_id'];
            $billTotal = billingCalculateBillTotal(
                $database,
                (int) $existingBillItem['bill_id']
            );
        } else {
            if ($referral['visit_status'] !== 'awaiting_therapy'
                || !in_array(
                    $referral['referral_status'],
                    ['referred', 'in_progress'],
                    true
                )) {
                $database->rollBack();
                jsonError('Only an active therapy referral can receive a session.', [], 409);
            }

            $feeStatement = $database->prepare(
                'SELECT fee_id
                 FROM fee_catalog
                 WHERE fee_code = :fee_code AND is_active = 1
                 LIMIT 1
                 FOR UPDATE'
            );
            $feeStatement->execute(['fee_code' => 'THERAPY_SESSION_DEMO']);
            if ($feeStatement->fetchColumn() === false) {
                $database->rollBack();
                jsonError(
                    'Therapy billing is not configured. Ask an administrator to activate the THERAPY_SESSION_DEMO fee.',
                    [],
                    409
                );
            }

            $sessionInsert = $database->prepare(
                'INSERT INTO therapy_sessions
                    (therapy_referral_id, therapist_user_id, session_notes,
                     client_request_id)
                 VALUES
                    (:referral_id, :therapist_user_id, :session_notes,
                     :client_request_id)'
            );
            $sessionInsert->execute([
                'referral_id' => (int) $referralId,
                'therapist_user_id' => (int) $user['user_id'],
                'session_notes' => $sessionNotes,
                'client_request_id' => $requestId,
            ]);
            $sessionId = (int) $database->lastInsertId();

            $referralUpdate = $database->prepare(
                'UPDATE therapy_referrals
                 SET status = \'in_progress\'
                 WHERE therapy_referral_id = :referral_id
                   AND status = \'referred\''
            );
            $referralUpdate->execute(['referral_id' => (int) $referralId]);

            $bill = billingGetOrCreateBill($database, (int) $referral['visit_id']);
            $billItem = billingAddFeeItemOnce(
                $database,
                (int) $bill['bill_id'],
                'THERAPY_SESSION_DEMO',
                'therapy',
                null,
                $sessionId
            );
            $billItemId = (int) $billItem['bill_item_id'];
            $billTotal = billingRefreshBillTotal(
                $database,
                (int) $bill['bill_id']
            );
            recordTherapyAudit(
                $database,
                (int) $user['user_id'],
                'therapy.session.recorded',
                (int) $referral['patient_id'],
                (int) $referral['visit_id']
            );
            $created = true;
        }
        $database->commit();
    } catch (Throwable $exception) {
        if ($database->inTransaction()) {
            $database->rollBack();
        }
        error_log('Therapy session recording failed: ' . $exception->getMessage());
        jsonError('Unable to record the therapy session right now.', [], 500);
    }

    jsonSuccess($created
        ? 'Therapy session recorded and billed.'
        : 'This therapy session was already recorded.', [
        'therapy_session_id' => $sessionId,
        'therapy_referral_id' => (int) $referralId,
        'visit_id' => (int) $referral['visit_id'],
        'referral_status' => $referral['referral_status'] === 'referred'
            ? 'in_progress'
            : $referral['referral_status'],
        'bill_item_id' => $billItemId,
        'bill_total' => $billTotal,
        'created' => $created,
    ], $created ? 201 : 200);
}

function handleTherapyComplete(string $referralId): void
{
    if (!ctype_digit($referralId) || (int) $referralId < 1) {
        jsonError('A valid therapy referral ID is required.', [
            'therapy_referral_id' => 'Enter a positive referral ID.',
        ], 422);
    }

    $database = getDatabaseConnection();
    $user = requireTherapyAccess($database);
    requireCsrfRequest();

    try {
        $database->beginTransaction();
        $referral = therapyReferralForUpdate($database, (int) $referralId);
        if ($referral === false) {
            $database->rollBack();
            jsonError('Therapy referral not found.', [], 404);
        }
        if ($referral['visit_status'] !== 'awaiting_therapy'
            || !in_array($referral['referral_status'], ['referred', 'in_progress'], true)) {
            $database->rollBack();
            jsonError('Only an active therapy referral can be completed.', [], 409);
        }

        $sessionCountStatement = $database->prepare(
            'SELECT COUNT(*)
             FROM therapy_sessions
             WHERE therapy_referral_id = :referral_id'
        );
        $sessionCountStatement->execute(['referral_id' => (int) $referralId]);
        if ((int) $sessionCountStatement->fetchColumn() < 1) {
            $database->rollBack();
            jsonError('Record at least one therapy session before completion.', [], 409);
        }

        $prescriptionStatement = $database->prepare(
            'SELECT 1
             FROM prescriptions
             WHERE visit_id = :visit_id AND status = \'active\'
             LIMIT 1'
        );
        $prescriptionStatement->execute(['visit_id' => (int) $referral['visit_id']]);
        $nextVisitStatus = $prescriptionStatement->fetchColumn() !== false
            ? 'awaiting_pharmacy'
            : 'awaiting_payment';

        $referralUpdate = $database->prepare(
            'UPDATE therapy_referrals
             SET status = \'completed\'
             WHERE therapy_referral_id = :referral_id'
        );
        $referralUpdate->execute(['referral_id' => (int) $referralId]);
        $visitUpdate = $database->prepare(
            'UPDATE visits
             SET status = :visit_status
             WHERE visit_id = :visit_id AND status = \'awaiting_therapy\''
        );
        $visitUpdate->execute([
            'visit_status' => $nextVisitStatus,
            'visit_id' => (int) $referral['visit_id'],
        ]);
        if ($visitUpdate->rowCount() !== 1) {
            $database->rollBack();
            jsonError('The visit is no longer awaiting therapy.', [], 409);
        }

        recordTherapyAudit(
            $database,
            (int) $user['user_id'],
            'therapy.referral.completed',
            (int) $referral['patient_id'],
            (int) $referral['visit_id']
        );
        $database->commit();
    } catch (Throwable $exception) {
        if ($database->inTransaction()) {
            $database->rollBack();
        }
        error_log('Therapy completion failed: ' . $exception->getMessage());
        jsonError('Unable to complete therapy right now.', [], 500);
    }

    jsonSuccess('Therapy completed and visit routed.', [
        'therapy_referral_id' => (int) $referralId,
        'visit_id' => (int) $referral['visit_id'],
        'referral_status' => 'completed',
        'visit_status' => $nextVisitStatus,
    ]);
}
