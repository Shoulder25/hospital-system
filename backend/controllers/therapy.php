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

function handleTherapyQueueGet(): void
{
    $database = getDatabaseConnection();
    $user = requireTherapyAccess($database);
    $statement = $database->query(
        'SELECT referral.therapy_referral_id, referral.visit_id,
                referral.therapy_type, referral.reason, referral.status,
                referral.created_at, visit.patient_id,
                patient.patient_number, patient.first_name, patient.last_name,
                diagnosis.diagnosis_description, diagnosis.clinical_findings,
                diagnosis.treatment_plan
         FROM therapy_referrals referral
         INNER JOIN visits visit ON visit.visit_id = referral.visit_id
         INNER JOIN patients patient ON patient.patient_id = visit.patient_id
         LEFT JOIN diagnoses diagnosis
            ON diagnosis.diagnosis_id = referral.diagnosis_id
         WHERE visit.status = \'awaiting_therapy\'
           AND referral.status IN (\'referred\', \'in_progress\')
         ORDER BY referral.created_at, referral.therapy_referral_id
         LIMIT 200'
    );
    $referrals = [];
    foreach ($statement->fetchAll() as $row) {
        $referralId = (int) $row['therapy_referral_id'];
        $referrals[$referralId] = [
            'therapy_referral_id' => $referralId,
            'visit_id' => (int) $row['visit_id'],
            'patient_id' => (int) $row['patient_id'],
            'patient_number' => $row['patient_number'],
            'patient_name' => trim(
                (string) $row['first_name'] . ' ' . (string) $row['last_name']
            ),
            'therapy_type' => $row['therapy_type'],
            'referral_reason' => $row['reason'],
            'status' => $row['status'],
            'created_at' => $row['created_at'],
            'diagnosis' => [
                'description' => $row['diagnosis_description'],
                'clinical_findings' => $row['clinical_findings'],
                'treatment_plan' => $row['treatment_plan'],
            ],
            'sessions' => [],
        ];
        recordTherapyAudit(
            $database,
            (int) $user['user_id'],
            'therapy.referral.viewed',
            (int) $row['patient_id'],
            (int) $row['visit_id']
        );
    }

    if ($referrals !== []) {
        $sessions = $database->query(
            'SELECT session.therapy_session_id, session.therapy_referral_id,
                    session.session_notes, session.session_at,
                    therapist.display_name AS therapist_name
             FROM therapy_sessions session
             INNER JOIN therapy_referrals referral
                ON referral.therapy_referral_id = session.therapy_referral_id
             INNER JOIN visits visit ON visit.visit_id = referral.visit_id
             INNER JOIN users therapist
                ON therapist.user_id = session.therapist_user_id
             WHERE visit.status = \'awaiting_therapy\'
               AND referral.status IN (\'referred\', \'in_progress\')
             ORDER BY session.session_at, session.therapy_session_id'
        );
        foreach ($sessions->fetchAll() as $session) {
            $referralId = (int) $session['therapy_referral_id'];
            if (!isset($referrals[$referralId])) {
                continue;
            }
            $referrals[$referralId]['sessions'][] = [
                'therapy_session_id' => (int) $session['therapy_session_id'],
                'session_notes' => $session['session_notes'],
                'session_at' => $session['session_at'],
                'therapist_name' => $session['therapist_name'],
            ];
        }
    }

    $referrals = array_values($referrals);
    jsonSuccess('Therapy queue loaded.', [
        'referrals' => $referrals,
        'count' => count($referrals),
    ]);
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
    $sessionNotes = $input['session_notes'] ?? '';
    if (!is_string($sessionNotes) || strlen($sessionNotes) > 5000) {
        jsonError('Session notes must be 5000 characters or fewer.', [
            'session_notes' => 'Enter valid session notes.',
        ], 422);
    }
    $sessionNotes = trim($sessionNotes);

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
            jsonError('Only an active therapy referral can receive a session.', [], 409);
        }

        $sessionInsert = $database->prepare(
            'INSERT INTO therapy_sessions
                (therapy_referral_id, therapist_user_id, session_notes)
             VALUES
                (:referral_id, :therapist_user_id, :session_notes)'
        );
        $sessionInsert->execute([
            'referral_id' => (int) $referralId,
            'therapist_user_id' => (int) $user['user_id'],
            'session_notes' => $sessionNotes === '' ? null : $sessionNotes,
        ]);
        $sessionId = (int) $database->lastInsertId();

        $referralUpdate = $database->prepare(
            'UPDATE therapy_referrals
             SET status = \'in_progress\'
             WHERE therapy_referral_id = :referral_id'
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
        $billTotal = billingRefreshBillTotal($database, (int) $bill['bill_id']);
        recordTherapyAudit(
            $database,
            (int) $user['user_id'],
            'therapy.session.recorded',
            (int) $referral['patient_id'],
            (int) $referral['visit_id']
        );
        $database->commit();
    } catch (Throwable $exception) {
        if ($database->inTransaction()) {
            $database->rollBack();
        }
        error_log('Therapy session recording failed: ' . $exception->getMessage());
        jsonError('Unable to record the therapy session right now.', [], 500);
    }

    jsonSuccess('Therapy session recorded and billed.', [
        'therapy_session_id' => $sessionId,
        'therapy_referral_id' => (int) $referralId,
        'visit_id' => (int) $referral['visit_id'],
        'referral_status' => 'in_progress',
        'bill_item_id' => (int) $billItem['bill_item_id'],
        'bill_total' => $billTotal,
    ], 201);
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
