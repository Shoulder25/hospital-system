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

function requireTherapyReadAccess(PDO $database): array
{
    $user = getAuthenticatedUser($database);
    if ($user === null) {
        jsonError('Authentication is required.', [], 401);
    }

    if (!in_array($user['role_name'], [
        'therapist',
        'system_administrator',
        'general_doctor',
        'resident_specialist',
        'locum_specialist',
    ], true)) {
        jsonError('Therapy record access is not available to this role.', [], 403);
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
                referral.review_status, referral.flagged_reason,
                referral.assigned_therapist_user_id, referral.accepted_at,
                referral.completed_at, referral.outcome,
                referral.discharge_notes, referral.created_at,
                referral.referred_by_user_id,
                visit.patient_id, patient.date_of_birth, patient.gender,
                patient.is_temporary, visit.arrived_at,
                visit.status AS visit_status, patient.patient_number,
                patient.first_name, patient.last_name,
                diagnosis.diagnosis_description, diagnosis.clinical_findings,
                diagnosis.treatment_plan,
                source.presenting_complaint, source.clinical_observations,
                source.examination_findings, source.notes AS consultation_notes,
                referrer.display_name AS referrer_name,
                referrer_role.role_name AS referrer_role,
                therapist.display_name AS therapist_name
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
            ON referrer_role.role_id = referrer.role_id
         LEFT JOIN users therapist
            ON therapist.user_id = referral.assigned_therapist_user_id';
}

function therapyReferralData(array $row): array
{
    return [
        'therapy_referral_id' => (int) $row['therapy_referral_id'],
        'visit_id' => (int) $row['visit_id'],
        'patient_id' => (int) $row['patient_id'],
        'patient_number' => $row['patient_number'],
        'date_of_birth' => $row['date_of_birth'],
        'gender' => $row['gender'],
        'is_temporary' => (bool) $row['is_temporary'],
        'patient_name' => trim(
            (string) $row['first_name'] . ' ' . (string) $row['last_name']
        ),
        'therapy_type' => $row['therapy_type'],
        'referral_reason' => $row['reason'],
        'status' => $row['status'],
        'review_status' => $row['review_status'],
        'flagged_reason' => $row['flagged_reason'],
        'assigned_therapist_user_id' => $row['assigned_therapist_user_id'] === null
            ? null
            : (int) $row['assigned_therapist_user_id'],
        'assigned_therapist' => $row['therapist_name'],
        'visit_status' => $row['visit_status'],
        'visit_arrived_at' => $row['arrived_at'],
        'created_at' => $row['created_at'],
        'accepted_at' => $row['accepted_at'],
        'completed_at' => $row['completed_at'],
        'outcome' => $row['outcome'],
        'discharge_notes' => $row['discharge_notes'],
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

function therapyDashboardCounts(PDO $database, array $user): array
{
    $assignment = '';
    $parameters = [];
    if ($user['role_name'] !== 'system_administrator') {
        $assignment = ' AND (assigned_therapist_user_id = :therapist_id
            OR (assigned_therapist_user_id IS NULL AND review_status = \'pending\'))';
        $parameters['therapist_id'] = (int) $user['user_id'];
    }
    $statement = $database->prepare(
        "SELECT
            COUNT(*) AS total,
            SUM(review_status = 'pending' AND status = 'referred') AS pending,
            SUM(review_status = 'flagged') AS flagged,
            SUM(review_status = 'accepted'
                AND status IN ('referred', 'in_progress')) AS active,
            SUM(status = 'completed') AS completed,
            SUM(status = 'completed' AND outcome IS NOT NULL) AS with_outcomes
         FROM therapy_referrals
         WHERE status IN ('referred', 'in_progress', 'completed')" . $assignment
    );
    $statement->execute($parameters);
    $counts = $statement->fetch();
    return array_map(
        'intval',
        $counts ?: [
            'total' => 0,
            'pending' => 0,
            'flagged' => 0,
            'active' => 0,
            'completed' => 0,
            'with_outcomes' => 0,
        ]
    );
}

function handleTherapyDashboardGet(): void
{
    $database = getDatabaseConnection();
    $user = requireTherapyAccess($database);
    jsonSuccess('Therapy dashboard loaded.', [
        'counts' => therapyDashboardCounts($database, $user),
    ]);
}

function handleTherapyReportsGet(): void
{
    $database = getDatabaseConnection();
    $user = requireTherapyAccess($database);
    $assignment = '';
    $parameters = [];
    if ($user['role_name'] !== 'system_administrator') {
        $assignment = ' AND referral.assigned_therapist_user_id = :therapist_id';
        $parameters['therapist_id'] = (int) $user['user_id'];
    }
    $typesStatement = $database->prepare(
        "SELECT referral.therapy_type, referral.status, COUNT(*) AS case_count
         FROM therapy_referrals referral
         WHERE referral.status IN ('referred', 'in_progress', 'completed')"
            . $assignment .
        ' GROUP BY referral.therapy_type, referral.status
          ORDER BY referral.therapy_type, referral.status'
    );
    $typesStatement->execute($parameters);
    $outcomesStatement = $database->prepare(
        "SELECT referral.therapy_referral_id, referral.visit_id,
                visit.patient_id, patient.patient_number,
                patient.first_name, patient.last_name,
                referral.therapy_type, referral.outcome,
                referral.discharge_notes, referral.completed_at,
                referrer.display_name AS referrer_name,
                referrer_role.role_name AS referrer_role
         FROM therapy_referrals referral
         INNER JOIN visits visit ON visit.visit_id = referral.visit_id
         INNER JOIN patients patient ON patient.patient_id = visit.patient_id
         INNER JOIN users referrer
            ON referrer.user_id = referral.referred_by_user_id
         INNER JOIN roles referrer_role
            ON referrer_role.role_id = referrer.role_id
         WHERE referral.status = 'completed'" . $assignment .
        ' ORDER BY referral.completed_at DESC, referral.therapy_referral_id DESC
          LIMIT 250'
    );
    $outcomesStatement->execute($parameters);
    $outcomes = array_map(static function (array $row): array {
        return [
            'therapy_referral_id' => (int) $row['therapy_referral_id'],
            'visit_id' => (int) $row['visit_id'],
            'patient_id' => (int) $row['patient_id'],
            'patient_number' => $row['patient_number'],
            'patient_name' => trim(
                (string) $row['first_name'] . ' ' . (string) $row['last_name']
            ),
            'therapy_type' => $row['therapy_type'],
            'outcome' => $row['outcome'],
            'discharge_notes' => $row['discharge_notes'],
            'completed_at' => $row['completed_at'],
            'referrer_name' => $row['referrer_name'],
            'referrer_role' => $row['referrer_role'],
        ];
    }, $outcomesStatement->fetchAll());
    jsonSuccess('Therapy outcome report loaded.', [
        'counts' => therapyDashboardCounts($database, $user),
        'by_type_and_status' => $typesStatement->fetchAll(),
        'completed_cases' => $outcomes,
    ]);
}

function therapySessionsForReferrals(PDO $database, array $referralIds): array
{
    if ($referralIds === []) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($referralIds), '?'));
    $statement = $database->prepare(
        'SELECT session.therapy_session_id, session.therapy_referral_id,
                session.session_notes, session.activities_performed,
                session.observations, session.patient_response,
                session.progress_notes, session.challenges,
                session.follow_up_recommendations, session.session_completed,
                session.session_at, session.therapist_user_id,
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
            'activities_performed' => $row['activities_performed'],
            'observations' => $row['observations'],
            'patient_response' => $row['patient_response'],
            'progress_notes' => $row['progress_notes'],
            'challenges' => $row['challenges'],
            'follow_up_recommendations' => $row['follow_up_recommendations'],
            'session_completed' => (bool) $row['session_completed'],
            'session_at' => $row['session_at'],
            'therapist_user_id' => (int) $row['therapist_user_id'],
            'therapist_name' => $row['therapist_name'],
        ];
    }

    return $sessions;
}

function handleTherapyQueueGet(): void
{
    $database = getDatabaseConnection();
    $user = requireTherapyAccess($database);
    $scope = $_GET['scope'] ?? 'all';
    $search = $_GET['search'] ?? '';
    $validScopes = [
        'all', 'pending', 'patients', 'assessments', 'sessions',
        'completed', 'history',
    ];
    if (!is_string($scope) || !in_array($scope, $validScopes, true)
        || !is_string($search) || strlen(trim($search)) > 100) {
        jsonError('Invalid therapy queue filters.', [
            'scope' => 'Choose a supported queue.',
            'search' => 'Search must be 100 characters or fewer.',
        ], 422);
    }

    $conditions = ["referral.status IN ('referred', 'in_progress', 'completed')"];
    $parameters = [];
    if ($user['role_name'] !== 'system_administrator') {
        $conditions[] = '(referral.assigned_therapist_user_id = :therapist_id
            OR (referral.assigned_therapist_user_id IS NULL
                AND referral.review_status = \'pending\'))';
        $parameters['therapist_id'] = (int) $user['user_id'];
    }
    if ($scope === 'pending') {
        $conditions[] = "referral.review_status IN ('pending', 'flagged')
            AND referral.status = 'referred'";
    } elseif (in_array($scope, ['patients', 'assessments', 'sessions'], true)) {
        $conditions[] = "referral.review_status = 'accepted'
            AND referral.status IN ('referred', 'in_progress')";
        if ($user['role_name'] !== 'system_administrator') {
            $conditions[] = 'referral.assigned_therapist_user_id = :assigned_therapist_id';
            $parameters['assigned_therapist_id'] = (int) $user['user_id'];
        }
    } elseif ($scope === 'completed') {
        $conditions[] = "referral.status = 'completed'";
    }
    if (trim($search) !== '') {
        $conditions[] = '(patient.patient_number LIKE :patient_number_search
            OR patient.first_name LIKE :first_name_search
            OR patient.last_name LIKE :last_name_search
            OR CAST(referral.visit_id AS CHAR) = :exact_visit_search
            OR CAST(referral.therapy_referral_id AS CHAR) = :exact_referral_search
            OR EXISTS (
                SELECT 1 FROM therapy_sessions search_session
                WHERE search_session.therapy_referral_id = referral.therapy_referral_id
                  AND (search_session.session_notes LIKE :session_notes_search
                       OR search_session.activities_performed LIKE :session_activities_search
                       OR search_session.patient_response LIKE :session_response_search)
            )
            OR EXISTS (
                SELECT 1 FROM therapy_progress search_progress
                WHERE search_progress.therapy_referral_id = referral.therapy_referral_id
                  AND (search_progress.progress_notes LIKE :progress_notes_search
                       OR search_progress.challenges LIKE :progress_challenges_search
                       OR search_progress.follow_up_recommendations LIKE :progress_followup_search)
            ))';
        $parameters['patient_number_search'] = '%' . trim($search) . '%';
        $parameters['first_name_search'] = '%' . trim($search) . '%';
        $parameters['last_name_search'] = '%' . trim($search) . '%';
        $parameters['exact_visit_search'] = trim($search);
        $parameters['exact_referral_search'] = trim($search);
        $parameters['session_notes_search'] = '%' . trim($search) . '%';
        $parameters['session_activities_search'] = '%' . trim($search) . '%';
        $parameters['session_response_search'] = '%' . trim($search) . '%';
        $parameters['progress_notes_search'] = '%' . trim($search) . '%';
        $parameters['progress_challenges_search'] = '%' . trim($search) . '%';
        $parameters['progress_followup_search'] = '%' . trim($search) . '%';
    }

    $statement = $database->prepare(
        therapyReferralSelectSql()
            . ' WHERE ' . implode(' AND ', $conditions)
            . " ORDER BY CASE WHEN referral.status = 'completed' THEN 1 ELSE 0 END,
                        referral.created_at DESC, referral.therapy_referral_id DESC
               LIMIT 250"
    );
    $statement->execute($parameters);
    $referrals = [];
    foreach ($statement->fetchAll() as $row) {
        $referralId = (int) $row['therapy_referral_id'];
        $referrals[$referralId] = therapyReferralData($row);
        $referralCountStatement = $database->prepare(
            'SELECT
                (SELECT COUNT(*) FROM therapy_sessions
                 WHERE therapy_referral_id = :session_referral_id) AS session_count,
                EXISTS (SELECT 1 FROM therapy_assessments
                        WHERE therapy_referral_id = :assessment_referral_id) AS has_assessment,
                EXISTS (SELECT 1 FROM therapy_treatment_plans
                        WHERE therapy_referral_id = :plan_referral_id) AS has_plan'
        );
        $referralCountStatement->execute([
            'session_referral_id' => $referralId,
            'assessment_referral_id' => $referralId,
            'plan_referral_id' => $referralId,
        ]);
        $counts = $referralCountStatement->fetch();
        $referrals[$referralId]['session_count'] = (int) $counts['session_count'];
        $referrals[$referralId]['has_assessment'] = (bool) $counts['has_assessment'];
        $referrals[$referralId]['has_treatment_plan'] = (bool) $counts['has_plan'];
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
    $user = requireTherapyReadAccess($database);
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
    if (in_array($user['role_name'], ['therapist', 'system_administrator'], true)) {
        therapyRequireReferralOwner($row, $user);
    } elseif ((int) $row['referred_by_user_id'] !== (int) $user['user_id']) {
        jsonError('Only the referring clinician can review this therapy report.', [], 403);
    }

    $referral = therapyReferralData($row);
    $sessions = therapySessionsForReferrals($database, [(int) $referralId]);
    $referral['sessions'] = $sessions[(int) $referralId] ?? [];
    $assessmentStatement = $database->prepare(
        'SELECT assessment.relevant_history, assessment.functional_status,
                assessment.assessment_findings, assessment.initial_goals,
                assessment.assessed_at, therapist.display_name AS therapist_name
         FROM therapy_assessments assessment
         INNER JOIN users therapist
            ON therapist.user_id = assessment.therapist_user_id
         WHERE assessment.therapy_referral_id = :referral_id
         LIMIT 1'
    );
    $assessmentStatement->execute(['referral_id' => (int) $referralId]);
    $referral['assessment'] = $assessmentStatement->fetch() ?: null;
    $planStatement = $database->prepare(
        'SELECT goals, interventions, frequency, duration, review_date,
                updated_at, therapist.display_name AS therapist_name
         FROM therapy_treatment_plans plan
         INNER JOIN users therapist ON therapist.user_id = plan.therapist_user_id
         WHERE plan.therapy_referral_id = :referral_id
         LIMIT 1'
    );
    $planStatement->execute(['referral_id' => (int) $referralId]);
    $referral['treatment_plan_record'] = $planStatement->fetch() ?: null;
    $progressStatement = $database->prepare(
        'SELECT progress.progress_id, progress.therapy_session_id,
                progress.patient_response, progress.progress_notes,
                progress.challenges, progress.follow_up_recommendations,
                progress.logged_at, therapist.display_name AS therapist_name
         FROM therapy_progress progress
         INNER JOIN users therapist
            ON therapist.user_id = progress.therapist_user_id
         WHERE progress.therapy_referral_id = :referral_id
         ORDER BY progress.logged_at, progress.progress_id'
    );
    $progressStatement->execute(['referral_id' => (int) $referralId]);
    $referral['progress'] = $progressStatement->fetchAll();
    $filesStatement = $database->prepare(
        'SELECT therapy_file_id, original_name, mime_type,
                file_size_bytes, uploaded_at
         FROM therapy_files
         WHERE therapy_referral_id = :referral_id
         ORDER BY uploaded_at, therapy_file_id'
    );
    $filesStatement->execute(['referral_id' => (int) $referralId]);
    $referral['files'] = array_map(static function (array $file): array {
        return [
            'therapy_file_id' => (int) $file['therapy_file_id'],
            'name' => $file['original_name'],
            'mime_type' => $file['mime_type'],
            'size_bytes' => (int) $file['file_size_bytes'],
            'uploaded_at' => $file['uploaded_at'],
        ];
    }, $filesStatement->fetchAll());
    $historyStatement = $database->prepare(
        'SELECT visit.visit_id, visit.arrived_at, visit.status,
                (SELECT diagnosis.diagnosis_description
                 FROM diagnoses diagnosis
                 WHERE diagnosis.visit_id = visit.visit_id
                 ORDER BY diagnosis.diagnosed_at DESC, diagnosis.diagnosis_id DESC
                 LIMIT 1) AS diagnosis
         FROM visits visit
         WHERE visit.patient_id = :patient_id
           AND visit.visit_id <> :current_visit_id
         ORDER BY visit.arrived_at DESC, visit.visit_id DESC
         LIMIT 25'
    );
    $historyStatement->execute([
        'patient_id' => (int) $row['patient_id'],
        'current_visit_id' => (int) $row['visit_id'],
    ]);
    $referral['patient_history'] = array_map(static function (array $history): array {
        return [
            'visit_id' => (int) $history['visit_id'],
            'arrived_at' => $history['arrived_at'],
            'status' => $history['status'],
            'diagnosis' => $history['diagnosis'],
        ];
    }, $historyStatement->fetchAll());
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
                visit.status AS visit_status,
                referral.assigned_therapist_user_id,
                referral.review_status
         FROM therapy_referrals referral
         INNER JOIN visits visit ON visit.visit_id = referral.visit_id
         WHERE referral.therapy_referral_id = :referral_id
         LIMIT 1
         FOR UPDATE'
    );
    $statement->execute(['referral_id' => $referralId]);

    return $statement->fetch();
}

function therapyValidateReferralId(string $referralId): int
{
    if (!ctype_digit($referralId) || (int) $referralId < 1) {
        jsonError('A valid therapy referral ID is required.', [
            'therapy_referral_id' => 'Enter a positive referral ID.',
        ], 422);
    }

    return (int) $referralId;
}

function therapyRequireReferralOwner(array $referral, array $user): void
{
    if ($user['role_name'] !== 'system_administrator'
        && $referral['assigned_therapist_user_id'] !== null
        && (int) $referral['assigned_therapist_user_id'] !== (int) $user['user_id']) {
        jsonError('This referral is assigned to another therapist.', [], 403);
    }
}

function therapyTextFields(array $input, array $definitions): array
{
    $values = [];
    $errors = [];
    foreach ($definitions as $field => [$label, $maximum, $required]) {
        $value = $input[$field] ?? null;
        if ($value === null || $value === '') {
            if ($required) {
                $errors[$field] = $label . ' is required.';
            }
            $values[$field] = null;
            continue;
        }
        if (!is_string($value) || strlen(trim($value)) > $maximum) {
            $errors[$field] = $label . ' must be text of '
                . $maximum . ' characters or fewer.';
            continue;
        }
        $values[$field] = trim($value) === '' ? null : trim($value);
        if ($required && $values[$field] === null) {
            $errors[$field] = $label . ' is required.';
        }
    }
    if ($errors !== []) {
        jsonError('Please correct the therapy information.', $errors, 422);
    }

    return $values;
}

function handleTherapyReferralAccept(string $referralId): void
{
    $id = therapyValidateReferralId($referralId);
    $database = getDatabaseConnection();
    $user = requireTherapyAccess($database);
    requireCsrfRequest();
    try {
        $database->beginTransaction();
        $referral = therapyReferralForUpdate($database, $id);
        if ($referral === false) {
            $database->rollBack();
            jsonError('Therapy referral not found.', [], 404);
        }
        therapyRequireReferralOwner($referral, $user);
        if ($referral['visit_status'] !== 'awaiting_therapy'
            || $referral['referral_status'] !== 'referred') {
            $database->rollBack();
            jsonError('Only a new active referral can be accepted.', [], 409);
        }
        $update = $database->prepare(
            "UPDATE therapy_referrals
             SET assigned_therapist_user_id = :therapist_id,
                 review_status = 'accepted',
                 flagged_reason = NULL,
                 accepted_at = COALESCE(accepted_at, CURRENT_TIMESTAMP)
             WHERE therapy_referral_id = :referral_id
               AND (assigned_therapist_user_id IS NULL
                    OR assigned_therapist_user_id = :same_therapist_id)"
        );
        $update->execute([
            'therapist_id' => (int) $user['user_id'],
            'referral_id' => $id,
            'same_therapist_id' => (int) $user['user_id'],
        ]);
        if ($update->rowCount() !== 1) {
            $database->rollBack();
            jsonError('This referral was accepted by another therapist.', [], 409);
        }
        recordTherapyAudit(
            $database,
            (int) $user['user_id'],
            'therapy.referral.accepted',
            (int) $referral['patient_id'],
            (int) $referral['visit_id']
        );
        $database->commit();
    } catch (Throwable $exception) {
        if ($database->inTransaction()) {
            $database->rollBack();
        }
        error_log('Therapy referral acceptance failed: ' . $exception->getMessage());
        jsonError('Unable to accept this therapy referral right now.', [], 500);
    }

    jsonSuccess('Therapy referral accepted and assigned to you.', [
        'therapy_referral_id' => $id,
        'assigned_therapist_user_id' => (int) $user['user_id'],
        'review_status' => 'accepted',
    ]);
}

function handleTherapyReferralFlag(string $referralId): void
{
    $id = therapyValidateReferralId($referralId);
    $database = getDatabaseConnection();
    $user = requireTherapyAccess($database);
    requireCsrfRequest();
    $input = readJsonRequest();
    $values = therapyTextFields($input, [
        'reason' => ['Reason for flagging', 2000, true],
    ]);
    try {
        $database->beginTransaction();
        $referral = therapyReferralForUpdate($database, $id);
        if ($referral === false) {
            $database->rollBack();
            jsonError('Therapy referral not found.', [], 404);
        }
        therapyRequireReferralOwner($referral, $user);
        if ($referral['visit_status'] !== 'awaiting_therapy'
            || $referral['referral_status'] !== 'referred') {
            $database->rollBack();
            jsonError('Only a new active referral can be flagged.', [], 409);
        }
        $update = $database->prepare(
            "UPDATE therapy_referrals
             SET assigned_therapist_user_id = :therapist_id,
                 review_status = 'flagged', flagged_reason = :reason
             WHERE therapy_referral_id = :referral_id
               AND (assigned_therapist_user_id IS NULL
                    OR assigned_therapist_user_id = :same_therapist_id)"
        );
        $update->execute([
            'therapist_id' => (int) $user['user_id'],
            'reason' => $values['reason'],
            'referral_id' => $id,
            'same_therapist_id' => (int) $user['user_id'],
        ]);
        if ($update->rowCount() !== 1) {
            $database->rollBack();
            jsonError('This referral has been assigned to another therapist.', [], 409);
        }
        recordTherapyAudit(
            $database,
            (int) $user['user_id'],
            'therapy.referral.flagged',
            (int) $referral['patient_id'],
            (int) $referral['visit_id']
        );
        $database->commit();
    } catch (Throwable $exception) {
        if ($database->inTransaction()) {
            $database->rollBack();
        }
        error_log('Therapy referral flagging failed: ' . $exception->getMessage());
        jsonError('Unable to flag this therapy referral right now.', [], 500);
    }

    jsonSuccess('Therapy referral flagged for review.', [
        'therapy_referral_id' => $id,
        'review_status' => 'flagged',
    ]);
}

function handleTherapyAssessmentSave(string $referralId): void
{
    $id = therapyValidateReferralId($referralId);
    $database = getDatabaseConnection();
    $user = requireTherapyAccess($database);
    requireCsrfRequest();
    $values = therapyTextFields(readJsonRequest(), [
        'relevant_history' => ['Relevant history', 5000, false],
        'functional_status' => ['Functional status', 5000, true],
        'assessment_findings' => ['Assessment findings', 10000, true],
        'initial_goals' => ['Initial goals', 5000, true],
    ]);
    try {
        $database->beginTransaction();
        $referral = therapyReferralForUpdate($database, $id);
        if ($referral === false) {
            $database->rollBack();
            jsonError('Therapy referral not found.', [], 404);
        }
        therapyRequireReferralOwner($referral, $user);
        if ($referral['visit_status'] !== 'awaiting_therapy'
            || !in_array($referral['referral_status'], ['referred', 'in_progress'], true)
            || $referral['review_status'] !== 'accepted') {
            $database->rollBack();
            jsonError('Accept this referral before recording an assessment.', [], 409);
        }
        $statement = $database->prepare(
            'INSERT INTO therapy_assessments
                (therapy_referral_id, therapist_user_id, relevant_history,
                 functional_status, assessment_findings, initial_goals)
             VALUES
                (:referral_id, :therapist_id, :relevant_history,
                 :functional_status, :assessment_findings, :initial_goals)
             ON DUPLICATE KEY UPDATE
                therapist_user_id = VALUES(therapist_user_id),
                relevant_history = VALUES(relevant_history),
                functional_status = VALUES(functional_status),
                assessment_findings = VALUES(assessment_findings),
                initial_goals = VALUES(initial_goals),
                assessed_at = CURRENT_TIMESTAMP'
        );
        $statement->execute([
            'referral_id' => $id,
            'therapist_id' => (int) $user['user_id'],
            'relevant_history' => $values['relevant_history'],
            'functional_status' => $values['functional_status'],
            'assessment_findings' => $values['assessment_findings'],
            'initial_goals' => $values['initial_goals'],
        ]);
        $database->prepare(
            "UPDATE therapy_referrals
             SET status = 'in_progress'
             WHERE therapy_referral_id = :referral_id AND status = 'referred'"
        )->execute(['referral_id' => $id]);
        recordTherapyAudit(
            $database,
            (int) $user['user_id'],
            'therapy.assessment.saved',
            (int) $referral['patient_id'],
            (int) $referral['visit_id']
        );
        $database->commit();
    } catch (Throwable $exception) {
        if ($database->inTransaction()) {
            $database->rollBack();
        }
        error_log('Therapy assessment save failed: ' . $exception->getMessage());
        jsonError('Unable to save the therapy assessment right now.', [], 500);
    }

    jsonSuccess('Therapy assessment saved.', [
        'therapy_referral_id' => $id,
    ]);
}

function handleTherapyTreatmentPlanSave(string $referralId): void
{
    $id = therapyValidateReferralId($referralId);
    $database = getDatabaseConnection();
    $user = requireTherapyAccess($database);
    requireCsrfRequest();
    $input = readJsonRequest();
    $values = therapyTextFields($input, [
        'goals' => ['Treatment goals', 10000, true],
        'interventions' => ['Planned interventions', 10000, true],
        'frequency' => ['Session frequency', 150, false],
        'duration' => ['Treatment duration', 150, false],
    ]);
    $reviewDate = $input['review_date'] ?? null;
    $parsedReviewDate = is_string($reviewDate)
        ? DateTime::createFromFormat('!Y-m-d', $reviewDate)
        : false;
    $dateErrors = DateTime::getLastErrors();
    if ($reviewDate !== null && (!is_string($reviewDate)
        || preg_match('/^\d{4}-\d{2}-\d{2}$/', $reviewDate) !== 1
        || $parsedReviewDate === false
        || $parsedReviewDate->format('Y-m-d') !== $reviewDate
        || ($dateErrors !== false
            && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0)))) {
        jsonError('Please correct the treatment plan.', [
            'review_date' => 'Enter a valid review date.',
        ], 422);
    }
    try {
        $database->beginTransaction();
        $referral = therapyReferralForUpdate($database, $id);
        if ($referral === false) {
            $database->rollBack();
            jsonError('Therapy referral not found.', [], 404);
        }
        therapyRequireReferralOwner($referral, $user);
        if ($referral['visit_status'] !== 'awaiting_therapy'
            || !in_array($referral['referral_status'], ['referred', 'in_progress'], true)
            || $referral['review_status'] !== 'accepted') {
            $database->rollBack();
            jsonError('Accept this referral before updating a treatment plan.', [], 409);
        }
        $statement = $database->prepare(
            'INSERT INTO therapy_treatment_plans
                (therapy_referral_id, therapist_user_id, goals, interventions,
                 frequency, duration, review_date)
             VALUES
                (:referral_id, :therapist_id, :goals, :interventions,
                 :frequency, :duration, :review_date)
             ON DUPLICATE KEY UPDATE
                therapist_user_id = VALUES(therapist_user_id),
                goals = VALUES(goals),
                interventions = VALUES(interventions),
                frequency = VALUES(frequency),
                duration = VALUES(duration),
                review_date = VALUES(review_date)'
        );
        $statement->execute([
            'referral_id' => $id,
            'therapist_id' => (int) $user['user_id'],
            'goals' => $values['goals'],
            'interventions' => $values['interventions'],
            'frequency' => $values['frequency'],
            'duration' => $values['duration'],
            'review_date' => $reviewDate,
        ]);
        recordTherapyAudit(
            $database,
            (int) $user['user_id'],
            'therapy.treatment_plan.saved',
            (int) $referral['patient_id'],
            (int) $referral['visit_id']
        );
        $database->commit();
    } catch (Throwable $exception) {
        if ($database->inTransaction()) {
            $database->rollBack();
        }
        error_log('Therapy treatment plan save failed: ' . $exception->getMessage());
        jsonError('Unable to save the treatment plan right now.', [], 500);
    }

    jsonSuccess('Treatment plan saved.', [
        'therapy_referral_id' => $id,
    ]);
}

function handleTherapyProgressSave(string $referralId): void
{
    $id = therapyValidateReferralId($referralId);
    $database = getDatabaseConnection();
    $user = requireTherapyAccess($database);
    requireCsrfRequest();
    $input = readJsonRequest();
    $values = therapyTextFields($input, [
        'patient_response' => ['Patient response', 5000, true],
        'progress_notes' => ['Progress notes', 10000, true],
        'challenges' => ['Challenges', 5000, false],
        'follow_up_recommendations' => ['Follow-up recommendations', 5000, false],
    ]);
    $sessionId = $input['therapy_session_id'] ?? null;
    if ($sessionId !== null && ((!is_int($sessionId)
        && !(is_string($sessionId) && ctype_digit($sessionId)))
        || (int) $sessionId < 1)) {
        jsonError('Please correct the progress record.', [
            'therapy_session_id' => 'Choose a valid session.',
        ], 422);
    }
    try {
        $database->beginTransaction();
        $referral = therapyReferralForUpdate($database, $id);
        if ($referral === false) {
            $database->rollBack();
            jsonError('Therapy referral not found.', [], 404);
        }
        therapyRequireReferralOwner($referral, $user);
        if ($referral['visit_status'] !== 'awaiting_therapy'
            || !in_array($referral['referral_status'], ['referred', 'in_progress'], true)
            || $referral['review_status'] !== 'accepted') {
            $database->rollBack();
            jsonError('Only an active assigned therapy case can receive progress notes.', [], 409);
        }
        if ($sessionId !== null) {
            $sessionStatement = $database->prepare(
                'SELECT therapy_session_id
                 FROM therapy_sessions
                 WHERE therapy_session_id = :session_id
                   AND therapy_referral_id = :referral_id
                 LIMIT 1'
            );
            $sessionStatement->execute([
                'session_id' => (int) $sessionId,
                'referral_id' => $id,
            ]);
            if ($sessionStatement->fetchColumn() === false) {
                $database->rollBack();
                jsonError('The selected session does not belong to this referral.', [], 422);
            }
        }
        $statement = $database->prepare(
            'INSERT INTO therapy_progress
                (therapy_referral_id, therapy_session_id, therapist_user_id,
                 patient_response, progress_notes, challenges,
                 follow_up_recommendations)
             VALUES
                (:referral_id, :session_id, :therapist_id,
                 :patient_response, :progress_notes, :challenges,
                 :follow_up_recommendations)'
        );
        $statement->execute([
            'referral_id' => $id,
            'session_id' => $sessionId === null ? null : (int) $sessionId,
            'therapist_id' => (int) $user['user_id'],
            'patient_response' => $values['patient_response'],
            'progress_notes' => $values['progress_notes'],
            'challenges' => $values['challenges'],
            'follow_up_recommendations' => $values['follow_up_recommendations'],
        ]);
        recordTherapyAudit(
            $database,
            (int) $user['user_id'],
            'therapy.progress.saved',
            (int) $referral['patient_id'],
            (int) $referral['visit_id']
        );
        $database->commit();
    } catch (Throwable $exception) {
        if ($database->inTransaction()) {
            $database->rollBack();
        }
        error_log('Therapy progress save failed: ' . $exception->getMessage());
        jsonError('Unable to save therapy progress right now.', [], 500);
    }

    $progressId = (int) $database->lastInsertId();
    jsonSuccess('Therapy progress saved.', [
        'therapy_referral_id' => $id,
        'progress_id' => $progressId,
    ], 201);
}

function handleTherapySessionCreate(string $referralId): void
{
    $referralId = (string) therapyValidateReferralId($referralId);

    $database = getDatabaseConnection();
    $user = requireTherapyAccess($database);
    requireCsrfRequest();
    $input = readJsonRequest();
    $values = therapyTextFields($input, [
        'session_notes' => ['Session notes', 5000, true],
        'activities_performed' => ['Activities performed', 5000, false],
        'observations' => ['Observations', 5000, false],
        'patient_response' => ['Patient response', 5000, false],
        'progress_notes' => ['Progress notes', 5000, false],
        'challenges' => ['Challenges', 5000, false],
        'follow_up_recommendations' => ['Follow-up recommendations', 5000, false],
    ]);
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
    $sessionAt = $input['session_at'] ?? null;
    if ($sessionAt !== null) {
        $parsedSessionAt = is_string($sessionAt)
            ? DateTime::createFromFormat('!Y-m-d\TH:i', $sessionAt)
            : false;
        $dateErrors = DateTime::getLastErrors();
        if ($parsedSessionAt === false
            || $parsedSessionAt->format('Y-m-d\TH:i') !== $sessionAt
            || ($dateErrors !== false
                && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0))) {
            jsonError('Please correct the session date and time.', [
                'session_at' => 'Enter a valid local date and time.',
            ], 422);
        }
        $sessionAt = $parsedSessionAt->format('Y-m-d H:i:s');
    }

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
        therapyRequireReferralOwner($referral, $user);

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
            if ($referral['review_status'] === 'flagged') {
                $database->rollBack();
                jsonError('A flagged referral must be accepted before recording a session.', [], 409);
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
                     activities_performed, observations, patient_response,
                     progress_notes, challenges, follow_up_recommendations,
                     session_completed, session_at, client_request_id)
                 VALUES
                    (:referral_id, :therapist_user_id, :session_notes,
                     :activities_performed, :observations, :patient_response,
                     :progress_notes, :challenges, :follow_up_recommendations,
                     1, COALESCE(:session_at, CURRENT_TIMESTAMP), :client_request_id)'
            );
            $sessionInsert->execute([
                'referral_id' => (int) $referralId,
                'therapist_user_id' => (int) $user['user_id'],
                'session_notes' => $values['session_notes'],
                'activities_performed' => $values['activities_performed'],
                'observations' => $values['observations'],
                'patient_response' => $values['patient_response'],
                'progress_notes' => $values['progress_notes'],
                'challenges' => $values['challenges'],
                'follow_up_recommendations' => $values['follow_up_recommendations'],
                'session_at' => $sessionAt,
                'client_request_id' => $requestId,
            ]);
            $sessionId = (int) $database->lastInsertId();

            $referralUpdate = $database->prepare(
                "UPDATE therapy_referrals
                 SET status = 'in_progress',
                     assigned_therapist_user_id = :therapist_id,
                     review_status = 'accepted',
                     accepted_at = COALESCE(accepted_at, CURRENT_TIMESTAMP)
                 WHERE therapy_referral_id = :referral_id
                   AND status IN ('referred', 'in_progress')
                   AND (assigned_therapist_user_id IS NULL
                        OR assigned_therapist_user_id = :same_therapist_id)"
            );
            $referralUpdate->execute([
                'therapist_id' => (int) $user['user_id'],
                'referral_id' => (int) $referralId,
                'same_therapist_id' => (int) $user['user_id'],
            ]);

            if ($values['patient_response'] !== null
                || $values['progress_notes'] !== null
                || $values['challenges'] !== null
                || $values['follow_up_recommendations'] !== null) {
                $progressInsert = $database->prepare(
                    'INSERT INTO therapy_progress
                        (therapy_referral_id, therapy_session_id, therapist_user_id,
                         patient_response, progress_notes, challenges,
                         follow_up_recommendations)
                     VALUES
                        (:referral_id, :session_id, :therapist_id,
                         :patient_response, :progress_notes, :challenges,
                         :follow_up_recommendations)'
                );
                $progressInsert->execute([
                    'referral_id' => (int) $referralId,
                    'session_id' => $sessionId,
                    'therapist_id' => (int) $user['user_id'],
                    'patient_response' => $values['patient_response'] ?? 'Not recorded.',
                    'progress_notes' => $values['progress_notes'] ?? 'Not recorded.',
                    'challenges' => $values['challenges'],
                    'follow_up_recommendations' => $values['follow_up_recommendations'],
                ]);
            }

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

function therapyStorageDirectory(): string
{
    $configuredPath = getenv('THERAPY_STORAGE_PATH');
    $directory = is_string($configuredPath) && trim($configuredPath) !== ''
        ? trim($configuredPath)
        : dirname(__DIR__, 4) . DIRECTORY_SEPARATOR
            . 'hospital-system-private' . DIRECTORY_SEPARATOR . 'therapy';
    if (!is_dir($directory)
        && !mkdir($directory, 0700, true)
        && !is_dir($directory)) {
        throw new RuntimeException('Unable to create the private therapy file directory.');
    }

    $realDirectory = realpath($directory);
    $documentRoot = realpath($_SERVER['DOCUMENT_ROOT'] ?? '');
    if ($realDirectory === false || !is_writable($realDirectory)) {
        throw new RuntimeException('The private therapy file directory is unavailable.');
    }
    if ($documentRoot !== false) {
        $normalizedDirectory = rtrim(
            strtolower(str_replace('\\', '/', $realDirectory)),
            '/'
        );
        $normalizedDocumentRoot = rtrim(
            strtolower(str_replace('\\', '/', $documentRoot)),
            '/'
        );
        if ($normalizedDirectory === $normalizedDocumentRoot
            || strpos($normalizedDirectory, $normalizedDocumentRoot . '/') === 0) {
            throw new RuntimeException('Therapy files must be stored outside the public document root.');
        }
    }

    return $realDirectory;
}

function handleTherapyFileUpload(string $referralId): void
{
    $id = therapyValidateReferralId($referralId);
    $database = getDatabaseConnection();
    $user = requireTherapyAccess($database);
    requireCsrfRequest();
    $upload = $_FILES['file'] ?? null;
    if (!is_array($upload) || !isset(
        $upload['name'],
        $upload['tmp_name'],
        $upload['error'],
        $upload['size']
    ) || $upload['error'] !== UPLOAD_ERR_OK
        || !is_uploaded_file((string) $upload['tmp_name'])) {
        jsonError('Choose a therapy document to upload.', [
            'file' => 'The selected file could not be uploaded.',
        ], 422);
    }
    if ((int) $upload['size'] < 1 || (int) $upload['size'] > 10485760) {
        jsonError('Therapy files must be 10 MB or smaller.', [
            'file' => 'Choose a file up to 10 MB.',
        ], 422);
    }
    $originalName = basename(str_replace('\\', '/', (string) $upload['name']));
    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $allowedTypes = [
        'pdf' => 'application/pdf',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'txt' => 'text/plain',
    ];
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = $finfo->file((string) $upload['tmp_name']);
    if (!isset($allowedTypes[$extension]) || $mimeType !== $allowedTypes[$extension]) {
        jsonError('Unsupported therapy document type.', [
            'file' => 'Use a PDF, JPG, PNG, or plain-text file.',
        ], 422);
    }
    $originalName = preg_replace('/[\x00-\x1F\x7F]/', '', $originalName);
    $originalName = is_string($originalName) && trim($originalName) !== ''
        ? substr(trim($originalName), 0, 255)
        : 'therapy-document.' . $extension;

    $storedPath = null;
    try {
        $directory = therapyStorageDirectory();
        $database->beginTransaction();
        $referral = therapyReferralForUpdate($database, $id);
        if ($referral === false) {
            $database->rollBack();
            jsonError('Therapy referral not found.', [], 404);
        }
        therapyRequireReferralOwner($referral, $user);
        if ($referral['visit_status'] !== 'awaiting_therapy'
            || !in_array($referral['referral_status'], ['referred', 'in_progress'], true)
            || $referral['review_status'] !== 'accepted') {
            $database->rollBack();
            jsonError('Accept this referral before attaching a file.', [], 409);
        }

        $storageKey = bin2hex(random_bytes(24));
        $storedPath = $directory . DIRECTORY_SEPARATOR . $storageKey;
        if (!move_uploaded_file((string) $upload['tmp_name'], $storedPath)) {
            throw new RuntimeException('Unable to move the therapy file to private storage.');
        }
        $statement = $database->prepare(
            'INSERT INTO therapy_files
                (therapy_referral_id, uploaded_by_user_id, original_name,
                 storage_key, mime_type, file_size_bytes)
             VALUES
                (:referral_id, :user_id, :original_name, :storage_key,
                 :mime_type, :file_size_bytes)'
        );
        $statement->execute([
            'referral_id' => $id,
            'user_id' => (int) $user['user_id'],
            'original_name' => $originalName,
            'storage_key' => $storageKey,
            'mime_type' => $mimeType,
            'file_size_bytes' => (int) $upload['size'],
        ]);
        $fileId = (int) $database->lastInsertId();
        recordTherapyAudit(
            $database,
            (int) $user['user_id'],
            'therapy.file.uploaded',
            (int) $referral['patient_id'],
            (int) $referral['visit_id']
        );
        $database->commit();
    } catch (Throwable $exception) {
        if ($database->inTransaction()) {
            $database->rollBack();
        }
        if ($storedPath !== null && is_file($storedPath) && !unlink($storedPath)) {
            error_log('Unable to remove an unrecorded therapy file.');
        }
        error_log('Therapy file upload failed: ' . $exception->getMessage());
        jsonError('Unable to save the therapy file right now.', [], 500);
    }

    jsonSuccess('Therapy document uploaded.', [
        'therapy_file_id' => $fileId,
        'name' => $originalName,
    ], 201);
}

function handleTherapyFileGet(string $fileId): void
{
    if (!ctype_digit($fileId) || (int) $fileId < 1) {
        jsonError('A valid therapy file ID is required.', [], 400);
    }
    $database = getDatabaseConnection();
    $user = requireTherapyReadAccess($database);
    $statement = $database->prepare(
        'SELECT file.therapy_file_id, file.therapy_referral_id,
                file.original_name, file.storage_key, file.mime_type,
                file.file_size_bytes, referral.assigned_therapist_user_id,
                referral.referred_by_user_id, visit.patient_id, visit.visit_id
         FROM therapy_files file
         INNER JOIN therapy_referrals referral
            ON referral.therapy_referral_id = file.therapy_referral_id
         INNER JOIN visits visit ON visit.visit_id = referral.visit_id
         WHERE file.therapy_file_id = :file_id
         LIMIT 1'
    );
    $statement->execute(['file_id' => (int) $fileId]);
    $file = $statement->fetch();
    if ($file === false) {
        jsonError('Therapy file not found.', [], 404);
    }
    if ($user['role_name'] === 'therapist') {
        therapyRequireReferralOwner([
            'assigned_therapist_user_id' => $file['assigned_therapist_user_id'],
        ], $user);
    } elseif ($user['role_name'] !== 'system_administrator'
        && (int) $file['referred_by_user_id'] !== (int) $user['user_id']) {
        jsonError('Only the referring clinician can review this therapy file.', [], 403);
    }
    try {
        $directory = therapyStorageDirectory();
    } catch (Throwable $exception) {
        error_log('Therapy private storage is unavailable: ' . $exception->getMessage());
        jsonError('The therapy file is unavailable.', [], 500);
    }
    $storageKey = $file['storage_key'];
    if (!is_string($storageKey) || preg_match('/^[a-f0-9]{48}$/', $storageKey) !== 1) {
        error_log('Invalid therapy storage key encountered.');
        jsonError('The therapy file is unavailable.', [], 500);
    }
    $path = $directory . DIRECTORY_SEPARATOR . $storageKey;
    if (!is_file($path) || !is_readable($path)) {
        jsonError('The therapy file is unavailable.', [], 404);
    }
    recordTherapyAudit(
        $database,
        (int) $user['user_id'],
        'therapy.file.viewed',
        (int) $file['patient_id'],
        (int) $file['visit_id']
    );
    $safeName = preg_replace('/[^A-Za-z0-9._-]/', '_', $file['original_name']);
    $safeName = is_string($safeName) && $safeName !== '' ? $safeName : 'therapy-file';
    header('Content-Type: ' . $file['mime_type']);
    header('Content-Length: ' . (int) $file['file_size_bytes']);
    header('Content-Disposition: attachment; filename="' . $safeName . '"; filename*=UTF-8\'\''
        . rawurlencode($file['original_name']));
    header('Cache-Control: no-store, private');
    header('X-Content-Type-Options: nosniff');
    readfile($path);
    exit;
}

function handleTherapyComplete(string $referralId): void
{
    $id = therapyValidateReferralId($referralId);
    $database = getDatabaseConnection();
    $user = requireTherapyAccess($database);
    requireCsrfRequest();
    $values = therapyTextFields(readJsonRequest(), [
        'outcome' => ['Therapy outcome', 5000, true],
        'discharge_notes' => ['Discharge notes', 10000, true],
    ]);

    try {
        $database->beginTransaction();
        $referral = therapyReferralForUpdate($database, $id);
        if ($referral === false) {
            $database->rollBack();
            jsonError('Therapy referral not found.', [], 404);
        }
        therapyRequireReferralOwner($referral, $user);
        if ($referral['visit_status'] !== 'awaiting_therapy'
            || !in_array($referral['referral_status'], ['referred', 'in_progress'], true)) {
            $database->rollBack();
            jsonError('Only an active therapy referral can be completed.', [], 409);
        }
        if ($referral['review_status'] !== 'accepted') {
            $database->rollBack();
            jsonError('Accept this referral before completing therapy.', [], 409);
        }

        $assessmentStatement = $database->prepare(
            'SELECT 1 FROM therapy_assessments
             WHERE therapy_referral_id = :referral_id LIMIT 1'
        );
        $assessmentStatement->execute(['referral_id' => $id]);
        $planStatement = $database->prepare(
            'SELECT 1 FROM therapy_treatment_plans
             WHERE therapy_referral_id = :referral_id LIMIT 1'
        );
        $planStatement->execute(['referral_id' => $id]);
        if ($assessmentStatement->fetchColumn() === false
            || $planStatement->fetchColumn() === false) {
            $database->rollBack();
            jsonError(
                'Save the initial assessment and treatment plan before completing therapy.',
                [],
                409
            );
        }

        $sessionCountStatement = $database->prepare(
            'SELECT COUNT(*)
             FROM therapy_sessions
             WHERE therapy_referral_id = :referral_id'
        );
        $sessionCountStatement->execute(['referral_id' => $id]);
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
            "UPDATE therapy_referrals
             SET status = 'completed',
                 completed_at = CURRENT_TIMESTAMP,
                 outcome = :outcome,
                 discharge_notes = :discharge_notes
             WHERE therapy_referral_id = :referral_id"
        );
        $referralUpdate->execute([
            'outcome' => $values['outcome'],
            'discharge_notes' => $values['discharge_notes'],
            'referral_id' => $id,
        ]);
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
        'therapy_referral_id' => $id,
        'visit_id' => (int) $referral['visit_id'],
        'referral_status' => 'completed',
        'visit_status' => $nextVisitStatus,
        'outcome' => $values['outcome'],
        'discharge_notes' => $values['discharge_notes'],
    ]);
}
