<?php

declare(strict_types=1);

function requireReceptionAccess(PDO $database): array
{
    $user = getAuthenticatedUser($database);
    if ($user === null) {
        jsonError('Authentication is required.', [], 401);
    }

    if (!in_array($user['role_name'], ['receptionist', 'system_administrator'], true)) {
        jsonError('Receptionist or system administrator access is required.', [], 403);
    }

    return $user;
}

function recordReceptionAudit(
    PDO $database,
    int $userId,
    string $action,
    ?int $patientId = null,
    ?int $visitId = null
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

function receptionPatientSummary(array $patient): array
{
    return [
        'patient_id' => (int) $patient['patient_id'],
        'patient_number' => $patient['patient_number'],
        'national_id' => $patient['national_id'],
        'first_name' => $patient['first_name'],
        'last_name' => $patient['last_name'],
        'date_of_birth' => $patient['date_of_birth'],
        'gender' => $patient['gender'],
        'phone' => $patient['phone'],
        'address' => $patient['address'],
        'is_temporary' => (bool) $patient['is_temporary'],
        'created_at' => $patient['created_at'],
    ];
}

function handleReceptionDashboard(): void
{
    $database = getDatabaseConnection();
    requireReceptionAccess($database);

    $statsStatement = $database->prepare(
        'SELECT
            (SELECT COUNT(*) FROM patients) AS total_patients,
            (SELECT COUNT(*) FROM visits
             WHERE arrived_at >= CURDATE()
               AND arrived_at < CURDATE() + INTERVAL 1 DAY) AS todays_arrivals,
            (SELECT COUNT(*) FROM visits
             WHERE is_emergency = 1
               AND arrived_at >= CURDATE()
               AND arrived_at < CURDATE() + INTERVAL 1 DAY) AS emergency_arrivals,
            (SELECT COUNT(*) FROM visits
             WHERE status = :ticket_status) AS awaiting_ticket_payment'
    );
    $statsStatement->execute(['ticket_status' => 'awaiting_ticket_payment']);
    $stats = $statsStatement->fetch();

    $recentStatement = $database->prepare(
        'SELECT patient_id, patient_number, national_id, first_name, last_name,
                date_of_birth, gender, phone, address, is_temporary, created_at
         FROM patients
         ORDER BY created_at DESC, patient_id DESC
         LIMIT 8'
    );
    $recentStatement->execute();
    $recentPatients = array_map(
        'receptionPatientSummary',
        $recentStatement->fetchAll()
    );

    jsonSuccess('Reception dashboard loaded.', [
        'stats' => [
            'total_patients' => (int) $stats['total_patients'],
            'todays_arrivals' => (int) $stats['todays_arrivals'],
            'emergency_arrivals' => (int) $stats['emergency_arrivals'],
            'awaiting_ticket_payment' => (int) $stats['awaiting_ticket_payment'],
        ],
        'recent_patients' => $recentPatients,
    ]);
}

function handleReceptionOrganizations(): void
{
    $database = getDatabaseConnection();
    requireReceptionAccess($database);

    $statement = $database->prepare(
        "SELECT organization_id, name, organization_type
         FROM organizations
         WHERE organization_type IN ('school', 'referring_facility')
         ORDER BY name"
    );
    $statement->execute();

    $organizations = array_map(static function (array $organization): array {
        return [
            'organization_id' => (int) $organization['organization_id'],
            'name' => $organization['name'],
            'organization_type' => $organization['organization_type'],
        ];
    }, $statement->fetchAll());

    jsonSuccess('Visit source organizations loaded.', [
        'organizations' => $organizations,
    ]);
}

function handleReceptionPatientsGet(string $search): void
{
    $database = getDatabaseConnection();
    requireReceptionAccess($database);

    $search = trim($search);
    if ($search === '' || strlen($search) > 100) {
        jsonError('Enter search text of 1 to 100 characters.', [
            'search' => 'Search by patient number, national ID or name.',
        ], 422);
    }

    $statement = $database->prepare(
        'SELECT patient_id, patient_number, national_id, first_name, last_name,
                date_of_birth, gender, phone, address, is_temporary, created_at
         FROM patients
         WHERE patient_number LIKE :patient_number
            OR national_id LIKE :national_id
            OR first_name LIKE :first_name
            OR last_name LIKE :last_name
            OR CONCAT_WS(\' \', first_name, last_name) LIKE :full_name
         ORDER BY last_name, first_name, patient_id DESC
         LIMIT 50'
    );
    $pattern = '%' . $search . '%';
    $statement->execute([
        'patient_number' => $pattern,
        'national_id' => $pattern,
        'first_name' => $pattern,
        'last_name' => $pattern,
        'full_name' => $pattern,
    ]);
    $patients = array_map(
        'receptionPatientSummary',
        $statement->fetchAll()
    );

    jsonSuccess('Patient search completed.', ['patients' => $patients]);
}

function handleReceptionPatientGet(string $patientId): void
{
    if (!ctype_digit($patientId) || (int) $patientId < 1) {
        jsonError('A valid patient ID is required.', [], 400);
    }

    $database = getDatabaseConnection();
    $user = requireReceptionAccess($database);

    $patientStatement = $database->prepare(
        'SELECT patient_id, patient_number, national_id, first_name, last_name,
                date_of_birth, gender, phone, address, is_temporary, created_at
         FROM patients
         WHERE patient_id = :patient_id
         LIMIT 1'
    );
    $patientStatement->execute(['patient_id' => (int) $patientId]);
    $patient = $patientStatement->fetch();

    if ($patient === false) {
        jsonError('Patient not found.', [], 404);
    }

    $visitsStatement = $database->prepare(
        'SELECT visit_id, arrived_at, is_emergency, medication_collection_only,
                visit_source, status
         FROM visits
         WHERE patient_id = :patient_id
         ORDER BY arrived_at DESC, visit_id DESC
         LIMIT 20'
    );
    $visitsStatement->execute(['patient_id' => (int) $patientId]);
    $visits = array_map(static function (array $visit): array {
        return [
            'visit_id' => (int) $visit['visit_id'],
            'arrived_at' => $visit['arrived_at'],
            'is_emergency' => (bool) $visit['is_emergency'],
            'medication_collection_only' =>
                (bool) $visit['medication_collection_only'],
            'visit_source' => $visit['visit_source'],
            'status' => $visit['status'],
        ];
    }, $visitsStatement->fetchAll());

    recordReceptionAudit(
        $database,
        (int) $user['user_id'],
        'reception.patient.viewed',
        (int) $patientId
    );

    jsonSuccess('Patient details loaded.', [
        'patient' => receptionPatientSummary($patient),
        'visits' => $visits,
    ]);
}

function handleReceptionPatientCreate(): void
{
    $database = getDatabaseConnection();
    $user = requireReceptionAccess($database);
    requireCsrfRequest();
    $input = readJsonRequest();

    $firstName = $input['first_name'] ?? null;
    $lastName = $input['last_name'] ?? null;
    $nationalId = $input['national_id'] ?? null;
    $dateOfBirth = $input['date_of_birth'] ?? null;
    $gender = $input['gender'] ?? null;
    $phone = $input['phone'] ?? null;
    $address = $input['address'] ?? null;
    $isTemporary = $input['is_temporary'] ?? false;
    $allowPossibleDuplicate = $input['allow_possible_duplicate'] ?? false;
    $errors = [];

    if (!is_bool($isTemporary)) {
        $errors['is_temporary'] = 'Temporary status must be true or false.';
    }
    if ($isTemporary) {
        if ($firstName !== null && (!is_string($firstName) || strlen(trim($firstName)) > 100)) {
            $errors['first_name'] = 'First name must be 100 characters or fewer.';
        }
        if ($lastName !== null && (!is_string($lastName) || strlen(trim($lastName)) > 100)) {
            $errors['last_name'] = 'Last name must be 100 characters or fewer.';
        }
    } else {
        if (!is_string($firstName) || trim($firstName) === ''
            || strlen(trim($firstName)) > 100) {
            $errors['first_name'] = 'Enter a first name of up to 100 characters.';
        }
        if (!is_string($lastName) || trim($lastName) === ''
            || strlen(trim($lastName)) > 100) {
            $errors['last_name'] = 'Enter a last name of up to 100 characters.';
        }
    }
    if ($nationalId !== null && !is_string($nationalId)) {
        $errors['national_id'] = 'National ID must be text.';
    } elseif (is_string($nationalId) && strlen(trim($nationalId)) > 50) {
        $errors['national_id'] = 'National ID must be 50 characters or fewer.';
    }
    if ($isTemporary && ($dateOfBirth === null || $dateOfBirth === '')) {
        $dateOfBirth = null;
    } elseif (!is_string($dateOfBirth) || !receptionValidDate($dateOfBirth)) {
        $errors['date_of_birth'] = 'Enter a valid date of birth that is not in the future.';
    }
    if ($isTemporary && ($gender === null || $gender === '')) {
        $gender = 'unknown';
    } elseif (!is_string($gender)
        || !in_array($gender, ['female', 'male', 'other', 'unknown'], true)) {
        $errors['gender'] = 'Select a valid gender.';
    }
    if ($phone !== null && !is_string($phone)) {
        $errors['phone'] = 'Phone number must be text.';
    } elseif (is_string($phone)
        && (strlen(trim($phone)) > 40
            || (trim($phone) !== '' && !preg_match('/^[0-9+().\s-]+$/', trim($phone))))) {
        $errors['phone'] = 'Enter a valid phone number of up to 40 characters.';
    }
    if ($address !== null && !is_string($address)) {
        $errors['address'] = 'Address must be text.';
    } elseif (is_string($address) && strlen(trim($address)) > 255) {
        $errors['address'] = 'Address must be 255 characters or fewer.';
    }
    if (!is_bool($allowPossibleDuplicate)) {
        $errors['allow_possible_duplicate'] = 'Duplicate confirmation must be true or false.';
    }

    if ($errors !== []) {
        jsonError('Please correct the patient information.', $errors, 422);
    }

    $firstName = is_string($firstName) && trim($firstName) !== ''
        ? trim($firstName)
        : null;
    $lastName = is_string($lastName) && trim($lastName) !== ''
        ? trim($lastName)
        : null;
    $nationalId = is_string($nationalId) && trim($nationalId) !== ''
        ? trim($nationalId)
        : null;
    $phone = is_string($phone) && trim($phone) !== '' ? trim($phone) : null;
    $address = is_string($address) && trim($address) !== '' ? trim($address) : null;

    if ($nationalId !== null) {
        $duplicateStatement = $database->prepare(
            'SELECT patient_id, patient_number, first_name, last_name, date_of_birth
             FROM patients
             WHERE national_id = :national_id
             LIMIT 1'
        );
        $duplicateStatement->execute(['national_id' => $nationalId]);
        $duplicate = $duplicateStatement->fetch();
        if ($duplicate !== false) {
            jsonError('A patient with this national ID already exists.', [
                'national_id' => 'Search for and open the existing patient record.',
                'existing_patient' => [
                    'patient_id' => (int) $duplicate['patient_id'],
                    'patient_number' => $duplicate['patient_number'],
                    'first_name' => $duplicate['first_name'],
                    'last_name' => $duplicate['last_name'],
                    'date_of_birth' => $duplicate['date_of_birth'],
                ],
            ], 409);
        }
    }

    $possibleDuplicates = [];
    if ($firstName !== null && $lastName !== null && $dateOfBirth !== null) {
        $possibleDuplicatesStatement = $database->prepare(
            'SELECT patient_id, patient_number, first_name, last_name, date_of_birth
             FROM patients
             WHERE LOWER(first_name) = LOWER(:first_name)
               AND LOWER(last_name) = LOWER(:last_name)
               AND date_of_birth = :date_of_birth
             ORDER BY patient_id DESC
             LIMIT 5'
        );
        $possibleDuplicatesStatement->execute([
            'first_name' => $firstName,
            'last_name' => $lastName,
            'date_of_birth' => $dateOfBirth,
        ]);
        $possibleDuplicates = array_map(static function (array $patient): array {
            return [
                'patient_id' => (int) $patient['patient_id'],
                'patient_number' => $patient['patient_number'],
                'first_name' => $patient['first_name'],
                'last_name' => $patient['last_name'],
                'date_of_birth' => $patient['date_of_birth'],
            ];
        }, $possibleDuplicatesStatement->fetchAll());
    }

    if ($possibleDuplicates !== [] && !$allowPossibleDuplicate) {
        jsonError(
            'Possible matching patient records were found. Review them before continuing.',
            ['possible_duplicates' => $possibleDuplicates],
            409
        );
    }

    try {
        $database->beginTransaction();
        $patientNumber = 'PT' . date('ymd') . strtoupper(bin2hex(random_bytes(6)));
        $insertStatement = $database->prepare(
            'INSERT INTO patients
                (patient_number, national_id, first_name, last_name, date_of_birth,
                 gender, phone, address, is_temporary)
             VALUES
                (:patient_number, :national_id, :first_name, :last_name, :date_of_birth,
                 :gender, :phone, :address, :is_temporary)'
        );
        $insertStatement->execute([
            'patient_number' => $patientNumber,
            'national_id' => $nationalId,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'date_of_birth' => $dateOfBirth,
            'gender' => $gender,
            'phone' => $phone,
            'address' => $address,
            'is_temporary' => $isTemporary ? 1 : 0,
        ]);
        $patientId = (int) $database->lastInsertId();

        recordReceptionAudit(
            $database,
            (int) $user['user_id'],
            'reception.patient.created',
            $patientId
        );
        $database->commit();
    } catch (PDOException $exception) {
        if ($database->inTransaction()) {
            $database->rollBack();
        }

        if ($exception->getCode() === '23000' && $nationalId !== null) {
            $duplicateStatement = $database->prepare(
                'SELECT patient_id, patient_number, first_name, last_name, date_of_birth
                 FROM patients
                 WHERE national_id = :national_id
                 LIMIT 1'
            );
            $duplicateStatement->execute(['national_id' => $nationalId]);
            $duplicate = $duplicateStatement->fetch();
            if ($duplicate !== false) {
                jsonError('A patient with this national ID already exists.', [
                    'national_id' => 'Search for and open the existing patient record.',
                    'existing_patient' => [
                        'patient_id' => (int) $duplicate['patient_id'],
                        'patient_number' => $duplicate['patient_number'],
                        'first_name' => $duplicate['first_name'],
                        'last_name' => $duplicate['last_name'],
                        'date_of_birth' => $duplicate['date_of_birth'],
                    ],
                ], 409);
            }
        }

        error_log('Reception patient creation failed: ' . $exception->getMessage());
        jsonError('Unable to register the patient right now.', [], 500);
    } catch (Throwable $exception) {
        if ($database->inTransaction()) {
            $database->rollBack();
        }
        error_log('Reception patient creation failed unexpectedly: '
            . $exception->getMessage());
        jsonError('Unable to register the patient right now.', [], 500);
    }

    jsonSuccess('Patient registered.', [
        'patient' => [
            'patient_id' => $patientId,
            'patient_number' => $patientNumber,
            'national_id' => $nationalId,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'date_of_birth' => $dateOfBirth,
            'gender' => $gender,
            'phone' => $phone,
            'address' => $address,
            'is_temporary' => $isTemporary,
        ],
    ], 201);
}

function handleReceptionPatientComplete(string $patientId): void
{
    if (!ctype_digit($patientId) || (int) $patientId < 1) {
        jsonError('A valid patient ID is required.', [], 400);
    }

    $database = getDatabaseConnection();
    $user = requireReceptionAccess($database);
    requireCsrfRequest();
    $input = readJsonRequest();

    $firstName = $input['first_name'] ?? null;
    $lastName = $input['last_name'] ?? null;
    $nationalId = $input['national_id'] ?? null;
    $dateOfBirth = $input['date_of_birth'] ?? null;
    $gender = $input['gender'] ?? null;
    $phone = $input['phone'] ?? null;
    $address = $input['address'] ?? null;
    $errors = [];

    if (!is_string($firstName) || trim($firstName) === '' || strlen(trim($firstName)) > 100) {
        $errors['first_name'] = 'Enter a first name of up to 100 characters.';
    }
    if (!is_string($lastName) || trim($lastName) === '' || strlen(trim($lastName)) > 100) {
        $errors['last_name'] = 'Enter a last name of up to 100 characters.';
    }
    if ($nationalId !== null && !is_string($nationalId)) {
        $errors['national_id'] = 'National ID must be text.';
    } elseif (is_string($nationalId) && strlen(trim($nationalId)) > 50) {
        $errors['national_id'] = 'National ID must be 50 characters or fewer.';
    }
    if (!is_string($dateOfBirth) || !receptionValidDate($dateOfBirth)) {
        $errors['date_of_birth'] = 'Enter a valid date of birth that is not in the future.';
    }
    if (!is_string($gender)
        || !in_array($gender, ['female', 'male', 'other', 'unknown'], true)) {
        $errors['gender'] = 'Select a valid gender.';
    }
    if ($phone !== null && !is_string($phone)) {
        $errors['phone'] = 'Phone number must be text.';
    } elseif (is_string($phone)
        && (strlen(trim($phone)) > 40
            || (trim($phone) !== '' && !preg_match('/^[0-9+().\s-]+$/', trim($phone))))) {
        $errors['phone'] = 'Enter a valid phone number of up to 40 characters.';
    }
    if ($address !== null && !is_string($address)) {
        $errors['address'] = 'Address must be text.';
    } elseif (is_string($address) && strlen(trim($address)) > 255) {
        $errors['address'] = 'Address must be 255 characters or fewer.';
    }
    if ($errors !== []) {
        jsonError('Please correct the patient information.', $errors, 422);
    }

    $firstName = trim($firstName);
    $lastName = trim($lastName);
    $nationalId = is_string($nationalId) && trim($nationalId) !== ''
        ? trim($nationalId)
        : null;
    $phone = is_string($phone) && trim($phone) !== '' ? trim($phone) : null;
    $address = is_string($address) && trim($address) !== '' ? trim($address) : null;

    if ($nationalId !== null) {
        $duplicateStatement = $database->prepare(
            'SELECT patient_id
             FROM patients
             WHERE national_id = :national_id AND patient_id <> :patient_id
             LIMIT 1'
        );
        $duplicateStatement->execute([
            'national_id' => $nationalId,
            'patient_id' => (int) $patientId,
        ]);
        if ($duplicateStatement->fetch() !== false) {
            jsonError('A patient with this national ID already exists.', [
                'national_id' => 'Enter the correct ID or open the matching record.',
            ], 409);
        }
    }

    try {
        $database->beginTransaction();
        $patientStatement = $database->prepare(
            'SELECT patient_number, is_temporary
             FROM patients
             WHERE patient_id = :patient_id
             LIMIT 1
             FOR UPDATE'
        );
        $patientStatement->execute(['patient_id' => (int) $patientId]);
        $patient = $patientStatement->fetch();
        if ($patient === false) {
            $database->rollBack();
            jsonError('Patient not found.', [], 404);
        }
        if ((int) $patient['is_temporary'] !== 1) {
            $database->rollBack();
            jsonError('This patient record is already complete.', [], 409);
        }

        $updateStatement = $database->prepare(
            'UPDATE patients
             SET national_id = :national_id,
                 first_name = :first_name,
                 last_name = :last_name,
                 date_of_birth = :date_of_birth,
                 gender = :gender,
                 phone = :phone,
                 address = :address,
                 is_temporary = 0
             WHERE patient_id = :patient_id'
        );
        $updateStatement->execute([
            'national_id' => $nationalId,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'date_of_birth' => $dateOfBirth,
            'gender' => $gender,
            'phone' => $phone,
            'address' => $address,
            'patient_id' => (int) $patientId,
        ]);
        recordReceptionAudit(
            $database,
            (int) $user['user_id'],
            'reception.patient.completed',
            (int) $patientId
        );
        $database->commit();
    } catch (PDOException $exception) {
        if ($database->inTransaction()) {
            $database->rollBack();
        }
        if ($exception->getCode() === '23000' && $nationalId !== null) {
            $duplicateStatement = $database->prepare(
                'SELECT patient_id
                 FROM patients
                 WHERE national_id = :national_id AND patient_id <> :patient_id
                 LIMIT 1'
            );
            $duplicateStatement->execute([
                'national_id' => $nationalId,
                'patient_id' => (int) $patientId,
            ]);
            if ($duplicateStatement->fetch() !== false) {
                jsonError('A patient with this national ID already exists.', [
                    'national_id' => 'Enter the correct ID or open the matching record.',
                ], 409);
            }
        }
        error_log('Reception patient completion failed: ' . $exception->getMessage());
        jsonError('Unable to complete the patient record right now.', [], 500);
    } catch (Throwable $exception) {
        if ($database->inTransaction()) {
            $database->rollBack();
        }
        error_log('Reception patient completion failed unexpectedly: '
            . $exception->getMessage());
        jsonError('Unable to complete the patient record right now.', [], 500);
    }

    jsonSuccess('Temporary patient record completed.', [
        'patient' => [
            'patient_id' => (int) $patientId,
            'patient_number' => $patient['patient_number'] ?? null,
            'national_id' => $nationalId,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'date_of_birth' => $dateOfBirth,
            'gender' => $gender,
            'phone' => $phone,
            'address' => $address,
            'is_temporary' => false,
        ],
    ]);
}

function handleReceptionVisitCreate(): void
{
    $database = getDatabaseConnection();
    $user = requireReceptionAccess($database);
    requireCsrfRequest();
    $input = readJsonRequest();

    $patientId = $input['patient_id'] ?? null;
    $isEmergency = $input['is_emergency'] ?? null;
    $medicationCollectionOnly = $input['medication_collection_only'] ?? null;
    $visitSource = $input['visit_source'] ?? null;
    $sourceOrganizationId = $input['source_organization_id'] ?? null;
    $sourceNotes = $input['source_notes'] ?? null;
    $errors = [];

    if ((!is_int($patientId)
            && !(is_string($patientId) && ctype_digit($patientId)))
        || (int) $patientId < 1) {
        $errors['patient_id'] = 'Select an existing patient.';
    }
    if (!is_bool($isEmergency)) {
        $errors['is_emergency'] = 'Emergency must be true or false.';
    }
    if (!is_bool($medicationCollectionOnly)) {
        $errors['medication_collection_only'] = 'Medication-only must be true or false.';
    }
    if (!is_string($visitSource)
        || !in_array($visitSource, ['walk_in', 'school_appointment', 'referral'], true)) {
        $errors['visit_source'] = 'Select a valid visit source.';
    }
    if ($sourceOrganizationId !== null
        && ((!is_int($sourceOrganizationId)
                && !(is_string($sourceOrganizationId) && ctype_digit($sourceOrganizationId)))
            || (int) $sourceOrganizationId < 1)) {
        $errors['source_organization_id'] = 'Select a valid source organization.';
    }
    if ($sourceNotes !== null && !is_string($sourceNotes)) {
        $errors['source_notes'] = 'Source notes must be text.';
    } elseif (is_string($sourceNotes) && strlen(trim($sourceNotes)) > 2000) {
        $errors['source_notes'] = 'Source notes must be 2000 characters or fewer.';
    }
    if ($isEmergency === true && $medicationCollectionOnly === true) {
        $errors['visit_type'] = 'A visit cannot be both an emergency and medication-only.';
    }

    if (is_string($visitSource) && $visitSource !== 'walk_in'
        && $sourceOrganizationId === null) {
        $errors['source_organization_id'] = 'Choose the school or referring facility.';
    }
    if (is_string($visitSource) && $visitSource === 'walk_in'
        && $sourceOrganizationId !== null) {
        $errors['source_organization_id'] = 'Walk-in visits do not use a source organization.';
    }

    if ($errors !== []) {
        jsonError('Please correct the visit information.', $errors, 422);
    }

    $expectedOrganizationType = [
        'school_appointment' => 'school',
        'referral' => 'referring_facility',
    ][$visitSource] ?? null;

    try {
        $database->beginTransaction();
        $patientStatement = $database->prepare(
            'SELECT patient_id, is_temporary
             FROM patients
             WHERE patient_id = :patient_id
             LIMIT 1
             FOR UPDATE'
        );
        $patientStatement->execute(['patient_id' => (int) $patientId]);
        $patient = $patientStatement->fetch();
        if ($patient === false) {
            $database->rollBack();
            jsonError('Patient not found.', [], 404);
        }
        if ((int) $patient['is_temporary'] === 1 && !$isEmergency) {
            $database->rollBack();
            jsonError('Temporary patient records can only start emergency visits.', [
                'patient_id' => 'Start an emergency visit or complete the patient details first.',
            ], 422);
        }

        if ($expectedOrganizationType !== null) {
            $organizationStatement = $database->prepare(
                'SELECT organization_id
                 FROM organizations
                 WHERE organization_id = :organization_id
                   AND organization_type = :organization_type
                 LIMIT 1'
            );
            $organizationStatement->execute([
                'organization_id' => (int) $sourceOrganizationId,
                'organization_type' => $expectedOrganizationType,
            ]);
            if ($organizationStatement->fetch() === false) {
                $database->rollBack();
                jsonError('The selected source organization is not valid for this visit.', [
                    'source_organization_id' => 'Choose an organization matching the visit source.',
                ], 422);
            }
        }

        $status = receptionVisitStatus($isEmergency, $medicationCollectionOnly);

        $insertStatement = $database->prepare(
            'INSERT INTO visits
                (patient_id, is_emergency, medication_collection_only, visit_source,
                 source_organization_id, source_notes, status, created_by_user_id)
             VALUES
                (:patient_id, :is_emergency, :medication_collection_only, :visit_source,
                 :source_organization_id, :source_notes, :status, :created_by_user_id)'
        );
        $insertStatement->execute([
            'patient_id' => (int) $patientId,
            'is_emergency' => $isEmergency ? 1 : 0,
            'medication_collection_only' => $medicationCollectionOnly ? 1 : 0,
            'visit_source' => $visitSource,
            'source_organization_id' => $sourceOrganizationId === null
                ? null
                : (int) $sourceOrganizationId,
            'source_notes' => is_string($sourceNotes) && trim($sourceNotes) !== ''
                ? trim($sourceNotes)
                : null,
            'status' => $status,
            'created_by_user_id' => (int) $user['user_id'],
        ]);
        $visitId = (int) $database->lastInsertId();
        recordReceptionAudit(
            $database,
            (int) $user['user_id'],
            'reception.visit.created',
            (int) $patientId,
            $visitId
        );
        $database->commit();
    } catch (PDOException $exception) {
        if ($database->inTransaction()) {
            $database->rollBack();
        }
        error_log('Reception visit creation failed: ' . $exception->getMessage());
        jsonError('Unable to start the visit right now.', [], 500);
    } catch (Throwable $exception) {
        if ($database->inTransaction()) {
            $database->rollBack();
        }
        error_log('Reception visit creation failed unexpectedly: '
            . $exception->getMessage());
        jsonError('Unable to start the visit right now.', [], 500);
    }

    jsonSuccess('Visit started.', [
        'visit' => [
            'visit_id' => $visitId,
            'patient_id' => (int) $patientId,
            'is_emergency' => $isEmergency,
            'medication_collection_only' => $medicationCollectionOnly,
            'visit_source' => $visitSource,
            'status' => $status,
            'created_by_user_id' => (int) $user['user_id'],
        ],
    ], 201);
}

function receptionValidDate(string $value): bool
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    $dateErrors = DateTimeImmutable::getLastErrors();

    return $date !== false
        && ($dateErrors === false
            || ($dateErrors['warning_count'] === 0 && $dateErrors['error_count'] === 0))
        && $date->format('Y-m-d') === $value
        && $value <= date('Y-m-d');
}

function receptionVisitStatus(bool $isEmergency, bool $medicationCollectionOnly): string
{
    if ($isEmergency) {
        return 'emergency_in_progress';
    }

    return $medicationCollectionOnly
        ? 'awaiting_pharmacy'
        : 'awaiting_ticket_payment';
}
