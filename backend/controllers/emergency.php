<?php

declare(strict_types=1);

function requireEmergencyAccess(PDO $database, bool $doctorOnly = false): array
{
    $user = getAuthenticatedUser($database);
    if ($user === null) {
        jsonError('Authentication is required.', [], 401);
    }

    $allowedRoles = $doctorOnly
        ? ['emergency_doctor']
        : ['emergency_doctor', 'emergency_nurse', 'system_administrator'];
    if (!in_array($user['role_name'], $allowedRoles, true)) {
        jsonError('Emergency department access is not authorized.', [], 403);
    }

    return $user;
}

function recordEmergencyAudit(
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

function emergencyVisitSummary(array $row): array
{
    return [
        'visit_id' => (int) $row['visit_id'],
        'patient_id' => (int) $row['patient_id'],
        'patient_number' => $row['patient_number'],
        'patient_name' => trim(
            (string) $row['first_name'] . ' ' . (string) $row['last_name']
        ),
        'is_temporary' => (bool) $row['is_temporary'],
        'arrived_at' => $row['arrived_at'],
        'visit_source' => $row['visit_source'],
        'source_organization' => $row['source_organization'],
        'status' => $row['visit_status'],
        'encounter_status' => $row['emergency_encounter_id'] === null
            ? 'not_started'
            : ($row['ended_at'] === null ? 'in_progress' : 'completed'),
        'encounter' => $row['emergency_encounter_id'] === null ? null : [
            'emergency_encounter_id' => (int) $row['emergency_encounter_id'],
            'emergency_doctor_id' => (int) $row['emergency_doctor_id'],
            'emergency_doctor_name' => $row['emergency_doctor_name'],
            'triage_level' => $row['triage_level'],
            'stabilization_notes' => $row['stabilization_notes'],
            'started_at' => $row['started_at'],
            'ended_at' => $row['ended_at'],
        ],
    ];
}

function emergencyVisitSelectSql(): string
{
    return 'SELECT visit.visit_id, visit.patient_id, visit.arrived_at,
                   visit.visit_source, visit.status AS visit_status,
                   patient.patient_number, patient.first_name, patient.last_name,
                   patient.is_temporary, organization.name AS source_organization,
                   encounter.emergency_encounter_id, encounter.emergency_doctor_id,
                   encounter.triage_level, encounter.stabilization_notes,
                   encounter.started_at, encounter.ended_at,
                   doctor.display_name AS emergency_doctor_name
            FROM visits visit
            INNER JOIN patients patient ON patient.patient_id = visit.patient_id
            LEFT JOIN organizations organization
                ON organization.organization_id = visit.source_organization_id
            LEFT JOIN emergency_encounters encounter
                ON encounter.visit_id = visit.visit_id
            LEFT JOIN users doctor
                ON doctor.user_id = encounter.emergency_doctor_id';
}

function handleEmergencyQueueGet(string $search): void
{
    $database = getDatabaseConnection();
    $user = requireEmergencyAccess($database);
    $search = trim($search);
    if (strlen($search) > 100) {
        jsonError('Search text must be 100 characters or fewer.', [
            'search' => 'Shorten the search text.',
        ], 422);
    }

    $searchSql = '';
    $parameters = ['status' => 'emergency_in_progress'];
    if ($search !== '') {
        $searchSql = ' AND (
            patient.patient_number LIKE :patient_number
            OR patient.first_name LIKE :first_name
            OR patient.last_name LIKE :last_name
            OR CONCAT_WS(\' \', patient.first_name, patient.last_name) LIKE :full_name
            OR CAST(visit.visit_id AS CHAR) LIKE :visit_id
        )';
        $pattern = '%' . $search . '%';
        $parameters += [
            'patient_number' => $pattern,
            'first_name' => $pattern,
            'last_name' => $pattern,
            'full_name' => $pattern,
            'visit_id' => $pattern,
        ];
    }

    $statement = $database->prepare(
        emergencyVisitSelectSql()
            . ' WHERE visit.status = :status AND visit.is_emergency = 1'
            . $searchSql
            . ' ORDER BY visit.arrived_at ASC, visit.visit_id ASC LIMIT 250'
    );
    $statement->execute($parameters);
    $rows = $statement->fetchAll();
    $visits = array_map('emergencyVisitSummary', $rows);
    foreach ($visits as $visit) {
        recordEmergencyAudit(
            $database,
            (int) $user['user_id'],
            'emergency.queue.viewed',
            $visit['patient_id'],
            $visit['visit_id']
        );
    }
    jsonSuccess('Emergency queue loaded.', [
        'visits' => $visits,
        'count' => count($visits),
    ]);
}

function handleEmergencyVisitGet(string $visitId): void
{
    if (!ctype_digit($visitId) || (int) $visitId < 1) {
        jsonError('A valid visit ID is required.', [], 422);
    }

    $database = getDatabaseConnection();
    $user = requireEmergencyAccess($database);
    $statement = $database->prepare(
        emergencyVisitSelectSql()
            . ' WHERE visit.visit_id = :visit_id
                AND visit.is_emergency = 1
                AND visit.status = \'emergency_in_progress\'
              LIMIT 1'
    );
    $statement->execute(['visit_id' => (int) $visitId]);
    $row = $statement->fetch();
    if ($row === false) {
        jsonError('Emergency visit not found or no longer active.', [], 404);
    }

    $nurseStatement = $database->prepare(
        'SELECT nurse.user_id, nurse.display_name, nurse.username
         FROM emergency_nurses attending
         INNER JOIN users nurse ON nurse.user_id = attending.nurse_user_id
         WHERE attending.emergency_encounter_id = :encounter_id
         ORDER BY nurse.display_name'
    );
    $nurseStatement->execute([
        'encounter_id' => $row['emergency_encounter_id'] === null
            ? 0
            : (int) $row['emergency_encounter_id'],
    ]);
    $nurses = array_map(static function (array $nurse): array {
        return [
            'user_id' => (int) $nurse['user_id'],
            'display_name' => $nurse['display_name'],
            'username' => $nurse['username'],
        ];
    }, $nurseStatement->fetchAll());

    $availableNurses = [];
    $medicines = [];
    if ($user['role_name'] === 'emergency_doctor'
        || $user['role_name'] === 'system_administrator') {
        $availableNurseStatement = $database->query(
            "SELECT user.user_id, user.display_name
             FROM users user
             INNER JOIN roles role ON role.role_id = user.role_id
             WHERE user.is_active = 1 AND role.role_name = 'emergency_nurse'
             ORDER BY user.display_name"
        );
        $availableNurses = array_map(static function (array $nurse): array {
            return [
                'user_id' => (int) $nurse['user_id'],
                'display_name' => $nurse['display_name'],
            ];
        }, $availableNurseStatement->fetchAll());

        $medicineStatement = $database->query(
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
        }, $medicineStatement->fetchAll());
    }

    $prescriptionStatement = $database->prepare(
        'SELECT prescription.prescription_id, prescription.status,
                prescription.created_at, prescriber.display_name AS prescribed_by
         FROM prescriptions prescription
         INNER JOIN users prescriber
            ON prescriber.user_id = prescription.prescribed_by_user_id
         WHERE prescription.visit_id = :visit_id
         ORDER BY prescription.created_at DESC, prescription.prescription_id DESC'
    );
    $prescriptionStatement->execute(['visit_id' => (int) $visitId]);
    $prescriptions = array_map(static function (array $prescription): array {
        return [
            'prescription_id' => (int) $prescription['prescription_id'],
            'status' => $prescription['status'],
            'created_at' => $prescription['created_at'],
            'prescribed_by' => $prescription['prescribed_by'],
        ];
    }, $prescriptionStatement->fetchAll());

    recordEmergencyAudit(
        $database,
        (int) $user['user_id'],
        'emergency.visit.viewed',
        (int) $row['patient_id'],
        (int) $visitId
    );

    jsonSuccess('Emergency visit loaded.', [
        'visit' => emergencyVisitSummary($row),
        'attending_nurses' => $nurses,
        'available_nurses' => $availableNurses,
        'medicines' => $medicines,
        'prescriptions' => $prescriptions,
        'temporary_patient_completion_url' => $row['is_temporary']
            ? '../reception/index.php'
            : null,
    ]);
}

function emergencyVisitForUpdate(PDO $database, int $visitId): array
{
    $statement = $database->prepare(
        'SELECT visit_id, patient_id, status, is_emergency, medication_collection_only
         FROM visits
         WHERE visit_id = :visit_id
         LIMIT 1
         FOR UPDATE'
    );
    $statement->execute(['visit_id' => $visitId]);
    $visit = $statement->fetch();
    if ($visit === false || (int) $visit['is_emergency'] !== 1) {
        jsonError('Emergency visit not found.', [], 404);
    }
    if ($visit['status'] !== 'emergency_in_progress'
        || (int) $visit['medication_collection_only'] === 1) {
        jsonError('This emergency visit is no longer active.', [], 409);
    }

    return $visit;
}

function handleEmergencyEncounterCreate(string $visitId): void
{
    if (!ctype_digit($visitId) || (int) $visitId < 1) {
        jsonError('A valid visit ID is required.', [], 422);
    }
    $database = getDatabaseConnection();
    $user = requireEmergencyAccess($database, true);
    requireCsrfRequest();
    $input = readJsonRequest();
    $triageLevel = $input['triage_level'] ?? null;
    $notes = $input['stabilization_notes'] ?? null;
    $errors = [];
    if (!is_string($triageLevel) || trim($triageLevel) === ''
        || strlen(trim($triageLevel)) > 40) {
        $errors['triage_level'] = 'Enter a triage level of up to 40 characters.';
    }
    if ($notes !== null
        && (!is_string($notes) || strlen(trim($notes)) > 10000)) {
        $errors['stabilization_notes'] = 'Notes must be text of 10000 characters or fewer.';
    }
    if ($errors !== []) {
        jsonError('Please correct the emergency encounter details.', $errors, 422);
    }

    try {
        $database->beginTransaction();
        $visit = emergencyVisitForUpdate($database, (int) $visitId);
        $existing = $database->prepare(
            'SELECT emergency_encounter_id
             FROM emergency_encounters
             WHERE visit_id = :visit_id
             LIMIT 1
             FOR UPDATE'
        );
        $existing->execute(['visit_id' => (int) $visitId]);
        if ($existing->fetchColumn() !== false) {
            $database->rollBack();
            jsonError('An emergency encounter already exists for this visit.', [], 409);
        }

        $encounterInsert = $database->prepare(
            'INSERT INTO emergency_encounters
                (visit_id, emergency_doctor_id, triage_level, stabilization_notes)
             VALUES
                (:visit_id, :doctor_id, :triage_level, :stabilization_notes)'
        );
        $encounterInsert->execute([
            'visit_id' => (int) $visitId,
            'doctor_id' => (int) $user['user_id'],
            'triage_level' => trim($triageLevel),
            'stabilization_notes' => is_string($notes) && trim($notes) !== ''
                ? trim($notes)
                : null,
        ]);
        $encounterId = (int) $database->lastInsertId();
        recordEmergencyAudit(
            $database,
            (int) $user['user_id'],
            'emergency.encounter.started',
            (int) $visit['patient_id'],
            (int) $visitId
        );
        $database->commit();
    } catch (Throwable $exception) {
        if ($database->inTransaction()) {
            $database->rollBack();
        }
        error_log('Emergency encounter creation failed: ' . $exception->getMessage());
        jsonError('Unable to start the emergency encounter right now.', [], 500);
    }

    jsonSuccess('Emergency encounter started; charges will be resolved at Pharmacy.', [
        'emergency_encounter_id' => $encounterId,
        'visit_id' => (int) $visitId,
        'emergency_charge' => null,
        'charge_created' => false,
        'charge_deferred' => true,
        'payment_collected' => false,
    ], 201);
}

function handleEmergencyNurseAssign(string $visitId): void
{
    if (!ctype_digit($visitId) || (int) $visitId < 1) {
        jsonError('A valid visit ID is required.', [], 422);
    }
    $database = getDatabaseConnection();
    $user = requireEmergencyAccess($database, true);
    requireCsrfRequest();
    $input = readJsonRequest();
    $nurseId = $input['nurse_user_id'] ?? null;
    if ((!is_int($nurseId) && !(is_string($nurseId) && ctype_digit($nurseId)))
        || (int) $nurseId < 1) {
        jsonError('A valid emergency nurse is required.', [
            'nurse_user_id' => 'Select an emergency nurse.',
        ], 422);
    }

    try {
        $database->beginTransaction();
        $visit = emergencyVisitForUpdate($database, (int) $visitId);
        $encounterStatement = $database->prepare(
            'SELECT emergency_encounter_id
             FROM emergency_encounters
             WHERE visit_id = :visit_id AND ended_at IS NULL
             LIMIT 1
             FOR UPDATE'
        );
        $encounterStatement->execute(['visit_id' => (int) $visitId]);
        $encounterId = $encounterStatement->fetchColumn();
        if ($encounterId === false) {
            $database->rollBack();
            jsonError('Start the emergency encounter before assigning nurses.', [], 409);
        }

        $nurseStatement = $database->prepare(
            "SELECT user.user_id
             FROM users user
             INNER JOIN roles role ON role.role_id = user.role_id
             WHERE user.user_id = :user_id AND user.is_active = 1
               AND role.role_name = 'emergency_nurse'
             LIMIT 1"
        );
        $nurseStatement->execute(['user_id' => (int) $nurseId]);
        if ($nurseStatement->fetchColumn() === false) {
            $database->rollBack();
            jsonError('The selected user is not an active emergency nurse.', [], 422);
        }

        $duplicate = $database->prepare(
            'SELECT nurse_user_id FROM emergency_nurses
             WHERE emergency_encounter_id = :encounter_id
               AND nurse_user_id = :nurse_id
             LIMIT 1'
        );
        $duplicate->execute([
            'encounter_id' => (int) $encounterId,
            'nurse_id' => (int) $nurseId,
        ]);
        if ($duplicate->fetchColumn() !== false) {
            $database->rollBack();
            jsonError('This emergency nurse is already assigned.', [], 409);
        }

        $insert = $database->prepare(
            'INSERT INTO emergency_nurses (emergency_encounter_id, nurse_user_id)
             VALUES (:encounter_id, :nurse_id)'
        );
        $insert->execute([
            'encounter_id' => (int) $encounterId,
            'nurse_id' => (int) $nurseId,
        ]);
        recordEmergencyAudit(
            $database,
            (int) $user['user_id'],
            'emergency.nurse.assigned',
            (int) $visit['patient_id'],
            (int) $visitId
        );
        $database->commit();
    } catch (Throwable $exception) {
        if ($database->inTransaction()) {
            $database->rollBack();
        }
        error_log('Emergency nurse assignment failed: ' . $exception->getMessage());
        jsonError('Unable to assign the emergency nurse right now.', [], 500);
    }

    jsonSuccess('Emergency nurse assigned.', [
        'emergency_encounter_id' => (int) $encounterId,
        'nurse_user_id' => (int) $nurseId,
    ], 201);
}

function handleEmergencyPrescriptionCreate(string $visitId): void
{
    if (!ctype_digit($visitId) || (int) $visitId < 1) {
        jsonError('A valid visit ID is required.', [], 422);
    }
    $database = getDatabaseConnection();
    $user = requireEmergencyAccess($database, true);
    requireCsrfRequest();
    $input = readJsonRequest();
    $items = validateDoctorPrescriptionItems($input['items'] ?? null);
    if ($items === []) {
        jsonError('Add at least one medicine to create a prescription.', [], 422);
    }

    try {
        $database->beginTransaction();
        $visit = emergencyVisitForUpdate($database, (int) $visitId);
        $encounterStatement = $database->prepare(
            'SELECT emergency_encounter_id
             FROM emergency_encounters
             WHERE visit_id = :visit_id AND ended_at IS NULL
             LIMIT 1
             FOR UPDATE'
        );
        $encounterStatement->execute(['visit_id' => (int) $visitId]);
        if ($encounterStatement->fetchColumn() === false) {
            $database->rollBack();
            jsonError('Start the emergency encounter before prescribing.', [], 409);
        }
        $activePrescription = $database->prepare(
            'SELECT prescription_id FROM prescriptions
             WHERE visit_id = :visit_id AND status = \'active\'
             LIMIT 1 FOR UPDATE'
        );
        $activePrescription->execute(['visit_id' => (int) $visitId]);
        if ($activePrescription->fetchColumn() !== false) {
            $database->rollBack();
            jsonError('An active prescription already exists for this emergency visit.', [], 409);
        }

        $prescriptionId = createDoctorPrescription(
            $database,
            (int) $visitId,
            null,
            null,
            (int) $user['user_id'],
            $items
        );
        recordEmergencyAudit(
            $database,
            (int) $user['user_id'],
            'emergency.prescription.created',
            (int) $visit['patient_id'],
            (int) $visitId
        );
        $database->commit();
    } catch (Throwable $exception) {
        if ($database->inTransaction()) {
            $database->rollBack();
        }
        error_log('Emergency prescription creation failed: ' . $exception->getMessage());
        jsonError('Unable to save the emergency prescription right now.', [], 500);
    }

    jsonSuccess('Emergency prescription recorded for pharmacy.', [
        'prescription_id' => $prescriptionId,
        'visit_id' => (int) $visitId,
        'status' => 'active',
        'dispensed' => false,
    ], 201);
}

function handleEmergencyFinish(string $visitId): void
{
    if (!ctype_digit($visitId) || (int) $visitId < 1) {
        jsonError('A valid visit ID is required.', [], 422);
    }
    $database = getDatabaseConnection();
    $user = requireEmergencyAccess($database, true);
    requireCsrfRequest();

    try {
        $database->beginTransaction();
        $visit = emergencyVisitForUpdate($database, (int) $visitId);
        $encounterStatement = $database->prepare(
            'SELECT emergency_encounter_id, ended_at
             FROM emergency_encounters
             WHERE visit_id = :visit_id
             LIMIT 1
             FOR UPDATE'
        );
        $encounterStatement->execute(['visit_id' => (int) $visitId]);
        $encounter = $encounterStatement->fetch();
        if ($encounter === false) {
            $database->rollBack();
            jsonError('Start an emergency encounter before finishing.', [], 409);
        }
        if ($encounter['ended_at'] !== null) {
            $database->rollBack();
            jsonError('This emergency encounter is already finished.', [], 409);
        }

        $prescriptionStatement = $database->prepare(
            'SELECT prescription_id
             FROM prescriptions
             WHERE visit_id = :visit_id AND status = \'active\'
             LIMIT 1'
        );
        $prescriptionStatement->execute(['visit_id' => (int) $visitId]);
        $hasPrescription = $prescriptionStatement->fetchColumn() !== false;
        $nextStatus = $hasPrescription ? 'awaiting_pharmacy' : 'awaiting_payment';

        $encounterUpdate = $database->prepare(
            'UPDATE emergency_encounters
             SET ended_at = CURRENT_TIMESTAMP
             WHERE emergency_encounter_id = :encounter_id AND ended_at IS NULL'
        );
        $encounterUpdate->execute([
            'encounter_id' => (int) $encounter['emergency_encounter_id'],
        ]);
        if ($encounterUpdate->rowCount() !== 1) {
            $database->rollBack();
            jsonError('This emergency encounter is already finished.', [], 409);
        }
        $visitUpdate = $database->prepare(
            'UPDATE visits
             SET status = :status
             WHERE visit_id = :visit_id AND status = \'emergency_in_progress\''
        );
        $visitUpdate->execute([
            'status' => $nextStatus,
            'visit_id' => (int) $visitId,
        ]);
        if ($visitUpdate->rowCount() !== 1) {
            $database->rollBack();
            jsonError('The emergency visit status changed before completion.', [], 409);
        }

        recordEmergencyAudit(
            $database,
            (int) $user['user_id'],
            'emergency.encounter.finished',
            (int) $visit['patient_id'],
            (int) $visitId
        );
        $database->commit();
    } catch (Throwable $exception) {
        if ($database->inTransaction()) {
            $database->rollBack();
        }
        error_log('Emergency encounter completion failed: ' . $exception->getMessage());
        jsonError('Unable to finish the emergency encounter right now.', [], 500);
    }

    jsonSuccess('Emergency encounter finished; charges remain deferred to pharmacy.', [
        'visit_id' => (int) $visitId,
        'visit_status' => $nextStatus,
        'prescription_exists' => $hasPrescription,
        'payment_collected' => false,
    ]);
}
