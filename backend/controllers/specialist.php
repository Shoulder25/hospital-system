<?php

declare(strict_types=1);

function requireSpecialistAccess(PDO $database): array
{
    $user = getAuthenticatedUser($database);
    if ($user === null) {
        jsonError('Authentication is required.', [], 401);
    }

    if (!in_array($user['role_name'], [
        'resident_specialist',
        'locum_specialist',
        'system_administrator',
    ], true)) {
        jsonError('Specialist access is required.', [], 403);
    }

    return $user;
}

function specialistRecordAudit(
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

function specialistReferralForUpdate(
    PDO $database,
    int $referralId,
    array $user
): array {
    $statement = $database->prepare(
        'SELECT referral.specialist_referral_id, referral.visit_id,
                referral.source_consultation_id, referral.specialist_user_id,
                referral.reason, referral.clinical_summary, referral.status,
                visit.patient_id, visit.status AS visit_status,
                patient.patient_number, patient.first_name, patient.last_name,
                patient.date_of_birth, patient.gender, patient.is_temporary
         FROM specialist_referrals referral
         INNER JOIN visits visit ON visit.visit_id = referral.visit_id
         INNER JOIN patients patient ON patient.patient_id = visit.patient_id
         WHERE referral.specialist_referral_id = :referral_id
         LIMIT 1
         FOR UPDATE'
    );
    $statement->execute(['referral_id' => $referralId]);
    $referral = $statement->fetch();
    if ($referral === false) {
        jsonError('Specialist referral not found.', [], 404);
    }

    if ($user['role_name'] !== 'system_administrator'
        && (int) $referral['specialist_user_id'] !== (int) $user['user_id']) {
        jsonError('This referral is assigned to another specialist.', [], 403);
    }

    return $referral;
}

function specialistReferralDetails(
    PDO $database,
    array $referral,
    int $viewerUserId
): array
{
    $visitId = (int) $referral['visit_id'];

    $assessmentStatement = $database->prepare(
        'SELECT assessment_id, nurse_user_id, complaint, symptoms,
                symptom_duration, blood_pressure, pulse, temperature,
                respiratory_rate, oxygen_saturation, weight, height, notes,
                decision, assessed_at
         FROM nurse_assessments
         WHERE visit_id = :visit_id
         ORDER BY assessed_at, assessment_id'
    );
    $assessmentStatement->execute(['visit_id' => $visitId]);

    $testsStatement = $database->prepare(
        'SELECT test.test_order_id, test.test_type, test.department,
                test.status AS order_status, test.ordered_at,
                result.test_result_id, result.result_text, result.result_notes,
                result.status AS result_status, result.recorded_at,
                recorder.display_name AS recorded_by
         FROM test_orders test
         LEFT JOIN test_results result ON result.test_order_id = test.test_order_id
         LEFT JOIN users recorder ON recorder.user_id = result.recorded_by_user_id
         WHERE test.visit_id = :visit_id
         ORDER BY test.ordered_at, test.test_order_id, result.recorded_at'
    );
    $testsStatement->execute(['visit_id' => $visitId]);

    $consultationsStatement = $database->prepare(
        'SELECT consultation.consultation_id, consultation.consultation_type,
                consultation.presenting_complaint,
                consultation.clinical_observations,
                consultation.examination_findings, consultation.notes,
                consultation.diagnosis_made, consultation.diagnosis_text,
                consultation.treatment_plan, consultation.next_step,
                consultation.consulted_at, clinician.display_name AS clinician_name
         FROM consultations consultation
         INNER JOIN users clinician ON clinician.user_id = consultation.clinician_user_id
         WHERE consultation.visit_id = :visit_id
         ORDER BY consultation.consulted_at, consultation.consultation_id'
    );
    $consultationsStatement->execute(['visit_id' => $visitId]);
    $consultations = $consultationsStatement->fetchAll();

    $diagnosesStatement = $database->prepare(
        'SELECT diagnosis.diagnosis_id, diagnosis.consultation_id,
                diagnosis.diagnosis_made, diagnosis.diagnosis_description,
                diagnosis.clinical_findings, diagnosis.treatment_plan,
                diagnosis.notes, diagnosis.diagnosed_at,
                clinician.display_name AS diagnosed_by
         FROM diagnoses diagnosis
         INNER JOIN users clinician
            ON clinician.user_id = diagnosis.diagnosed_by_user_id
         WHERE diagnosis.visit_id = :visit_id
         ORDER BY diagnosis.diagnosed_at, diagnosis.diagnosis_id'
    );
    $diagnosesStatement->execute(['visit_id' => $visitId]);

    $sourceStatement = $database->prepare(
        'SELECT consultation.consultation_id, consultation.presenting_complaint,
                consultation.clinical_observations, consultation.examination_findings,
                consultation.notes, consultation.diagnosis_text,
                consultation.treatment_plan, consultation.consulted_at,
                clinician.display_name AS clinician_name,
                diagnosis.diagnosis_description, diagnosis.clinical_findings,
                diagnosis.notes AS diagnosis_notes
         FROM consultations consultation
         INNER JOIN users clinician ON clinician.user_id = consultation.clinician_user_id
         LEFT JOIN diagnoses diagnosis
            ON diagnosis.consultation_id = consultation.consultation_id
         WHERE consultation.consultation_id = :consultation_id
           AND consultation.visit_id = :visit_id
         LIMIT 1'
    );
    $sourceStatement->execute([
        'consultation_id' => (int) $referral['source_consultation_id'],
        'visit_id' => $visitId,
    ]);

    $therapyStatement = $database->prepare(
        'SELECT therapy.therapy_referral_id, therapy.therapy_type,
                therapy.reason, therapy.status, therapy.review_status,
                therapy.outcome, therapy.discharge_notes, therapy.completed_at,
                assigned.display_name AS therapist_name
         FROM therapy_referrals therapy
         LEFT JOIN users assigned
            ON assigned.user_id = therapy.assigned_therapist_user_id
         WHERE therapy.visit_id = :visit_id
           AND therapy.referred_by_user_id = :referring_user_id
         ORDER BY therapy.created_at, therapy.therapy_referral_id'
    );
    $therapyStatement->execute([
        'visit_id' => $visitId,
        'referring_user_id' => $viewerUserId,
    ]);
    $therapyReports = $therapyStatement->fetchAll();
    $therapySessions = therapySessionsForReferrals(
        $database,
        array_map(
            static fn (array $therapy): int => (int) $therapy['therapy_referral_id'],
            $therapyReports
        )
    );
    foreach ($therapyReports as &$therapyReport) {
        $therapyId = (int) $therapyReport['therapy_referral_id'];
        $progressStatement = $database->prepare(
            'SELECT patient_response, progress_notes, challenges,
                    follow_up_recommendations, logged_at
             FROM therapy_progress
             WHERE therapy_referral_id = :referral_id
             ORDER BY logged_at, progress_id'
        );
        $progressStatement->execute(['referral_id' => $therapyId]);
        $therapyReport['sessions'] = $therapySessions[$therapyId] ?? [];
        $therapyReport['progress'] = $progressStatement->fetchAll();
    }
    unset($therapyReport);

    return [
        'referral' => [
            'specialist_referral_id' => (int) $referral['specialist_referral_id'],
            'status' => $referral['status'],
            'reason' => $referral['reason'],
            'clinical_summary' => $referral['clinical_summary'],
        ],
        'patient' => [
            'patient_id' => (int) $referral['patient_id'],
            'patient_number' => $referral['patient_number'],
            'name' => trim(
                (string) $referral['first_name'] . ' ' . (string) $referral['last_name']
            ),
            'date_of_birth' => $referral['date_of_birth'],
            'gender' => $referral['gender'],
            'is_temporary' => (bool) $referral['is_temporary'],
        ],
        'visit' => [
            'visit_id' => $visitId,
            'status' => $referral['visit_status'],
        ],
        'referring_consultation' => $sourceStatement->fetch() ?: null,
        'nursing_assessments' => $assessmentStatement->fetchAll(),
        'tests' => $testsStatement->fetchAll(),
        'consultations' => $consultations,
        'diagnoses' => $diagnosesStatement->fetchAll(),
        'therapy_reports' => $therapyReports,
    ];
}

function handleSpecialistReferralsGet(): void
{
    $database = getDatabaseConnection();
    $user = requireSpecialistAccess($database);

    $assignment = '';
    $parameters = [];
    if ($user['role_name'] !== 'system_administrator') {
        $assignment = ' AND referral.specialist_user_id = :specialist_user_id';
        $parameters['specialist_user_id'] = (int) $user['user_id'];
    }

    $statement = $database->prepare(
        'SELECT referral.specialist_referral_id, referral.visit_id,
                referral.source_consultation_id, referral.specialist_user_id,
                referral.reason, referral.clinical_summary, referral.status,
                referral.created_at, visit.patient_id, visit.status AS visit_status,
                patient.patient_number, patient.first_name, patient.last_name,
                patient.date_of_birth, patient.gender, patient.is_temporary,
                specialist.display_name AS specialist_name,
                role.role_name AS specialist_role
         FROM specialist_referrals referral
         INNER JOIN visits visit ON visit.visit_id = referral.visit_id
         INNER JOIN patients patient ON patient.patient_id = visit.patient_id
         INNER JOIN users specialist ON specialist.user_id = referral.specialist_user_id
         INNER JOIN roles role ON role.role_id = specialist.role_id
         WHERE referral.status IN (\'pending\', \'accepted\')'
            . $assignment .
        ' ORDER BY referral.created_at, referral.specialist_referral_id
          LIMIT 200'
    );
    $statement->execute($parameters);
    $referrals = [];
    foreach ($statement->fetchAll() as $row) {
        $referral = [
            'specialist_referral_id' => (int) $row['specialist_referral_id'],
            'visit_id' => (int) $row['visit_id'],
            'specialist_user_id' => (int) $row['specialist_user_id'],
            'specialist_name' => $row['specialist_name'],
            'specialist_role' => $row['specialist_role'],
            'created_at' => $row['created_at'],
            'patient_id' => (int) $row['patient_id'],
            'patient_number' => $row['patient_number'],
            'patient_name' => trim(
                (string) $row['first_name'] . ' ' . (string) $row['last_name']
            ),
            'date_of_birth' => $row['date_of_birth'],
            'gender' => $row['gender'],
            'is_temporary' => (bool) $row['is_temporary'],
            'details' => specialistReferralDetails(
                $database,
                $row,
                (int) $user['user_id']
            ),
        ];
        $referrals[] = $referral;
        specialistRecordAudit(
            $database,
            (int) $user['user_id'],
            'specialist.referral.viewed',
            (int) $row['patient_id'],
            (int) $row['visit_id']
        );
    }

    $medicinesStatement = $database->query(
        'SELECT medicine_id, medicine_name, unit_price, is_demo
         FROM medicines
         WHERE is_active = 1
         ORDER BY medicine_name'
    );
    $medicines = array_map(static function (array $medicine): array {
        return [
            'medicine_id' => (int) $medicine['medicine_id'],
            'medicine_name' => $medicine['medicine_name'],
            'unit_price' => (string) $medicine['unit_price'],
            'is_demo' => (bool) $medicine['is_demo'],
        ];
    }, $medicinesStatement->fetchAll());

    jsonSuccess('Assigned specialist referrals loaded.', [
        'referrals' => $referrals,
        'count' => count($referrals),
        'medicines' => $medicines,
    ]);
}

function handleSpecialistReferralGet(string $referralId): void
{
    if (!ctype_digit($referralId) || (int) $referralId < 1) {
        jsonError('A valid specialist referral ID is required.', [], 422);
    }

    $database = getDatabaseConnection();
    $user = requireSpecialistAccess($database);
    $statement = $database->prepare(
        'SELECT referral.specialist_referral_id, referral.visit_id,
                referral.source_consultation_id, referral.specialist_user_id,
                referral.reason, referral.clinical_summary, referral.status,
                referral.created_at, visit.patient_id, visit.status AS visit_status,
                patient.patient_number, patient.first_name, patient.last_name,
                patient.date_of_birth, patient.gender, patient.is_temporary,
                specialist.display_name AS specialist_name,
                role.role_name AS specialist_role
         FROM specialist_referrals referral
         INNER JOIN visits visit ON visit.visit_id = referral.visit_id
         INNER JOIN patients patient ON patient.patient_id = visit.patient_id
         INNER JOIN users specialist ON specialist.user_id = referral.specialist_user_id
         INNER JOIN roles role ON role.role_id = specialist.role_id
         WHERE referral.specialist_referral_id = :referral_id
           AND referral.status IN (\'pending\', \'accepted\')
         LIMIT 1'
    );
    $statement->execute(['referral_id' => (int) $referralId]);
    $row = $statement->fetch();
    if ($row === false) {
        jsonError('Active specialist referral not found.', [], 404);
    }
    if ($user['role_name'] !== 'system_administrator'
        && (int) $row['specialist_user_id'] !== (int) $user['user_id']) {
        jsonError('This referral is assigned to another specialist.', [], 403);
    }

    $referral = [
        'specialist_referral_id' => (int) $row['specialist_referral_id'],
        'visit_id' => (int) $row['visit_id'],
        'specialist_user_id' => (int) $row['specialist_user_id'],
        'specialist_name' => $row['specialist_name'],
        'specialist_role' => $row['specialist_role'],
        'created_at' => $row['created_at'],
        'patient_id' => (int) $row['patient_id'],
        'patient_number' => $row['patient_number'],
        'patient_name' => trim(
            (string) $row['first_name'] . ' ' . (string) $row['last_name']
        ),
        'details' => specialistReferralDetails(
            $database,
            $row,
            (int) $user['user_id']
        ),
    ];
    specialistRecordAudit(
        $database,
        (int) $user['user_id'],
        'specialist.referral.viewed',
        (int) $row['patient_id'],
        (int) $row['visit_id']
    );

    jsonSuccess('Specialist referral loaded.', ['referral' => $referral]);
}

function handleSpecialistReferralAccept(string $referralId): void
{
    if (!ctype_digit($referralId) || (int) $referralId < 1) {
        jsonError('A valid specialist referral ID is required.', [], 422);
    }
    $database = getDatabaseConnection();
    $user = requireSpecialistAccess($database);
    requireCsrfRequest();

    try {
        $database->beginTransaction();
        $referral = specialistReferralForUpdate($database, (int) $referralId, $user);
        if ($referral['status'] !== 'pending'
            || $referral['visit_status'] !== 'awaiting_specialist') {
            $database->rollBack();
            jsonError('Only a pending active referral can be accepted.', [], 409);
        }

        $update = $database->prepare(
            'UPDATE specialist_referrals
             SET status = \'accepted\'
             WHERE specialist_referral_id = :referral_id AND status = \'pending\''
        );
        $update->execute(['referral_id' => (int) $referralId]);
        if ($update->rowCount() !== 1) {
            $database->rollBack();
            jsonError('This referral is no longer pending.', [], 409);
        }
        specialistRecordAudit(
            $database,
            (int) $user['user_id'],
            'specialist.referral.accepted',
            (int) $referral['patient_id'],
            (int) $referral['visit_id']
        );
        $database->commit();
    } catch (Throwable $exception) {
        if ($database->inTransaction()) {
            $database->rollBack();
        }
        error_log('Specialist referral acceptance failed: ' . $exception->getMessage());
        jsonError('Unable to accept the referral right now.', [], 500);
    }

    jsonSuccess('Specialist referral accepted.', [
        'specialist_referral_id' => (int) $referralId,
        'status' => 'accepted',
    ]);
}

function specialistOptionalText(array $input, string $key, int $maximum, array &$errors): ?string
{
    $value = $input[$key] ?? null;
    if ($value !== null && (!is_string($value) || strlen(trim($value)) > $maximum)) {
        $errors[$key] = 'Enter text of ' . $maximum . ' characters or fewer.';
        return null;
    }

    return is_string($value) && trim($value) !== '' ? trim($value) : null;
}

function specialistRequiredText(
    array $input,
    string $key,
    string $label,
    int $maximum,
    array &$errors
): ?string {
    $value = specialistOptionalText($input, $key, $maximum, $errors);
    if ($value === null) {
        $errors[$key] = $label . ' is required.';
    }

    return $value;
}

function handleSpecialistReferralOutcome(string $referralId): void
{
    if (!ctype_digit($referralId) || (int) $referralId < 1) {
        jsonError('A valid specialist referral ID is required.', [], 422);
    }
    $database = getDatabaseConnection();
    $user = requireSpecialistAccess($database);
    requireCsrfRequest();
    $input = readJsonRequest();
    $outcome = $input['outcome'] ?? null;
    if (!is_string($outcome) || !in_array($outcome, ['diagnosed', 'undiagnosed'], true)) {
        jsonError('Choose a valid specialist outcome.', [
            'outcome' => 'Choose diagnosed or undiagnosed.',
        ], 422);
    }

    $errors = [];
    $complaint = specialistOptionalText($input, 'presenting_complaint', 5000, $errors);
    $observations = specialistOptionalText($input, 'clinical_observations', 10000, $errors);
    $examination = specialistOptionalText($input, 'examination_findings', 10000, $errors);
    $notes = specialistOptionalText($input, 'notes', 10000, $errors);
    $items = [];
    $diagnosisDescription = null;
    $clinicalFindings = null;
    $treatmentPlan = null;
    $therapyEnabled = false;
    $therapyType = null;
    $therapyReason = null;
    $providerName = null;
    $clinicalSummary = null;
    $outsideAmount = null;

    if ($outcome === 'diagnosed') {
        $diagnosisDescription = specialistRequiredText(
            $input,
            'diagnosis_description',
            'Diagnosis description',
            5000,
            $errors
        );
        $clinicalFindings = specialistOptionalText($input, 'clinical_findings', 10000, $errors);
        $treatmentPlan = specialistRequiredText(
            $input,
            'treatment_plan',
            'Treatment plan',
            10000,
            $errors
        );
        $therapyEnabled = $input['refer_for_therapy'] ?? false;
        if (!is_bool($therapyEnabled)) {
            $errors['refer_for_therapy'] = 'Choose whether a therapy referral is required.';
            $therapyEnabled = false;
        }
        if ($therapyEnabled) {
            $therapyType = specialistRequiredText(
                $input,
                'therapy_type',
                'Therapy type',
                100,
                $errors
            );
            $therapyReason = specialistRequiredText(
                $input,
                'therapy_reason',
                'Therapy referral reason',
                5000,
                $errors
            );
        }
        $items = validateDoctorPrescriptionItems($input['prescription_items'] ?? []);
    } else {
        $providerName = specialistRequiredText(
            $input,
            'provider_name',
            'Outside hospital or doctor name',
            150,
            $errors
        );
        $clinicalSummary = specialistRequiredText(
            $input,
            'clinical_summary',
            'Clinical summary',
            10000,
            $errors
        );
        $amount = $input['outside_tests_amount'] ?? null;
        if ((!is_string($amount) && !is_int($amount) && !is_float($amount))
            || !preg_match('/^\d{1,8}(?:\.\d{1,2})?$/', (string) $amount)
            || (float) $amount <= 0) {
            $errors['outside_tests_amount'] = 'Enter an amount from 0.01 to 99999999.99 with up to 2 decimals.';
        } else {
            $outsideAmount = number_format((float) $amount, 2, '.', '');
        }
    }

    if ($errors !== []) {
        jsonError('Please correct the specialist outcome.', $errors, 422);
    }

    $responseConsultationId = null;
    $diagnosisId = null;
    $prescriptionId = null;
    $outsideReferralId = null;
    $nextStatus = '';
    try {
        $database->beginTransaction();
        $referral = specialistReferralForUpdate($database, (int) $referralId, $user);
        if ($referral['status'] !== 'accepted'
            || $referral['visit_status'] !== 'awaiting_specialist') {
            $database->rollBack();
            jsonError('Accept the active referral before recording its outcome.', [], 409);
        }

        $sourceStatement = $database->prepare(
            'SELECT consultation_id
             FROM consultations
             WHERE consultation_id = :consultation_id AND visit_id = :visit_id
             LIMIT 1'
        );
        $sourceStatement->execute([
            'consultation_id' => (int) $referral['source_consultation_id'],
            'visit_id' => (int) $referral['visit_id'],
        ]);
        if ($sourceStatement->fetchColumn() === false) {
            throw new RuntimeException('The source consultation for this referral is missing.');
        }

        $nextStep = $outcome === 'undiagnosed'
            ? 'outside_referral'
            : ($therapyEnabled ? 'therapy' : ($items !== [] ? 'prescription' : 'completed'));
        $consultationInsert = $database->prepare(
            'INSERT INTO consultations
                (visit_id, clinician_user_id, consultation_type,
                 presenting_complaint, clinical_observations,
                 examination_findings, notes, diagnosis_made,
                 diagnosis_text, treatment_plan, next_step)
             VALUES
                (:visit_id, :clinician_user_id, \'specialist\',
                 :presenting_complaint, :clinical_observations,
                 :examination_findings, :notes, :diagnosis_made,
                 :diagnosis_text, :treatment_plan, :next_step)'
        );
        $consultationInsert->execute([
            'visit_id' => (int) $referral['visit_id'],
            'clinician_user_id' => (int) $user['user_id'],
            'presenting_complaint' => $complaint,
            'clinical_observations' => $observations,
            'examination_findings' => $examination,
            'notes' => $notes,
            'diagnosis_made' => $outcome === 'diagnosed' ? 1 : 0,
            'diagnosis_text' => $diagnosisDescription,
            'treatment_plan' => $treatmentPlan,
            'next_step' => $nextStep,
        ]);
        $responseConsultationId = (int) $database->lastInsertId();

        if ($outcome === 'diagnosed') {
            $diagnosisInsert = $database->prepare(
                'INSERT INTO diagnoses
                    (visit_id, consultation_id, diagnosed_by_user_id, diagnosis_made,
                     diagnosis_description, clinical_findings, treatment_plan, notes)
                 VALUES
                    (:visit_id, :consultation_id, :specialist_id, 1,
                     :description, :findings, :treatment_plan, :notes)'
            );
            $diagnosisInsert->execute([
                'visit_id' => (int) $referral['visit_id'],
                'consultation_id' => $responseConsultationId,
                'specialist_id' => (int) $user['user_id'],
                'description' => $diagnosisDescription,
                'findings' => $clinicalFindings,
                'treatment_plan' => $treatmentPlan,
                'notes' => $notes,
            ]);
            $diagnosisId = (int) $database->lastInsertId();

            if ($items !== []) {
                $prescriptionId = createDoctorPrescription(
                    $database,
                    (int) $referral['visit_id'],
                    $responseConsultationId,
                    $diagnosisId,
                    (int) $user['user_id'],
                    $items
                );
            }
            if ($therapyEnabled) {
                createClinicalTherapyReferral(
                    $database,
                    (int) $referral['visit_id'],
                    $responseConsultationId,
                    $diagnosisId,
                    (int) $user['user_id'],
                    $therapyType,
                    $therapyReason
                );
            }
            $nextStatus = doctorNextStatusAfterDiagnosis(
                $database,
                (int) $referral['visit_id']
            );
        } else {
            $outsideInsert = $database->prepare(
                'INSERT INTO outside_referrals
                    (visit_id, source_consultation_id, specialist_referral_id, provider_name,
                     clinical_summary, outside_tests_payable, status)
                 VALUES
                    (:visit_id, :consultation_id, :specialist_referral_id, :provider_name,
                     :clinical_summary, 1, \'issued\')'
            );
            $outsideInsert->execute([
                'visit_id' => (int) $referral['visit_id'],
                'consultation_id' => $responseConsultationId,
                'specialist_referral_id' => (int) $referralId,
                'provider_name' => $providerName,
                'clinical_summary' => $clinicalSummary,
            ]);
            $outsideReferralId = (int) $database->lastInsertId();

            $bill = billingGetOrCreateBill($database, (int) $referral['visit_id']);
            if ($bill['status'] === 'cancelled' || $bill['status'] === 'paid') {
                throw new RuntimeException('The visit bill is already closed.');
            }
            billingAddFeeItemOnce(
                $database,
                (int) $bill['bill_id'],
                'OUTSIDE_TESTS_MANUAL',
                'outside_referral',
                null,
                null,
                null,
                $outsideReferralId,
                $outsideAmount,
                $providerName
            );
            billingRefreshBillTotal($database, (int) $bill['bill_id']);
            $nextStatus = 'awaiting_payment';
            specialistRecordAudit(
                $database,
                (int) $user['user_id'],
                'specialist.outside_referral.created',
                (int) $referral['patient_id'],
                (int) $referral['visit_id']
            );
        }

        $referralUpdate = $database->prepare(
            'UPDATE specialist_referrals
             SET status = \'completed\',
                 response_consultation_id = :consultation_id
             WHERE specialist_referral_id = :referral_id AND status = \'accepted\''
        );
        $referralUpdate->execute([
            'consultation_id' => $responseConsultationId,
            'referral_id' => (int) $referralId,
        ]);
        if ($referralUpdate->rowCount() !== 1) {
            throw new RuntimeException('The specialist referral is no longer active.');
        }

        $visitUpdate = $database->prepare(
            'UPDATE visits SET status = :status
             WHERE visit_id = :visit_id AND status = \'awaiting_specialist\''
        );
        $visitUpdate->execute([
            'status' => $nextStatus,
            'visit_id' => (int) $referral['visit_id'],
        ]);
        if ($visitUpdate->rowCount() !== 1) {
            throw new RuntimeException('The visit is no longer awaiting specialist care.');
        }

        specialistRecordAudit(
            $database,
            (int) $user['user_id'],
            $outcome === 'diagnosed'
                ? 'specialist.outcome.diagnosed'
                : 'specialist.outcome.undiagnosed',
            (int) $referral['patient_id'],
            (int) $referral['visit_id']
        );
        $database->commit();
    } catch (Throwable $exception) {
        if ($database->inTransaction()) {
            $database->rollBack();
        }
        error_log('Specialist outcome recording failed: ' . $exception->getMessage());
        jsonError('Unable to save the specialist outcome right now.', [], 500);
    }

    jsonSuccess(
        $outcome === 'undiagnosed'
            ? 'Outside referral issued and charge added to the visit bill.'
            : 'Specialist diagnosis recorded.',
        [
            'specialist_referral_id' => (int) $referralId,
            'consultation_id' => $responseConsultationId,
            'diagnosis_id' => $diagnosisId,
            'prescription_id' => $prescriptionId,
            'outside_referral_id' => $outsideReferralId,
            'visit_id' => (int) $referral['visit_id'],
            'visit_status' => $nextStatus,
        ],
        201
    );
}

function handleSpecialistOutsideReferralGet(string $outsideReferralId): void
{
    if (!ctype_digit($outsideReferralId) || (int) $outsideReferralId < 1) {
        jsonError('A valid outside referral ID is required.', [], 422);
    }
    $database = getDatabaseConnection();
    $user = requireSpecialistAccess($database);
    $statement = $database->prepare(
        'SELECT outside_referral.outside_referral_id, outside_referral.visit_id,
                outside_referral.specialist_referral_id,
                outside_referral.provider_name, outside_referral.clinical_summary,
                outside_referral.outside_tests_payable, outside_referral.status,
                outside_referral.created_at, visit.patient_id, patient.patient_number,
                patient.first_name, patient.last_name,
                specialist_referral.reason AS referral_reason,
                source_diagnosis.diagnosis_description,
                consultation.consultation_id, consultation.clinician_user_id,
                issuer.display_name AS issuer_name
         FROM outside_referrals outside_referral
         INNER JOIN visits visit ON visit.visit_id = outside_referral.visit_id
         INNER JOIN patients patient ON patient.patient_id = visit.patient_id
         INNER JOIN consultations consultation
            ON consultation.consultation_id = outside_referral.source_consultation_id
         INNER JOIN users issuer ON issuer.user_id = consultation.clinician_user_id
         LEFT JOIN specialist_referrals specialist_referral
            ON specialist_referral.specialist_referral_id =
               outside_referral.specialist_referral_id
         LEFT JOIN diagnoses source_diagnosis
            ON source_diagnosis.consultation_id =
               specialist_referral.source_consultation_id
         WHERE outside_referral.outside_referral_id = :outside_referral_id
         LIMIT 1'
    );
    $statement->execute(['outside_referral_id' => (int) $outsideReferralId]);
    $referral = $statement->fetch();
    if ($referral === false) {
        jsonError('Outside referral not found.', [], 404);
    }

    if ($user['role_name'] !== 'system_administrator') {
        $assignmentCondition = $referral['specialist_referral_id'] === null
            ? 'response_consultation_id = :consultation_id AND visit_id = :visit_id'
            : 'specialist_referral_id = :specialist_referral_id';
        $assignedStatement = $database->prepare(
            'SELECT 1
             FROM specialist_referrals
             WHERE ' . $assignmentCondition . '
               AND specialist_user_id = :specialist_user_id
             LIMIT 1'
        );
        $assignedParameters = ['specialist_user_id' => (int) $user['user_id']];
        if ($referral['specialist_referral_id'] === null) {
            $assignedParameters['consultation_id'] = (int) $referral['consultation_id'];
            $assignedParameters['visit_id'] = (int) $referral['visit_id'];
        } else {
            $assignedParameters['specialist_referral_id'] =
                (int) $referral['specialist_referral_id'];
        }
        $assignedStatement->execute($assignedParameters);
        if ($assignedStatement->fetchColumn() === false) {
            jsonError('This outside referral is not assigned to you.', [], 403);
        }
    }

    specialistRecordAudit(
        $database,
        (int) $user['user_id'],
        'specialist.outside_referral.viewed',
        (int) $referral['patient_id'],
        (int) $referral['visit_id']
    );
    jsonSuccess('Outside referral loaded.', [
        'outside_referral' => [
            'outside_referral_id' => (int) $referral['outside_referral_id'],
            'status' => $referral['status'],
            'provider_name' => $referral['provider_name'],
            'clinical_summary' => $referral['clinical_summary'],
            'outside_tests_payable' => (bool) $referral['outside_tests_payable'],
            'created_at' => $referral['created_at'],
            'issued_by' => $referral['issuer_name'],
            'referral_reason' => $referral['referral_reason'],
            'diagnosis_description' => $referral['diagnosis_description'],
        ],
        'patient' => [
            'patient_number' => $referral['patient_number'],
            'name' => trim(
                (string) $referral['first_name'] . ' ' . (string) $referral['last_name']
            ),
        ],
        'visit' => [
            'visit_id' => (int) $referral['visit_id'],
        ],
    ]);
}
