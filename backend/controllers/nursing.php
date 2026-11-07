<?php

declare(strict_types=1);

function requireNursingAccess(PDO $database): array
{
    $user = getAuthenticatedUser($database);
    if ($user === null) {
        jsonError('Authentication is required.', [], 401);
    }

    if (!in_array($user['role_name'], ['nurse', 'system_administrator'], true)) {
        jsonError('Nurse or system administrator access is required.', [], 403);
    }

    return $user;
}

function recordNursingAudit(
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

function nursingTestDepartments(): array
{
    return [
        'blood' => 'laboratory',
        'glucose' => 'laboratory',
        'urine' => 'laboratory',
        'pregnancy' => 'laboratory',
        'pap_smear' => 'laboratory',
        'x_ray' => 'radiology',
        'ecg' => 'cardiac',
    ];
}

function nursingQueueCriteriaSql(): string
{
    return "v.status = 'awaiting_nurse'
        AND v.is_emergency = 0
        AND v.medication_collection_only = 0";
}

function handleNursingDashboard(): void
{
    $database = getDatabaseConnection();
    requireNursingAccess($database);
    $eligible = nursingQueueCriteriaSql();

    $statsStatement = $database->query(
        "SELECT
            (SELECT COUNT(*)
             FROM visits v
             WHERE $eligible
               AND NOT EXISTS (
                    SELECT 1 FROM nurse_assessments na
                    WHERE na.visit_id = v.visit_id
               )) AS awaiting_assessment,
            (SELECT COUNT(*) FROM nurse_assessments na
             WHERE na.assessed_at >= CURDATE()
               AND na.assessed_at < CURDATE() + INTERVAL 1 DAY) AS assessed_today,
            (SELECT COUNT(*) FROM visits
             WHERE status = 'awaiting_tests') AS awaiting_tests,
            (SELECT COUNT(*) FROM visits
             WHERE status = 'awaiting_doctor') AS awaiting_doctor"
    );
    $stats = $statsStatement->fetch();

    $minorCountStatement = $database->query(
        "SELECT COUNT(DISTINCT na.assessment_id)
         FROM nurse_assessments na
         INNER JOIN minor_treatment_items mti
            ON mti.assessment_id = na.assessment_id
         WHERE na.assessed_at >= CURDATE()
           AND na.assessed_at < CURDATE() + INTERVAL 1 DAY"
    );

    $medicineStatement = $database->prepare(
        'SELECT medicine_id, medicine_name, unit_price, quantity_in_stock, is_demo
         FROM medicines
         WHERE is_low_schedule = 1 AND is_active = 1
         ORDER BY medicine_name'
    );
    $medicineStatement->execute();
    $medicines = array_map(static function (array $medicine): array {
        return [
            'medicine_id' => (int) $medicine['medicine_id'],
            'medicine_name' => $medicine['medicine_name'],
            'unit_price' => (string) $medicine['unit_price'],
            'quantity_in_stock' => (string) $medicine['quantity_in_stock'],
            'is_demo' => (bool) $medicine['is_demo'],
        ];
    }, $medicineStatement->fetchAll());

    jsonSuccess('Nursing dashboard loaded.', [
        'stats' => [
            'awaiting_assessment' => (int) $stats['awaiting_assessment'],
            'assessed_today' => (int) $stats['assessed_today'],
            'awaiting_tests' => (int) $stats['awaiting_tests'],
            'awaiting_doctor' => (int) $stats['awaiting_doctor'],
            'minor_treated_today' => (int) $minorCountStatement->fetchColumn(),
        ],
        'minor_treatment_medicines' => $medicines,
    ]);
}

function handleNursingQueue(string $search): void
{
    $database = getDatabaseConnection();
    requireNursingAccess($database);
    $search = trim($search);
    if (strlen($search) > 100) {
        jsonError('Search text must be 100 characters or fewer.', [
            'search' => 'Shorten the search text.',
        ], 422);
    }

    $eligible = nursingQueueCriteriaSql();
    $sql = "SELECT v.visit_id, v.arrived_at, v.is_emergency,
                   v.medication_collection_only, v.visit_source, v.status,
                   p.patient_id, p.patient_number, p.first_name, p.last_name,
                   p.date_of_birth, p.gender,
                   na.assessment_id, na.decision,
                   CASE
                     WHEN na.assessment_id IS NULL THEN 'assessment'
                     ELSE 'minor_treatment'
                   END AS next_action
            FROM visits v
            INNER JOIN patients p ON p.patient_id = v.patient_id
            LEFT JOIN nurse_assessments na
                ON na.assessment_id = (
                    SELECT MAX(latest.assessment_id)
                    FROM nurse_assessments latest
                    WHERE latest.visit_id = v.visit_id
                )
            WHERE $eligible
              AND (
                    na.assessment_id IS NULL
                    OR (
                        na.decision = 'minor_illness'
                        AND NOT EXISTS (
                            SELECT 1 FROM minor_treatment_items mti
                            WHERE mti.assessment_id = na.assessment_id
                        )
                    )
              )
              AND (
                    :patient_number = ''
                    OR p.patient_number LIKE :patient_number_search
                    OR p.first_name LIKE :first_name_search
                    OR p.last_name LIKE :last_name_search
                    OR CONCAT_WS(' ', p.first_name, p.last_name) LIKE :full_name_search
              )
            ORDER BY v.arrived_at ASC, v.visit_id ASC
            LIMIT 200";
    $statement = $database->prepare($sql);
    $pattern = '%' . $search . '%';
    $statement->execute([
        'patient_number' => $search,
        'patient_number_search' => $pattern,
        'first_name_search' => $pattern,
        'last_name_search' => $pattern,
        'full_name_search' => $pattern,
    ]);
    $queue = array_map(static function (array $row): array {
        return [
            'visit_id' => (int) $row['visit_id'],
            'patient_id' => (int) $row['patient_id'],
            'patient_number' => $row['patient_number'],
            'patient_name' => trim(
                (string) $row['first_name'] . ' ' . (string) $row['last_name']
            ),
            'date_of_birth' => $row['date_of_birth'],
            'gender' => $row['gender'],
            'arrived_at' => $row['arrived_at'],
            'is_emergency' => (bool) $row['is_emergency'],
            'visit_source' => $row['visit_source'],
            'status' => $row['status'],
            'assessment_id' => $row['assessment_id'] === null
                ? null
                : (int) $row['assessment_id'],
            'decision' => $row['decision'],
            'next_action' => $row['next_action'],
        ];
    }, $statement->fetchAll());

    jsonSuccess('Nursing queue loaded.', ['visits' => $queue]);
}

function handleNursingVisitGet(string $visitId): void
{
    if (!ctype_digit($visitId) || (int) $visitId < 1) {
        jsonError('A valid visit ID is required.', [], 400);
    }

    $database = getDatabaseConnection();
    requireNursingAccess($database);
    $eligible = nursingQueueCriteriaSql();
    $statement = $database->prepare(
        "SELECT v.visit_id, v.patient_id, v.arrived_at, v.is_emergency,
                v.medication_collection_only, v.visit_source, v.source_notes,
                v.status, p.patient_number, p.first_name, p.last_name,
                p.date_of_birth, p.gender, p.phone, p.address,
                na.assessment_id, na.nurse_user_id, na.complaint, na.symptoms,
                na.symptom_duration, na.blood_pressure, na.pulse, na.temperature,
                na.respiratory_rate, na.oxygen_saturation, na.weight, na.height,
                na.notes, na.decision, na.minor_treatment_notes, na.assessed_at
         FROM visits v
         INNER JOIN patients p ON p.patient_id = v.patient_id
         LEFT JOIN nurse_assessments na
            ON na.assessment_id = (
                SELECT MAX(latest.assessment_id)
                FROM nurse_assessments latest
                WHERE latest.visit_id = v.visit_id
            )
         WHERE v.visit_id = :visit_id AND $eligible
         LIMIT 1"
    );
    $statement->execute(['visit_id' => (int) $visitId]);
    $row = $statement->fetch();
    if ($row === false) {
        jsonError('This visit is not eligible for normal nursing assessment.', [], 404);
    }
    if ($row['decision'] !== null && $row['decision'] !== 'minor_illness') {
        jsonError('A nursing decision has already been submitted for this visit.', [], 409);
    }
    if ($row['decision'] === 'minor_illness') {
        $treatmentStatement = $database->prepare(
            'SELECT 1 FROM minor_treatment_items
             WHERE assessment_id = :assessment_id
             LIMIT 1'
        );
        $treatmentStatement->execute(['assessment_id' => (int) $row['assessment_id']]);
        if ($treatmentStatement->fetchColumn() !== false) {
            jsonError('Minor illness treatment is already recorded for this visit.', [], 409);
        }
    }

    $historyStatement = $database->prepare(
        'SELECT v.visit_id, v.arrived_at, v.visit_source, v.status,
                v.is_emergency, v.medication_collection_only,
                na.assessment_id, na.complaint, na.symptoms, na.decision,
                na.notes, na.assessed_at
         FROM visits v
         LEFT JOIN nurse_assessments na
            ON na.assessment_id = (
                SELECT MAX(latest.assessment_id)
                FROM nurse_assessments latest
                WHERE latest.visit_id = v.visit_id
            )
         WHERE v.patient_id = :patient_id AND v.visit_id <> :current_visit_id
         ORDER BY v.arrived_at DESC, v.visit_id DESC
         LIMIT 10'
    );
    $historyStatement->execute([
        'patient_id' => (int) $row['patient_id'],
        'current_visit_id' => (int) $visitId,
    ]);
    $history = array_map(static function (array $item): array {
        return [
            'visit_id' => (int) $item['visit_id'],
            'arrived_at' => $item['arrived_at'],
            'visit_source' => $item['visit_source'],
            'status' => $item['status'],
            'is_emergency' => (bool) $item['is_emergency'],
            'medication_collection_only' =>
                (bool) $item['medication_collection_only'],
            'assessment' => $item['assessment_id'] === null ? null : [
                'complaint' => $item['complaint'],
                'symptoms' => $item['symptoms'],
                'decision' => $item['decision'],
                'notes' => $item['notes'],
                'assessed_at' => $item['assessed_at'],
            ],
        ];
    }, $historyStatement->fetchAll());

    $assessment = $row['assessment_id'] === null ? null : [
        'assessment_id' => (int) $row['assessment_id'],
        'complaint' => $row['complaint'],
        'symptoms' => $row['symptoms'],
        'symptom_duration' => $row['symptom_duration'],
        'blood_pressure' => $row['blood_pressure'],
        'pulse' => $row['pulse'] === null ? null : (int) $row['pulse'],
        'temperature' => $row['temperature'],
        'respiratory_rate' => $row['respiratory_rate'] === null
            ? null
            : (int) $row['respiratory_rate'],
        'oxygen_saturation' => $row['oxygen_saturation'],
        'weight' => $row['weight'],
        'height' => $row['height'],
        'notes' => $row['notes'],
        'decision' => $row['decision'],
        'minor_treatment_notes' => $row['minor_treatment_notes'],
        'assessed_at' => $row['assessed_at'],
    ];

    jsonSuccess('Nursing visit loaded.', [
        'patient' => [
            'patient_id' => (int) $row['patient_id'],
            'patient_number' => $row['patient_number'],
            'full_name' => trim((string) $row['first_name'] . ' ' . (string) $row['last_name']),
            'date_of_birth' => $row['date_of_birth'],
            'gender' => $row['gender'],
            'phone' => $row['phone'],
            'address' => $row['address'],
        ],
        'visit' => [
            'visit_id' => (int) $row['visit_id'],
            'arrived_at' => $row['arrived_at'],
            'is_emergency' => (bool) $row['is_emergency'],
            'medication_collection_only' =>
                (bool) $row['medication_collection_only'],
            'visit_source' => $row['visit_source'],
            'source_notes' => $row['source_notes'],
            'status' => $row['status'],
        ],
        'assessment' => $assessment,
        'history' => $history,
    ]);
}

function nursingOptionalMeasurement(
    array $input,
    string $field,
    float $minimum,
    float $maximum,
    bool $integer,
    array &$errors
): ?string {
    $value = $input[$field] ?? null;
    if ($value === null || $value === '') {
        return null;
    }
    if ((!is_string($value) && !is_int($value) && !is_float($value))
        || !is_numeric((string) $value)) {
        $errors[$field] = 'Enter a numeric value or leave this field blank.';
        return null;
    }

    $number = (float) $value;
    if (!is_finite($number) || $number < $minimum || $number > $maximum
        || ($integer && floor($number) !== $number)) {
        $errors[$field] = 'Enter a valid value within the accepted input range.';
        return null;
    }

    return $integer ? (string) (int) $number : number_format($number, 2, '.', '');
}

function nursingEligibleVisitForUpdate(PDO $database, int $visitId): array
{
    $statement = $database->prepare(
        'SELECT visit_id, patient_id, is_emergency, medication_collection_only,
                status
         FROM visits
         WHERE visit_id = :visit_id
         LIMIT 1
         FOR UPDATE'
    );
    $statement->execute(['visit_id' => $visitId]);
    $visit = $statement->fetch();
    if ($visit === false) {
        jsonError('Visit not found.', [], 404);
    }
    if ((int) $visit['is_emergency'] === 1
        || (int) $visit['medication_collection_only'] === 1
        || $visit['status'] !== 'awaiting_nurse') {
        jsonError('This visit is not eligible for normal nursing assessment.', [], 409);
    }

    return $visit;
}

function handleNursingAssessmentSave(string $visitId): void
{
    if (!ctype_digit($visitId) || (int) $visitId < 1) {
        jsonError('A valid visit ID is required.', [], 400);
    }

    $database = getDatabaseConnection();
    $user = requireNursingAccess($database);
    requireCsrfRequest();
    $input = readJsonRequest();
    $complaint = $input['complaint'] ?? null;
    $symptoms = $input['symptoms'] ?? null;
    $duration = $input['symptom_duration'] ?? null;
    $notes = $input['nursing_notes'] ?? null;
    $errors = [];

    if (!is_string($complaint) || trim($complaint) === '' || strlen(trim($complaint)) > 5000) {
        $errors['complaint'] = 'Enter the main complaint (up to 5000 characters).';
    }
    foreach (['symptoms' => $symptoms, 'nursing_notes' => $notes] as $field => $value) {
        if ($value !== null && !is_string($value)) {
            $errors[$field] = 'Enter text or leave this field blank.';
        } elseif (is_string($value) && strlen(trim($value)) > 10000) {
            $errors[$field] = 'Text must be 10000 characters or fewer.';
        }
    }
    if ($duration !== null && (!is_string($duration) || strlen(trim($duration)) > 100)) {
        $errors['symptom_duration'] = 'Duration must be 100 characters or fewer.';
    }

    $temperature = nursingOptionalMeasurement($input, 'temperature', 25, 45, false, $errors);
    $systolic = nursingOptionalMeasurement($input, 'systolic', 40, 300, true, $errors);
    $diastolic = nursingOptionalMeasurement($input, 'diastolic', 20, 200, true, $errors);
    $pulse = nursingOptionalMeasurement($input, 'pulse', 1, 300, true, $errors);
    $respiratoryRate = nursingOptionalMeasurement(
        $input,
        'respiratory_rate',
        1,
        100,
        true,
        $errors
    );
    $oxygen = nursingOptionalMeasurement($input, 'oxygen_saturation', 0, 100, false, $errors);
    $weight = nursingOptionalMeasurement($input, 'weight', 0.1, 500, false, $errors);
    $height = nursingOptionalMeasurement($input, 'height', 20, 250, false, $errors);
    if (($systolic === null) !== ($diastolic === null)) {
        $errors['blood_pressure'] = 'Enter both systolic and diastolic values, or leave both blank.';
    } elseif ($systolic !== null && (int) $systolic <= (int) $diastolic) {
        $errors['blood_pressure'] = 'Systolic pressure must be greater than diastolic pressure.';
    }
    if ($errors !== []) {
        jsonError('Please correct the assessment information.', $errors, 422);
    }

    $bloodPressure = $systolic === null ? null : $systolic . '/' . $diastolic;
    try {
        $database->beginTransaction();
        $visit = nursingEligibleVisitForUpdate($database, (int) $visitId);
        $existingStatement = $database->prepare(
            'SELECT assessment_id FROM nurse_assessments
             WHERE visit_id = :visit_id
             LIMIT 1'
        );
        $existingStatement->execute(['visit_id' => (int) $visitId]);
        if ($existingStatement->fetch() !== false) {
            $database->rollBack();
            jsonError(
                'An assessment already exists for this visit. Continue with its pending decision.',
                [],
                409
            );
        }

        $insertStatement = $database->prepare(
            'INSERT INTO nurse_assessments
                (visit_id, nurse_user_id, complaint, symptoms, symptom_duration,
                 blood_pressure, pulse, temperature, respiratory_rate,
                 oxygen_saturation, weight, height, notes, decision)
             VALUES
                (:visit_id, :nurse_user_id, :complaint, :symptoms, :symptom_duration,
                 :blood_pressure, :pulse, :temperature, :respiratory_rate,
                 :oxygen_saturation, :weight, :height, :notes, NULL)'
        );
        $insertStatement->execute([
            'visit_id' => (int) $visitId,
            'nurse_user_id' => (int) $user['user_id'],
            'complaint' => trim($complaint),
            'symptoms' => is_string($symptoms) && trim($symptoms) !== ''
                ? trim($symptoms)
                : null,
            'symptom_duration' => is_string($duration) && trim($duration) !== ''
                ? trim($duration)
                : null,
            'blood_pressure' => $bloodPressure,
            'pulse' => $pulse,
            'temperature' => $temperature,
            'respiratory_rate' => $respiratoryRate,
            'oxygen_saturation' => $oxygen,
            'weight' => $weight,
            'height' => $height,
            'notes' => is_string($notes) && trim($notes) !== '' ? trim($notes) : null,
        ]);
        $assessmentId = (int) $database->lastInsertId();
        recordNursingAudit(
            $database,
            (int) $user['user_id'],
            'nursing.assessment.saved',
            (int) $visit['patient_id'],
            (int) $visitId
        );
        $database->commit();
    } catch (PDOException $exception) {
        if ($database->inTransaction()) {
            $database->rollBack();
        }
        error_log('Nursing assessment save failed: ' . $exception->getMessage());
        jsonError('Unable to save the nursing assessment right now.', [], 500);
    } catch (Throwable $exception) {
        if ($database->inTransaction()) {
            $database->rollBack();
        }
        error_log('Nursing assessment save failed unexpectedly: '
            . $exception->getMessage());
        jsonError('Unable to save the nursing assessment right now.', [], 500);
    }

    jsonSuccess('Nursing assessment saved. Select the next outcome.', [
        'assessment_id' => $assessmentId,
        'visit_id' => (int) $visitId,
        'nurse_user_id' => (int) $user['user_id'],
    ], 201);
}

function handleNursingDecision(string $visitId): void
{
    if (!ctype_digit($visitId) || (int) $visitId < 1) {
        jsonError('A valid visit ID is required.', [], 400);
    }

    $database = getDatabaseConnection();
    $user = requireNursingAccess($database);
    requireCsrfRequest();
    $input = readJsonRequest();
    $decision = $input['decision'] ?? null;
    $testTypes = $input['test_types'] ?? [];
    if (!is_string($decision)
        || !in_array($decision, ['minor_illness', 'tests', 'general_doctor'], true)) {
        jsonError('Select a valid nursing outcome.', [
            'decision' => 'Choose minor illness, tests, or general doctor.',
        ], 422);
    }
    if (!is_array($testTypes)) {
        jsonError('Test types must be a list.', [
            'test_types' => 'Select only approved in-house tests.',
        ], 422);
    }
    $testDepartments = nursingTestDepartments();
    if ($decision === 'tests') {
        if ($testTypes === [] || count($testTypes) > count($testDepartments)) {
            jsonError('Select at least one approved in-house test.', [
                'test_types' => 'Choose from the approved in-house list.',
            ], 422);
        }
        foreach ($testTypes as $testType) {
            if (!is_string($testType) || !isset($testDepartments[$testType])) {
                jsonError('One or more selected tests are not approved.', [
                    'test_types' => 'Choose blood, glucose, urine, pregnancy, pap smear, X-ray, or ECG.',
                ], 422);
            }
        }
        if (count(array_unique($testTypes)) !== count($testTypes)) {
            jsonError('A test may only be selected once.', [
                'test_types' => 'Remove repeated tests.',
            ], 422);
        }
    } elseif ($testTypes !== []) {
        jsonError('Tests may only be selected for the tests outcome.', [
            'test_types' => 'Clear the test selections or choose the tests outcome.',
        ], 422);
    }

    try {
        $database->beginTransaction();
        $visit = nursingEligibleVisitForUpdate($database, (int) $visitId);
        $assessmentStatement = $database->prepare(
            'SELECT assessment_id, decision
             FROM nurse_assessments
             WHERE visit_id = :visit_id
             ORDER BY assessment_id DESC
             LIMIT 1
             FOR UPDATE'
        );
        $assessmentStatement->execute(['visit_id' => (int) $visitId]);
        $assessment = $assessmentStatement->fetch();
        if ($assessment === false) {
            $database->rollBack();
            jsonError('Save the nursing assessment before submitting a decision.', [], 409);
        }
        if ($assessment['decision'] !== null) {
            $database->rollBack();
            jsonError('A nursing decision has already been submitted for this visit.', [], 409);
        }

        $updateStatement = $database->prepare(
            'UPDATE nurse_assessments SET decision = :decision
             WHERE assessment_id = :assessment_id AND decision IS NULL'
        );
        $updateStatement->execute([
            'decision' => $decision,
            'assessment_id' => (int) $assessment['assessment_id'],
        ]);
        if ($updateStatement->rowCount() !== 1) {
            $database->rollBack();
            jsonError('A nursing decision has already been submitted for this visit.', [], 409);
        }

        foreach ($testTypes as $testType) {
            $orderStatement = $database->prepare(
                'INSERT INTO test_orders
                    (visit_id, test_type, department, ordered_by_user_id)
                 VALUES
                    (:visit_id, :test_type, :department, :ordered_by_user_id)'
            );
            $orderStatement->execute([
                'visit_id' => (int) $visitId,
                'test_type' => $testType,
                'department' => $testDepartments[$testType],
                'ordered_by_user_id' => (int) $user['user_id'],
            ]);
        }

        $nextStatus = [
            'tests' => 'awaiting_tests',
            'general_doctor' => 'awaiting_doctor',
        ][$decision] ?? null;
        if ($nextStatus !== null) {
            $visitUpdate = $database->prepare(
                'UPDATE visits SET status = :status WHERE visit_id = :visit_id'
            );
            $visitUpdate->execute([
                'status' => $nextStatus,
                'visit_id' => (int) $visitId,
            ]);
        }
        recordNursingAudit(
            $database,
            (int) $user['user_id'],
            'nursing.decision.' . $decision,
            (int) $visit['patient_id'],
            (int) $visitId
        );
        $database->commit();
    } catch (PDOException $exception) {
        if ($database->inTransaction()) {
            $database->rollBack();
        }
        error_log('Nursing decision submission failed: ' . $exception->getMessage());
        jsonError('Unable to submit the nursing decision right now.', [], 500);
    } catch (Throwable $exception) {
        if ($database->inTransaction()) {
            $database->rollBack();
        }
        error_log('Nursing decision submission failed unexpectedly: '
            . $exception->getMessage());
        jsonError('Unable to submit the nursing decision right now.', [], 500);
    }

    jsonSuccess('Nursing decision recorded.', [
        'visit_id' => (int) $visitId,
        'assessment_id' => (int) $assessment['assessment_id'],
        'decision' => $decision,
        'status' => $nextStatus ?? 'awaiting_minor_treatment',
    ]);
}

function handleNursingMinorTreatment(string $visitId): void
{
    if (!ctype_digit($visitId) || (int) $visitId < 1) {
        jsonError('A valid visit ID is required.', [], 400);
    }

    $database = getDatabaseConnection();
    $user = requireNursingAccess($database);
    requireCsrfRequest();
    $input = readJsonRequest();
    $items = $input['items'] ?? null;
    $notes = $input['treatment_notes'] ?? null;
    if (!is_array($items) || $items === [] || count($items) > 20) {
        jsonError('Select at least one approved low-schedule medicine.', [
            'items' => 'Add one or more medicines and quantities.',
        ], 422);
    }
    if (!is_string($notes) || trim($notes) === '' || strlen(trim($notes)) > 5000) {
        jsonError('Treatment notes are required.', [
            'treatment_notes' => 'Enter treatment notes of up to 5000 characters.',
        ], 422);
    }

    $validatedItems = [];
    $medicineIds = [];
    $errors = [];
    foreach ($items as $index => $item) {
        if (!is_array($item)) {
            $errors['items.' . $index] = 'Each treatment item must include a medicine and quantity.';
            continue;
        }
        $medicineId = $item['medicine_id'] ?? null;
        if ((!is_int($medicineId)
                && !(is_string($medicineId) && ctype_digit($medicineId)))
            || (int) $medicineId < 1) {
            $errors['items.' . $index . '.medicine_id'] = 'Select a valid medicine.';
            continue;
        }
        $quantityValue = $item['quantity'] ?? null;
        if ((!is_string($quantityValue)
                && !is_int($quantityValue)
                && !is_float($quantityValue))
            || !is_numeric((string) $quantityValue)
            || !is_finite((float) $quantityValue)
            || (float) $quantityValue <= 0
            || (float) $quantityValue > 10000) {
            $errors['items.' . $index . '.quantity'] = 'Enter a quantity greater than 0 and no more than 10000.';
            continue;
        }
        $quantity = number_format((float) $quantityValue, 2, '.', '');
        if ((float) $quantity <= 0) {
            $errors['items.' . $index . '.quantity'] = 'Quantity must be at least 0.01.';
            continue;
        }
        $id = (int) $medicineId;
        if (isset($medicineIds[$id])) {
            $errors['items.' . $index . '.medicine_id'] = 'Select each medicine once and adjust its quantity.';
            continue;
        }
        $medicineIds[$id] = true;
        $validatedItems[] = [
            'medicine_id' => $id,
            'quantity' => $quantity,
        ];
    }
    if ($errors !== []) {
        jsonError('Please correct the minor treatment items.', $errors, 422);
    }

    try {
        $database->beginTransaction();
        $visit = nursingEligibleVisitForUpdate($database, (int) $visitId);
        $assessmentStatement = $database->prepare(
            'SELECT assessment_id, decision
             FROM nurse_assessments
             WHERE visit_id = :visit_id
             ORDER BY assessment_id DESC
             LIMIT 1
             FOR UPDATE'
        );
        $assessmentStatement->execute(['visit_id' => (int) $visitId]);
        $assessment = $assessmentStatement->fetch();
        if ($assessment === false || $assessment['decision'] !== 'minor_illness') {
            $database->rollBack();
            jsonError('Submit a minor illness decision before recording treatment.', [], 409);
        }
        $existingItems = $database->prepare(
            'SELECT minor_treatment_item_id
             FROM minor_treatment_items
             WHERE assessment_id = :assessment_id
             LIMIT 1'
        );
        $existingItems->execute(['assessment_id' => (int) $assessment['assessment_id']]);
        if ($existingItems->fetch() !== false) {
            $database->rollBack();
            jsonError('Minor illness treatment is already recorded for this assessment.', [], 409);
        }

        $billStatement = $database->prepare(
            'SELECT bill_id, status
             FROM bills
             WHERE visit_id = :visit_id
             LIMIT 1
             FOR UPDATE'
        );
        $billStatement->execute(['visit_id' => (int) $visitId]);
        $bill = $billStatement->fetch();
        if ($bill !== false && $bill['status'] === 'cancelled') {
            $database->rollBack();
            jsonError('A cancelled bill cannot receive minor treatment charges.', [], 409);
        }
        if ($bill === false) {
            $billNumber = 'B' . date('ymdHis') . strtoupper(bin2hex(random_bytes(5)));
            $billInsert = $database->prepare(
                "INSERT INTO bills (visit_id, bill_number, status)
                 VALUES (:visit_id, :bill_number, 'pending')"
            );
            $billInsert->execute([
                'visit_id' => (int) $visitId,
                'bill_number' => $billNumber,
            ]);
            $billId = (int) $database->lastInsertId();
            $billStatus = 'pending';
        } else {
            $billId = (int) $bill['bill_id'];
            $billStatus = $bill['status'];
        }

        $medicineStatement = $database->prepare(
            'SELECT medicine_id, medicine_name, unit_price, quantity_in_stock
             FROM medicines
             WHERE medicine_id = :medicine_id
               AND is_low_schedule = 1
               AND is_active = 1
             LIMIT 1
             FOR UPDATE'
        );
        $treatmentInsert = $database->prepare(
            'INSERT INTO minor_treatment_items
                (assessment_id, medicine_id, quantity, unit_price)
             VALUES
                (:assessment_id, :medicine_id, :quantity, :unit_price)'
        );
        $stockUpdate = $database->prepare(
            'UPDATE medicines
             SET quantity_in_stock = quantity_in_stock - :quantity
             WHERE medicine_id = :medicine_id
               AND quantity_in_stock >= :required_quantity'
        );
        $billItemInsert = $database->prepare(
            "INSERT INTO bill_items
                (bill_id, charge_type, description, quantity, unit_amount,
                 minor_treatment_item_id)
             VALUES
                (:bill_id, 'minor_treatment', :description, :quantity, :unit_amount,
                 :minor_treatment_item_id)"
        );

        foreach ($validatedItems as $item) {
            $medicineStatement->execute(['medicine_id' => $item['medicine_id']]);
            $medicine = $medicineStatement->fetch();
            if ($medicine === false) {
                $database->rollBack();
                jsonError('A selected medicine is not on the active low-schedule list.', [
                    'medicine_id' => 'Select an approved low-schedule medicine.',
                ], 422);
            }
            if ((float) $medicine['quantity_in_stock'] < (float) $item['quantity']) {
                $database->rollBack();
                jsonError('There is not enough stock for a selected medicine.', [
                    'medicine_id' => $medicine['medicine_name'] . ' has insufficient stock.',
                ], 409);
            }

            $treatmentInsert->execute([
                'assessment_id' => (int) $assessment['assessment_id'],
                'medicine_id' => $item['medicine_id'],
                'quantity' => $item['quantity'],
                'unit_price' => $medicine['unit_price'],
            ]);
            $treatmentItemId = (int) $database->lastInsertId();
            $stockUpdate->execute([
                'quantity' => $item['quantity'],
                'medicine_id' => $item['medicine_id'],
                'required_quantity' => $item['quantity'],
            ]);
            if ($stockUpdate->rowCount() !== 1) {
                $database->rollBack();
                jsonError('Medicine stock changed while treatment was being recorded.', [], 409);
            }
            $billItemInsert->execute([
                'bill_id' => $billId,
                'description' => 'Minor treatment: ' . $medicine['medicine_name'],
                'quantity' => $item['quantity'],
                'unit_amount' => $medicine['unit_price'],
                'minor_treatment_item_id' => $treatmentItemId,
            ]);
        }

        $notesUpdate = $database->prepare(
            'UPDATE nurse_assessments
             SET minor_treatment_notes = :notes
             WHERE assessment_id = :assessment_id'
        );
        $notesUpdate->execute([
            'notes' => trim($notes),
            'assessment_id' => (int) $assessment['assessment_id'],
        ]);
        $totalStatement = $database->prepare(
            'SELECT COALESCE(SUM(quantity * unit_amount), 0)
             FROM bill_items
             WHERE bill_id = :bill_id'
        );
        $totalStatement->execute(['bill_id' => $billId]);
        $totalAmount = (string) $totalStatement->fetchColumn();
        $updatedBillStatus = $billStatus === 'partially_paid'
            ? 'partially_paid'
            : 'pending';
        $billUpdate = $database->prepare(
            'UPDATE bills
             SET total_amount = :total_amount, status = :status
             WHERE bill_id = :bill_id'
        );
        $billUpdate->execute([
            'total_amount' => $totalAmount,
            'status' => $updatedBillStatus,
            'bill_id' => $billId,
        ]);
        $visitUpdate = $database->prepare(
            'UPDATE visits SET status = :status WHERE visit_id = :visit_id'
        );
        $visitUpdate->execute([
            'status' => 'awaiting_payment',
            'visit_id' => (int) $visitId,
        ]);
        recordNursingAudit(
            $database,
            (int) $user['user_id'],
            'nursing.minor_treatment.recorded',
            (int) $visit['patient_id'],
            (int) $visitId
        );
        $database->commit();
    } catch (PDOException $exception) {
        if ($database->inTransaction()) {
            $database->rollBack();
        }
        error_log('Minor illness treatment save failed: ' . $exception->getMessage());
        jsonError('Unable to record minor illness treatment right now.', [], 500);
    } catch (Throwable $exception) {
        if ($database->inTransaction()) {
            $database->rollBack();
        }
        error_log('Minor illness treatment save failed unexpectedly: '
            . $exception->getMessage());
        jsonError('Unable to record minor illness treatment right now.', [], 500);
    }

    jsonSuccess('Minor illness treatment recorded and routed for pharmacy payment.', [
        'visit_id' => (int) $visitId,
        'assessment_id' => (int) $assessment['assessment_id'],
        'bill_id' => $billId,
        'total_amount' => $totalAmount,
        'status' => 'awaiting_payment',
    ]);
}
