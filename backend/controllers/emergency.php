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
        'reason_for_visit' => $row['reason_for_visit'],
        'reception_notes' => $row['reception_notes'],
        'visit_source' => $row['visit_source'],
        'source_organization' => $row['source_organization'],
        'status' => $row['visit_status'],
        'encounter_status' => $row['emergency_encounter_id'] === null
            ? 'not_started'
            : ($row['ended_at'] === null ? 'in_progress' : 'completed'),
        'encounter' => $row['emergency_encounter_id'] === null ? null : [
            'emergency_encounter_id' => (int) $row['emergency_encounter_id'],
            'emergency_doctor_id' => $row['emergency_doctor_id'] === null
                ? null
                : (int) $row['emergency_doctor_id'],
            'emergency_doctor_name' => $row['emergency_doctor_name'],
            'triage_level' => $row['triage_level'],
            'stabilization_notes' => $row['stabilization_notes'],
            'handover_status' => $row['handover_status'],
            'handover_requested_at' => $row['handover_requested_at'],
            'handover_accepted_at' => $row['handover_accepted_at'],
            'outcome' => $row['outcome'],
            'outcome_notes' => $row['outcome_notes'],
            'outcome_recorded_at' => $row['outcome_recorded_at'],
            'outcome_recorded_by' => $row['outcome_recorded_by'],
            'started_at' => $row['started_at'],
            'ended_at' => $row['ended_at'],
        ],
    ];
}

function emergencyVisitSelectSql(): string
{
    return 'SELECT DISTINCT visit.visit_id, visit.patient_id, visit.arrived_at,
                   visit.reason_for_visit, visit.reception_notes,
                   visit.visit_source, visit.status AS visit_status,
                   patient.patient_number, patient.first_name, patient.last_name,
                   patient.date_of_birth, patient.gender, patient.phone,
                   patient.address,
                   patient.is_temporary, organization.name AS source_organization,
                   encounter.emergency_encounter_id, encounter.emergency_doctor_id,
                   encounter.triage_level, encounter.stabilization_notes,
                   encounter.handover_status, encounter.handover_requested_at,
                   encounter.handover_accepted_at, encounter.outcome,
                   encounter.outcome_notes, encounter.outcome_recorded_at,
                   outcome_user.display_name AS outcome_recorded_by,
                   encounter.started_at, encounter.ended_at,
                   doctor.display_name AS emergency_doctor_name
            FROM visits visit
            INNER JOIN patients patient ON patient.patient_id = visit.patient_id
            LEFT JOIN organizations organization
                ON organization.organization_id = visit.source_organization_id
            LEFT JOIN emergency_encounters encounter
                ON encounter.visit_id = visit.visit_id
            LEFT JOIN users doctor
                ON doctor.user_id = encounter.emergency_doctor_id
            LEFT JOIN users outcome_user
                ON outcome_user.user_id = encounter.outcome_recorded_by_user_id';
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

function handleEmergencyDoctorDashboard(): void
{
    $database = getDatabaseConnection();
    $user = requireEmergencyAccess($database);
    if (!in_array($user['role_name'], ['emergency_doctor', 'system_administrator'], true)) {
        jsonError('Emergency Doctor access is not authorized.', [], 403);
    }

    $statement = $database->prepare(
        "SELECT
            (SELECT COUNT(*)
             FROM visits visit
             LEFT JOIN emergency_encounters encounter
                ON encounter.visit_id = visit.visit_id
             WHERE visit.is_emergency = 1
               AND visit.status = 'emergency_in_progress'
               AND (encounter.emergency_encounter_id IS NULL
                    OR (encounter.ended_at IS NULL
                        AND encounter.handover_status = 'pending'))) AS awaiting_doctor,
            (SELECT COUNT(*)
             FROM emergency_encounters encounter
             INNER JOIN visits visit ON visit.visit_id = encounter.visit_id
             WHERE visit.status = 'emergency_in_progress'
               AND encounter.ended_at IS NULL
               AND encounter.handover_status = 'pending') AS pending_handovers,
            (SELECT COUNT(*)
             FROM emergency_encounters encounter
             INNER JOIN visits visit ON visit.visit_id = encounter.visit_id
             WHERE visit.status = 'emergency_in_progress'
               AND encounter.ended_at IS NULL
               AND (:is_admin = 1
                    OR encounter.emergency_doctor_id = :doctor_id)) AS active_cases,
            (SELECT COUNT(*)
             FROM emergency_encounters encounter
             INNER JOIN visits visit ON visit.visit_id = encounter.visit_id
             WHERE encounter.ended_at IS NOT NULL
                OR visit.status IN
                    ('awaiting_payment', 'awaiting_pharmacy', 'completed', 'cancelled'))
                AS previous_cases"
    );
    $statement->execute([
        'is_admin' => $user['role_name'] === 'system_administrator' ? 1 : 0,
        'doctor_id' => (int) $user['user_id'],
    ]);
    $counts = $statement->fetch();
    jsonSuccess('Emergency Doctor dashboard loaded.', [
        'stats' => [
            'awaiting_doctor' => (int) $counts['awaiting_doctor'],
            'pending_handovers' => (int) $counts['pending_handovers'],
            'active_cases' => (int) $counts['active_cases'],
            'previous_cases' => (int) $counts['previous_cases'],
        ],
    ]);
}

function handleEmergencyDoctorQueueGet(string $section, string $search): void
{
    $database = getDatabaseConnection();
    $user = requireEmergencyAccess($database);
    if (!in_array($user['role_name'], ['emergency_doctor', 'system_administrator'], true)) {
        jsonError('Emergency Doctor access is not authorized.', [], 403);
    }
    if (!in_array($section, ['queue', 'handovers', 'active', 'history'], true)) {
        jsonError('Choose an Emergency Doctor activity.', [
            'section' => 'Choose queue, handovers, active, or history.',
        ], 422);
    }
    $search = trim($search);
    if (strlen($search) > 100) {
        jsonError('Search text must be 100 characters or fewer.', [
            'search' => 'Shorten the search text.',
        ], 422);
    }

    $where = match ($section) {
        'queue' => "visit.status = 'emergency_in_progress'
            AND encounter.ended_at IS NULL
            AND encounter.handover_status = 'pending'",
        'handovers' => "visit.status = 'emergency_in_progress'
            AND encounter.handover_status IN ('pending', 'accepted')",
        'active' => "visit.status = 'emergency_in_progress'
            AND encounter.ended_at IS NULL
            AND encounter.emergency_doctor_id = :doctor_id",
        default => "(encounter.ended_at IS NOT NULL
            OR visit.status IN
                ('awaiting_payment', 'awaiting_pharmacy', 'completed', 'cancelled'))",
    };
    $parameters = [];
    if ($section === 'active' && $user['role_name'] !== 'system_administrator') {
        $parameters['doctor_id'] = (int) $user['user_id'];
    } elseif ($section === 'active') {
        $where = "visit.status = 'emergency_in_progress'
            AND encounter.ended_at IS NULL
            AND encounter.emergency_doctor_id IS NOT NULL";
    }

    $searchSql = '';
    if ($search !== '') {
        $pattern = '%' . $search . '%';
        $searchSql = ' AND (
            patient.patient_number LIKE :patient_number
            OR patient.first_name LIKE :first_name
            OR patient.last_name LIKE :last_name
            OR CONCAT_WS(\' \', patient.first_name, patient.last_name) LIKE :full_name
            OR CAST(visit.visit_id AS CHAR) LIKE :visit_id
        )';
        $parameters += [
            'patient_number' => $pattern,
            'first_name' => $pattern,
            'last_name' => $pattern,
            'full_name' => $pattern,
            'visit_id' => $pattern,
        ];
    }

    $orderBy = $section === 'history'
        ? 'COALESCE(encounter.ended_at, visit.arrived_at) DESC, visit.visit_id DESC'
        : 'CASE encounter.handover_status WHEN \'pending\' THEN 0 ELSE 1 END,
           encounter.handover_requested_at ASC, visit.arrived_at ASC, visit.visit_id ASC';
    $statement = $database->prepare(
        emergencyVisitSelectSql()
            . ' WHERE visit.is_emergency = 1 AND ' . $where . $searchSql
            . ' ORDER BY ' . $orderBy . ' LIMIT 250'
    );
    $statement->execute($parameters);
    $visits = array_map('emergencyVisitSummary', $statement->fetchAll());
    foreach ($visits as $visit) {
        recordEmergencyAudit(
            $database,
            (int) $user['user_id'],
            'emergency.doctor.' . $section . '.viewed',
            $visit['patient_id'],
            $visit['visit_id']
        );
    }

    jsonSuccess('Emergency Doctor activity loaded.', [
        'section' => $section,
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
            . '             WHERE visit.visit_id = :visit_id
                   AND visit.is_emergency = 1
                 LIMIT 1'
    );
    $statement->execute(['visit_id' => (int) $visitId]);
    $row = $statement->fetch();
    if ($row === false) {
        jsonError('Emergency visit not found or no longer active.', [], 404);
    }

    $nurseStatement = $database->prepare(
        'SELECT nurse.user_id, nurse.display_name, nurse.username,
                attending.assigned_at
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
            'assigned_at' => $nurse['assigned_at'],
        ];
    }, $nurseStatement->fetchAll());

    $availableNurses = [];
    $medicines = [];
    $specialists = [];
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

        $specialistStatement = $database->query(
            "SELECT user.user_id, user.display_name, role.role_name
             FROM users user
             INNER JOIN roles role ON role.role_id = user.role_id
             WHERE user.is_active = 1
               AND role.role_name IN ('resident_specialist', 'locum_specialist')
             ORDER BY role.role_name, user.display_name"
        );
        $specialists = array_map(static function (array $specialist): array {
            return [
                'user_id' => (int) $specialist['user_id'],
                'display_name' => $specialist['display_name'],
                'role_name' => $specialist['role_name'],
            ];
        }, $specialistStatement->fetchAll());
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

    $nursingAssessment = null;
    $observations = [];
    $doctorNotes = [];
    if ($row['emergency_encounter_id'] !== null) {
        $assessmentStatement = $database->prepare(
            'SELECT assessment.*, nurse.display_name AS nurse_name
             FROM emergency_nursing_assessments assessment
             INNER JOIN users nurse ON nurse.user_id = assessment.nurse_user_id
             WHERE assessment.emergency_encounter_id = :encounter_id
             LIMIT 1'
        );
        $assessmentStatement->execute([
            'encounter_id' => (int) $row['emergency_encounter_id'],
        ]);
        $assessment = $assessmentStatement->fetch();
        if ($assessment !== false) {
            $nursingAssessment = $assessment;
        }

        $observationStatement = $database->prepare(
            'SELECT observation.*, nurse.display_name AS nurse_name
             FROM emergency_nursing_observations observation
             INNER JOIN users nurse ON nurse.user_id = observation.nurse_user_id
             WHERE observation.emergency_encounter_id = :encounter_id
             ORDER BY observation.recorded_at DESC,
                      observation.emergency_nursing_observation_id DESC
             LIMIT 25'
        );
        $observationStatement->execute([
            'encounter_id' => (int) $row['emergency_encounter_id'],
        ]);
        $observations = $observationStatement->fetchAll();

        $doctorNotesStatement = $database->prepare(
            'SELECT note.note_type, note.note_text, note.created_at,
                    doctor.display_name AS doctor_name
             FROM emergency_doctor_notes note
             INNER JOIN users doctor ON doctor.user_id = note.doctor_user_id
             WHERE note.emergency_encounter_id = :encounter_id
             ORDER BY note.created_at DESC, note.emergency_doctor_note_id DESC
             LIMIT 50'
        );
        $doctorNotesStatement->execute([
            'encounter_id' => (int) $row['emergency_encounter_id'],
        ]);
        $doctorNotes = $doctorNotesStatement->fetchAll();
    }

    $clinicalStatement = $database->prepare(
        'SELECT consultation.consultation_id, consultation.clinician_user_id,
                consultation.presenting_complaint,
                consultation.clinical_observations,
                consultation.examination_findings, consultation.notes,
                consultation.diagnosis_made, consultation.diagnosis_text,
                consultation.treatment_plan, consultation.next_step,
                consultation.consulted_at, clinician.display_name AS clinician_name,
                diagnosis.diagnosis_id, diagnosis.diagnosis_stage,
                diagnosis.diagnosis_description, diagnosis.clinical_findings,
                diagnosis.treatment_plan AS diagnosis_treatment_plan,
                diagnosis.notes AS diagnosis_notes, diagnosis.diagnosed_at
         FROM consultations consultation
         INNER JOIN users clinician
            ON clinician.user_id = consultation.clinician_user_id
         LEFT JOIN diagnoses diagnosis
            ON diagnosis.consultation_id = consultation.consultation_id
         WHERE consultation.visit_id = :visit_id
           AND consultation.consultation_type = \'emergency\'
         ORDER BY consultation.consulted_at DESC, diagnosis.diagnosis_id DESC'
    );
    $clinicalStatement->execute(['visit_id' => (int) $visitId]);
    $clinicalRecords = $clinicalStatement->fetchAll();

    $orderStatement = $database->prepare(
        'SELECT test_order.test_order_id, test_order.test_type,
                test_order.department, test_order.status AS order_status,
                test_order.ordered_at, test_result.test_result_id,
                test_result.result_text, test_result.result_notes,
                test_result.status AS result_status, test_result.recorded_at,
                recorder.display_name AS recorded_by
         FROM test_orders test_order
         LEFT JOIN test_results test_result
            ON test_result.test_order_id = test_order.test_order_id
         LEFT JOIN users recorder
            ON recorder.user_id = test_result.recorded_by_user_id
         WHERE test_order.visit_id = :visit_id
         ORDER BY test_order.ordered_at DESC, test_order.test_order_id DESC,
                  test_result.recorded_at DESC'
    );
    $orderStatement->execute(['visit_id' => (int) $visitId]);
    $testOrders = [];
    foreach ($orderStatement->fetchAll() as $order) {
        $orderId = (int) $order['test_order_id'];
        if (!isset($testOrders[$orderId])) {
            $testOrders[$orderId] = [
                'test_order_id' => $orderId,
                'test_type' => $order['test_type'],
                'department' => $order['department'],
                'status' => $order['order_status'],
                'ordered_at' => $order['ordered_at'],
                'results' => [],
            ];
        }
        if ($order['test_result_id'] !== null) {
            $testOrders[$orderId]['results'][] = [
                'test_result_id' => (int) $order['test_result_id'],
                'text' => $order['result_text'],
                'notes' => $order['result_notes'],
                'status' => $order['result_status'],
                'recorded_at' => $order['recorded_at'],
                'recorded_by' => $order['recorded_by'],
            ];
        }
    }

    $referralStatement = $database->prepare(
        'SELECT referral.specialist_referral_id AS referral_id,
                \'specialist\' AS referral_type, referral.reason,
                referral.clinical_summary, referral.status, referral.created_at,
                specialist.display_name AS destination
         FROM specialist_referrals referral
         INNER JOIN users specialist
            ON specialist.user_id = referral.specialist_user_id
         WHERE referral.visit_id = :specialist_visit
         UNION ALL
         SELECT referral.outside_referral_id AS referral_id,
                \'outside\' AS referral_type, NULL AS reason,
                referral.clinical_summary, referral.status, referral.created_at,
                COALESCE(referral.provider_name, organization.name) AS destination
         FROM outside_referrals referral
         LEFT JOIN organizations organization
            ON organization.organization_id = referral.organization_id
         WHERE referral.visit_id = :outside_visit
         ORDER BY created_at DESC'
    );
    $referralStatement->execute([
        'specialist_visit' => (int) $visitId,
        'outside_visit' => (int) $visitId,
    ]);
    $referrals = $referralStatement->fetchAll();

    $previousEmergencyStatement = $database->prepare(
        'SELECT previous_visit.visit_id, previous_visit.arrived_at,
                previous_visit.status,
                previous_visit.reason_for_visit,
                previous_encounter.triage_level,
                previous_encounter.outcome,
                previous_encounter.ended_at
         FROM visits previous_visit
         LEFT JOIN emergency_encounters previous_encounter
            ON previous_encounter.visit_id = previous_visit.visit_id
         WHERE previous_visit.patient_id = :patient_id
           AND previous_visit.is_emergency = 1
           AND previous_visit.visit_id <> :current_visit_id
         ORDER BY previous_visit.arrived_at DESC
         LIMIT 20'
    );
    $previousEmergencyStatement->execute([
        'patient_id' => (int) $row['patient_id'],
        'current_visit_id' => (int) $visitId,
    ]);

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
        'specialists' => $specialists,
        'prescriptions' => $prescriptions,
        'nursing_assessment' => $nursingAssessment,
        'nursing_observations' => $observations,
        'doctor_notes' => $doctorNotes,
        'clinical_records' => $clinicalRecords,
        'test_orders' => array_values($testOrders),
        'referrals' => $referrals,
        'previous_emergency_cases' => $previousEmergencyStatement->fetchAll(),
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
            'INSERT INTO emergency_nurses
                (emergency_encounter_id, nurse_user_id, assigned_at)
             VALUES (:encounter_id, :nurse_id, CURRENT_TIMESTAMP)'
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
            'SELECT emergency_encounter_id, emergency_doctor_id
             FROM emergency_encounters
             WHERE visit_id = :visit_id AND ended_at IS NULL
             LIMIT 1
             FOR UPDATE'
        );
        $encounterStatement->execute(['visit_id' => (int) $visitId]);
        $encounter = $encounterStatement->fetch();
        if ($encounter === false
            || (int) $encounter['emergency_doctor_id'] !== (int) $user['user_id']) {
            $database->rollBack();
            jsonError('Accept the handover before prescribing.', [], 409);
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

        $consultationStatement = $database->prepare(
            "SELECT consultation_id
             FROM consultations
             WHERE visit_id = :visit_id
               AND clinician_user_id = :doctor_id
               AND consultation_type = 'emergency'
             ORDER BY consulted_at DESC, consultation_id DESC
             LIMIT 1"
        );
        $consultationStatement->execute([
            'visit_id' => (int) $visitId,
            'doctor_id' => (int) $user['user_id'],
        ]);
        $consultationId = $consultationStatement->fetchColumn();
        $diagnosisId = null;
        if ($consultationId !== false) {
            $diagnosisStatement = $database->prepare(
                'SELECT diagnosis_id
                 FROM diagnoses
                 WHERE consultation_id = :consultation_id
                 ORDER BY diagnosed_at DESC, diagnosis_id DESC
                 LIMIT 1'
            );
            $diagnosisStatement->execute([
                'consultation_id' => (int) $consultationId,
            ]);
            $diagnosisId = $diagnosisStatement->fetchColumn();
        }
        $prescriptionId = createDoctorPrescription(
            $database,
            (int) $visitId,
            $consultationId === false ? null : (int) $consultationId,
            $diagnosisId === false ? null : (int) $diagnosisId,
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
            'SELECT emergency_encounter_id, emergency_doctor_id, ended_at
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
        if ((int) $encounter['emergency_doctor_id'] !== (int) $user['user_id']) {
            $database->rollBack();
            jsonError('Only the responsible Emergency Doctor can finish this encounter.', [], 403);
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
             SET ended_at = CURRENT_TIMESTAMP,
                 outcome = \'discharge\',
                 outcome_recorded_at = CURRENT_TIMESTAMP,
                 outcome_recorded_by_user_id = :doctor_id
             WHERE emergency_encounter_id = :encounter_id AND ended_at IS NULL'
        );
        $encounterUpdate->execute([
            'doctor_id' => (int) $user['user_id'],
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

function requireEmergencyNurseRead(PDO $database): array
{
    $user = requireEmergencyAccess($database);
    if (!in_array($user['role_name'], ['emergency_nurse', 'system_administrator'], true)) {
        jsonError('Emergency nurse access is not authorized.', [], 403);
    }

    return $user;
}

function requireEmergencyNurseWrite(array $user): void
{
    if ($user['role_name'] !== 'emergency_nurse') {
        jsonError('Only an authenticated emergency nurse can record nursing care.', [], 403);
    }
}

function emergencyNurseEncounter(PDO $database, int $visitId, bool $forUpdate = false): array
{
    $sql = 'SELECT encounter.emergency_encounter_id, encounter.ended_at,
                   encounter.handover_status, encounter.triage_level,
                   visit.patient_id, visit.status AS visit_status
            FROM visits visit
            INNER JOIN emergency_encounters encounter
                ON encounter.visit_id = visit.visit_id
            WHERE visit.visit_id = :visit_id
              AND visit.is_emergency = 1
            LIMIT 1';
    if ($forUpdate) {
        $sql .= ' FOR UPDATE';
    }
    $statement = $database->prepare($sql);
    $statement->execute(['visit_id' => $visitId]);
    $encounter = $statement->fetch();
    if ($encounter === false) {
        jsonError('A nurse must acknowledge this emergency visit first.', [], 409);
    }
    if ($encounter['ended_at'] !== null) {
        jsonError('This emergency encounter is already complete.', [], 409);
    }
    if ($encounter['visit_status'] !== 'emergency_in_progress') {
        jsonError('This emergency visit is no longer active.', [], 409);
    }

    return $encounter;
}

function emergencyNurseAssignment(PDO $database, int $encounterId, int $nurseId): void
{
    $statement = $database->prepare(
        'SELECT nurse_user_id
         FROM emergency_nurses
         WHERE emergency_encounter_id = :encounter_id
           AND nurse_user_id = :nurse_id
         LIMIT 1'
    );
    $statement->execute([
        'encounter_id' => $encounterId,
        'nurse_id' => $nurseId,
    ]);
    if ($statement->fetchColumn() === false) {
        jsonError('Acknowledge this patient before recording nursing care.', [], 403);
    }
}

function handleEmergencyNurseDashboard(): void
{
    $database = getDatabaseConnection();
    $user = requireEmergencyNurseRead($database);
    $parameters = $user['role_name'] === 'system_administrator'
        ? []
        : [
            'nurse_id_active' => (int) $user['user_id'],
            'nurse_id_handover' => (int) $user['user_id'],
            'nurse_id_history' => (int) $user['user_id'],
        ];
    $nurseFilterActive = $user['role_name'] === 'system_administrator'
        ? ''
        : ' AND attending.nurse_user_id = :nurse_id_active';
    $nurseFilterHandover = $user['role_name'] === 'system_administrator'
        ? ''
        : ' AND attending.nurse_user_id = :nurse_id_handover';
    $nurseFilterHistory = $user['role_name'] === 'system_administrator'
        ? ''
        : ' AND attending.nurse_user_id = :nurse_id_history';
    $statement = $database->prepare(
        "SELECT
            (SELECT COUNT(*)
             FROM visits visit
             WHERE visit.is_emergency = 1
               AND visit.status = 'emergency_in_progress'
               AND NOT EXISTS (
                    SELECT 1
                    FROM emergency_encounters assigned_encounter
                    INNER JOIN emergency_nurses assigned_nurse
                        ON assigned_nurse.emergency_encounter_id =
                           assigned_encounter.emergency_encounter_id
                    WHERE assigned_encounter.visit_id = visit.visit_id
               )) AS incoming,
            (SELECT COUNT(DISTINCT visit.visit_id)
             FROM visits visit
             INNER JOIN emergency_encounters encounter
                ON encounter.visit_id = visit.visit_id
             INNER JOIN emergency_nurses attending
                ON attending.emergency_encounter_id =
                   encounter.emergency_encounter_id
             WHERE visit.is_emergency = 1
               AND visit.status = 'emergency_in_progress'
               AND encounter.ended_at IS NULL
               AND encounter.handover_status <> 'pending'
               $nurseFilterActive) AS active,
            (SELECT COUNT(DISTINCT visit.visit_id)
             FROM visits visit
             INNER JOIN emergency_encounters encounter
                ON encounter.visit_id = visit.visit_id
             INNER JOIN emergency_nurses attending
                ON attending.emergency_encounter_id =
                   encounter.emergency_encounter_id
             WHERE visit.is_emergency = 1
               AND encounter.handover_status IN ('pending', 'accepted')
               $nurseFilterHandover) AS handover,
            (SELECT COUNT(DISTINCT visit.visit_id)
             FROM visits visit
             LEFT JOIN emergency_encounters encounter
                ON encounter.visit_id = visit.visit_id
             LEFT JOIN emergency_nurses attending
                ON attending.emergency_encounter_id =
                   encounter.emergency_encounter_id
             WHERE visit.is_emergency = 1
               AND (visit.status <> 'emergency_in_progress'
                    OR encounter.ended_at IS NOT NULL
                    OR EXISTS (
                        SELECT 1
                        FROM emergency_nursing_assessments completed_assessment
                        WHERE completed_assessment.emergency_encounter_id =
                              encounter.emergency_encounter_id
                          AND completed_assessment.status = 'complete'
                    ))
               $nurseFilterHistory) AS history"
    );
    $statement->execute($parameters);
    $stats = $statement->fetch();

    jsonSuccess('Emergency nursing dashboard loaded.', [
        'stats' => [
            'incoming' => (int) $stats['incoming'],
            'active' => (int) $stats['active'],
            'handover' => (int) $stats['handover'],
            'history' => (int) $stats['history'],
        ],
    ]);
}

function handleEmergencyNurseQueue(string $section, string $search): void
{
    if (!in_array($section, ['incoming', 'active', 'handover', 'history'], true)) {
        jsonError('Choose a supported emergency nursing activity.', [], 404);
    }
    if (strlen(trim($search)) > 100) {
        jsonError('Search text must be 100 characters or fewer.', [
            'search' => 'Shorten the search text.',
        ], 422);
    }

    $database = getDatabaseConnection();
    $user = requireEmergencyNurseRead($database);
    $where = match ($section) {
        'incoming' => "visit.status = 'emergency_in_progress'
            AND NOT EXISTS (
                SELECT 1 FROM emergency_encounters assigned_encounter
                INNER JOIN emergency_nurses assigned_nurse
                    ON assigned_nurse.emergency_encounter_id =
                       assigned_encounter.emergency_encounter_id
                WHERE assigned_encounter.visit_id = visit.visit_id
            )",
        'active' => "visit.status = 'emergency_in_progress'
            AND encounter.ended_at IS NULL
            AND encounter.handover_status <> 'pending'
            AND attending.nurse_user_id IS NOT NULL",
        'handover' => "encounter.handover_status IN ('pending', 'accepted')
            AND attending.nurse_user_id IS NOT NULL",
        default => "(visit.status <> 'emergency_in_progress'
            OR encounter.ended_at IS NOT NULL
            OR assessment.status = 'complete')
            AND attending.nurse_user_id IS NOT NULL",
    };
    $parameters = ['is_emergency' => 1];
    if ($user['role_name'] !== 'system_administrator') {
        $where .= ' AND (attending.nurse_user_id = :nurse_id OR '
            . ($section === 'incoming'
                ? 'attending.nurse_user_id IS NULL'
                : '1 = 0')
            . ')';
        $parameters['nurse_id'] = (int) $user['user_id'];
    }
    $search = trim($search);
    if ($search !== '') {
        $where .= ' AND (
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
            . ' LEFT JOIN emergency_nurses attending
                    ON attending.emergency_encounter_id =
                       encounter.emergency_encounter_id
               LEFT JOIN emergency_nursing_assessments assessment
                    ON assessment.emergency_encounter_id =
                       encounter.emergency_encounter_id
               WHERE visit.is_emergency = :is_emergency AND '
            . $where
            . ' ORDER BY visit.arrived_at DESC, visit.visit_id DESC
               LIMIT 250'
    );
    $statement->execute($parameters);
    $visits = array_map('emergencyVisitSummary', $statement->fetchAll());
    foreach ($visits as $visit) {
        recordEmergencyAudit(
            $database,
            (int) $user['user_id'],
            'emergency.nurse.' . $section . '.viewed',
            $visit['patient_id'],
            $visit['visit_id']
        );
    }

    jsonSuccess('Emergency nursing activity loaded.', [
        'visits' => $visits,
        'count' => count($visits),
    ]);
}

function handleEmergencyNurseVisitGet(string $visitId): void
{
    if (!ctype_digit($visitId) || (int) $visitId < 1) {
        jsonError('A valid visit ID is required.', [], 422);
    }
    $database = getDatabaseConnection();
    $user = requireEmergencyNurseRead($database);
    $statement = $database->prepare(
        emergencyVisitSelectSql()
            . ' WHERE visit.visit_id = :visit_id AND visit.is_emergency = 1
               LIMIT 1'
    );
    $statement->execute(['visit_id' => (int) $visitId]);
    $row = $statement->fetch();
    if ($row === false) {
        jsonError('Emergency visit not found.', [], 404);
    }

    $assessment = null;
    $observations = [];
    $attendingNurses = [];
    $doctorNotes = [];
    $doctorConsultations = [];
    if ($row['emergency_encounter_id'] !== null) {
        $assessmentStatement = $database->prepare(
            'SELECT assessment.*, nurse.display_name AS nurse_name
             FROM emergency_nursing_assessments assessment
             INNER JOIN users nurse ON nurse.user_id = assessment.nurse_user_id
             WHERE assessment.emergency_encounter_id = :encounter_id
             LIMIT 1'
        );
        $assessmentStatement->execute([
            'encounter_id' => (int) $row['emergency_encounter_id'],
        ]);
        $assessment = $assessmentStatement->fetch();
        if ($assessment === false) {
            $assessment = null;
        }
        $observationStatement = $database->prepare(
            'SELECT observation.*, nurse.display_name AS nurse_name
             FROM emergency_nursing_observations observation
             INNER JOIN users nurse ON nurse.user_id = observation.nurse_user_id
             WHERE observation.emergency_encounter_id = :encounter_id
             ORDER BY observation.recorded_at DESC,
                      observation.emergency_nursing_observation_id DESC
             LIMIT 50'
        );
        $observationStatement->execute([
            'encounter_id' => (int) $row['emergency_encounter_id'],
        ]);
        $observations = $observationStatement->fetchAll();

        $nurseStatement = $database->prepare(
            'SELECT nurse.user_id, nurse.display_name, attending.assigned_at
             FROM emergency_nurses attending
             INNER JOIN users nurse ON nurse.user_id = attending.nurse_user_id
             WHERE attending.emergency_encounter_id = :encounter_id
             ORDER BY nurse.display_name'
        );
        $nurseStatement->execute([
            'encounter_id' => (int) $row['emergency_encounter_id'],
        ]);
        $attendingNurses = $nurseStatement->fetchAll();

        $doctorNotesStatement = $database->prepare(
            'SELECT note.note_type, note.note_text, note.created_at,
                    doctor.display_name AS doctor_name
             FROM emergency_doctor_notes note
             INNER JOIN users doctor ON doctor.user_id = note.doctor_user_id
             WHERE note.emergency_encounter_id = :encounter_id
             ORDER BY note.created_at DESC, note.emergency_doctor_note_id DESC
             LIMIT 50'
        );
        $doctorNotesStatement->execute([
            'encounter_id' => (int) $row['emergency_encounter_id'],
        ]);
        $doctorNotes = $doctorNotesStatement->fetchAll();

        $doctorConsultationStatement = $database->prepare(
            'SELECT consultation.presenting_complaint,
                    consultation.clinical_observations,
                    consultation.examination_findings, consultation.notes,
                    consultation.diagnosis_made, consultation.diagnosis_text,
                    consultation.treatment_plan, consultation.next_step,
                    consultation.consulted_at,
                    doctor.display_name AS doctor_name
             FROM consultations consultation
             INNER JOIN users doctor
                ON doctor.user_id = consultation.clinician_user_id
             WHERE consultation.visit_id = :visit_id
               AND consultation.consultation_type = \'emergency\'
             ORDER BY consultation.consulted_at DESC
             LIMIT 20'
        );
        $doctorConsultationStatement->execute(['visit_id' => (int) $visitId]);
        $doctorConsultations = $doctorConsultationStatement->fetchAll();
    }

    $historyStatement = $database->prepare(
        'SELECT visit.visit_id, visit.arrived_at, visit.status,
                visit.reason_for_visit,
                consultation.consultation_type,
                consultation.presenting_complaint,
                consultation.diagnosis_text,
                consultation.treatment_plan,
                consultation.notes AS consultation_notes
         FROM visits visit
         LEFT JOIN consultations consultation
            ON consultation.visit_id = visit.visit_id
         WHERE visit.patient_id = :patient_id
           AND visit.visit_id <> :current_visit_id
         ORDER BY visit.arrived_at DESC, consultation.consulted_at DESC
         LIMIT 20'
    );
    $historyStatement->execute([
        'patient_id' => (int) $row['patient_id'],
        'current_visit_id' => (int) $visitId,
    ]);

    $previousNursingStatement = $database->prepare(
        'SELECT assessment.triage_priority, assessment.symptoms,
                assessment.allergies, assessment.visible_injuries,
                assessment.nursing_notes, assessment.updated_at,
                previous_visit.visit_id
         FROM emergency_nursing_assessments assessment
         INNER JOIN emergency_encounters encounter
            ON encounter.emergency_encounter_id =
               assessment.emergency_encounter_id
         INNER JOIN visits previous_visit
            ON previous_visit.visit_id = encounter.visit_id
         WHERE previous_visit.patient_id = :patient_id
           AND previous_visit.visit_id <> :current_visit_id
         ORDER BY assessment.updated_at DESC
         LIMIT 10'
    );
    $previousNursingStatement->execute([
        'patient_id' => (int) $row['patient_id'],
        'current_visit_id' => (int) $visitId,
    ]);

    $previousGeneralNursingStatement = $database->prepare(
        'SELECT assessment.complaint, assessment.symptoms,
                assessment.blood_pressure, assessment.pulse,
                assessment.temperature, assessment.notes,
                assessment.decision, assessment.assessed_at,
                previous_visit.visit_id
         FROM nurse_assessments assessment
         INNER JOIN visits previous_visit
            ON previous_visit.visit_id = assessment.visit_id
         WHERE previous_visit.patient_id = :patient_id
           AND previous_visit.visit_id <> :current_visit_id
         ORDER BY assessment.assessed_at DESC
         LIMIT 10'
    );
    $previousGeneralNursingStatement->execute([
        'patient_id' => (int) $row['patient_id'],
        'current_visit_id' => (int) $visitId,
    ]);

    $previousTestsStatement = $database->prepare(
        'SELECT test_order.test_type, test_result.result_text,
                test_result.result_notes, test_result.status,
                test_result.recorded_at, previous_visit.visit_id
         FROM test_orders test_order
         INNER JOIN visits previous_visit
            ON previous_visit.visit_id = test_order.visit_id
         INNER JOIN test_results test_result
            ON test_result.test_order_id = test_order.test_order_id
         WHERE previous_visit.patient_id = :patient_id
           AND previous_visit.visit_id <> :current_visit_id
         ORDER BY test_result.recorded_at DESC
         LIMIT 20'
    );
    $previousTestsStatement->execute([
        'patient_id' => (int) $row['patient_id'],
        'current_visit_id' => (int) $visitId,
    ]);

    $previousPrescriptionsStatement = $database->prepare(
        'SELECT prescription.status, prescription.created_at,
                medicine.medicine_name, item.dosage, item.frequency,
                item.duration, previous_visit.visit_id
         FROM prescriptions prescription
         INNER JOIN visits previous_visit
            ON previous_visit.visit_id = prescription.visit_id
         INNER JOIN prescription_items item
            ON item.prescription_id = prescription.prescription_id
         INNER JOIN medicines medicine
            ON medicine.medicine_id = item.medicine_id
         WHERE previous_visit.patient_id = :patient_id
           AND previous_visit.visit_id <> :current_visit_id
         ORDER BY prescription.created_at DESC
         LIMIT 20'
    );
    $previousPrescriptionsStatement->execute([
        'patient_id' => (int) $row['patient_id'],
        'current_visit_id' => (int) $visitId,
    ]);

    recordEmergencyAudit(
        $database,
        (int) $user['user_id'],
        'emergency.nurse.visit.viewed',
        (int) $row['patient_id'],
        (int) $visitId
    );
    jsonSuccess('Emergency nursing visit loaded.', [
        'visit' => emergencyVisitSummary($row),
        'reason_for_visit' => $row['reason_for_visit'],
        'reception_notes' => $row['reception_notes'] ?? null,
        'patient' => [
            'patient_id' => (int) $row['patient_id'],
            'patient_number' => $row['patient_number'],
            'patient_name' => trim(
                (string) $row['first_name'] . ' ' . (string) $row['last_name']
            ),
            'date_of_birth' => $row['date_of_birth'],
            'gender' => $row['gender'],
            'phone' => $row['phone'],
            'address' => $row['address'],
            'is_temporary' => (bool) $row['is_temporary'],
        ],
        'assessment' => $assessment,
        'observations' => $observations,
        'attending_nurses' => array_map(static function (array $nurse): array {
            return [
                'user_id' => (int) $nurse['user_id'],
                'display_name' => $nurse['display_name'],
                'assigned_at' => $nurse['assigned_at'],
            ];
        }, $attendingNurses),
        'doctor_notes' => $doctorNotes,
        'doctor_consultations' => $doctorConsultations,
        'previous_visits' => $historyStatement->fetchAll(),
        'previous_emergency_assessments' => $previousNursingStatement->fetchAll(),
        'previous_nursing_assessments' => $previousGeneralNursingStatement->fetchAll(),
        'previous_test_results' => $previousTestsStatement->fetchAll(),
        'previous_prescriptions' => $previousPrescriptionsStatement->fetchAll(),
        'doctor_availability_supported' => false,
    ]);
}

function handleEmergencyNurseAcknowledge(string $visitId): void
{
    if (!ctype_digit($visitId) || (int) $visitId < 1) {
        jsonError('A valid visit ID is required.', [], 422);
    }
    $database = getDatabaseConnection();
    $user = requireEmergencyNurseRead($database);
    requireEmergencyNurseWrite($user);
    requireCsrfRequest();

    try {
        $database->beginTransaction();
        $visitStatement = $database->prepare(
            'SELECT visit_id, patient_id, status, is_emergency
             FROM visits
             WHERE visit_id = :visit_id
             LIMIT 1
             FOR UPDATE'
        );
        $visitStatement->execute(['visit_id' => (int) $visitId]);
        $visit = $visitStatement->fetch();
        if ($visit === false || (int) $visit['is_emergency'] !== 1) {
            $database->rollBack();
            jsonError('Emergency visit not found.', [], 404);
        }
        if ($visit['status'] !== 'emergency_in_progress') {
            $database->rollBack();
            jsonError('This emergency visit is no longer active.', [], 409);
        }

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
            $insertEncounter = $database->prepare(
                'INSERT INTO emergency_encounters
                    (visit_id, emergency_doctor_id, handover_status)
                 VALUES (:visit_id, NULL, \'not_requested\')'
            );
            $insertEncounter->execute(['visit_id' => (int) $visitId]);
            $encounterId = (int) $database->lastInsertId();
        } else {
            if ($encounter['ended_at'] !== null) {
                $database->rollBack();
                jsonError('This emergency encounter is already complete.', [], 409);
            }
            $encounterId = (int) $encounter['emergency_encounter_id'];
        }

        $existing = $database->prepare(
            'SELECT nurse_user_id
             FROM emergency_nurses
             WHERE emergency_encounter_id = :encounter_id
               AND nurse_user_id = :nurse_id
             LIMIT 1'
        );
        $existing->execute([
            'encounter_id' => $encounterId,
            'nurse_id' => (int) $user['user_id'],
        ]);
        if ($existing->fetchColumn() !== false) {
            $database->rollBack();
            jsonError('You have already acknowledged this emergency patient.', [], 409);
        }
        $assign = $database->prepare(
            'INSERT INTO emergency_nurses
                (emergency_encounter_id, nurse_user_id, assigned_at)
             VALUES (:encounter_id, :nurse_id, CURRENT_TIMESTAMP)'
        );
        $assign->execute([
            'encounter_id' => $encounterId,
            'nurse_id' => (int) $user['user_id'],
        ]);
        recordEmergencyAudit(
            $database,
            (int) $user['user_id'],
            'emergency.nurse.patient.acknowledged',
            (int) $visit['patient_id'],
            (int) $visitId
        );
        $database->commit();
    } catch (Throwable $exception) {
        if ($database->inTransaction()) {
            $database->rollBack();
        }
        error_log('Emergency nurse acknowledgement failed: ' . $exception->getMessage());
        jsonError('Unable to acknowledge the emergency patient right now.', [], 500);
    }

    jsonSuccess('Emergency patient acknowledged. No ticket payment is required.', [
        'visit_id' => (int) $visitId,
        'emergency_encounter_id' => $encounterId,
    ], 201);
}

function handleEmergencyNurseAssessmentSave(string $visitId): void
{
    if (!ctype_digit($visitId) || (int) $visitId < 1) {
        jsonError('A valid visit ID is required.', [], 422);
    }
    $database = getDatabaseConnection();
    $user = requireEmergencyNurseRead($database);
    requireEmergencyNurseWrite($user);
    requireCsrfRequest();
    $input = readJsonRequest();
    $textLimits = [
        'symptoms' => 10000,
        'blood_pressure' => 40,
        'pulse' => 40,
        'respiratory_rate' => 40,
        'temperature' => 40,
        'oxygen_saturation' => 40,
        'consciousness_level' => 100,
        'pain_score' => 40,
        'allergies' => 5000,
        'visible_injuries' => 5000,
        'triage_priority' => 40,
        'nursing_notes' => 10000,
        'immediate_care' => 10000,
    ];
    $values = [];
    $errors = [];
    foreach ($textLimits as $field => $maxLength) {
        $value = $input[$field] ?? null;
        if ($value !== null && !is_string($value) && !is_numeric($value)) {
            $errors[$field] = 'Enter text or a numeric value.';
            continue;
        }
        $value = $value === null ? '' : trim((string) $value);
        if (strlen($value) > $maxLength) {
            $errors[$field] = 'This field is too long.';
            continue;
        }
        if (in_array($field, [
            'pulse', 'respiratory_rate', 'temperature', 'oxygen_saturation',
        ], true) && $value !== '' && !preg_match('/^[0-9]+(?:\.[0-9]+)?$/', $value)) {
            $errors[$field] = 'Enter a numeric observation.';
            continue;
        }
        $values[$field] = $value === '' ? null : $value;
    }
    $status = $input['status'] ?? 'draft';
    if (!is_string($status) || !in_array($status, ['draft', 'complete'], true)) {
        $errors['status'] = 'Choose draft or complete.';
    }
    if ($errors !== []) {
        jsonError('Please correct the emergency nursing assessment.', $errors, 422);
    }

    try {
        $database->beginTransaction();
        $encounter = emergencyNurseEncounter($database, (int) $visitId, true);
        $encounterId = (int) $encounter['emergency_encounter_id'];
        emergencyNurseAssignment($database, $encounterId, (int) $user['user_id']);
        $existing = $database->prepare(
            'SELECT emergency_nursing_assessment_id, nurse_user_id
             FROM emergency_nursing_assessments
             WHERE emergency_encounter_id = :encounter_id
             LIMIT 1
             FOR UPDATE'
        );
        $existing->execute(['encounter_id' => $encounterId]);
        $assessment = $existing->fetch();
        if ($assessment !== false
            && (int) $assessment['nurse_user_id'] !== (int) $user['user_id']) {
            $database->rollBack();
            jsonError('An assessment is already being maintained by another nurse.', [], 409);
        }

        $parameters = $values + [
            'status' => $status,
            'completed_at' => $status === 'complete' ? date('Y-m-d H:i:s') : null,
            'encounter_id' => $encounterId,
            'nurse_id' => (int) $user['user_id'],
        ];
        if ($assessment === false) {
            $insert = $database->prepare(
                'INSERT INTO emergency_nursing_assessments
                    (emergency_encounter_id, nurse_user_id, symptoms,
                     blood_pressure, pulse, respiratory_rate, temperature,
                     oxygen_saturation, consciousness_level, pain_score,
                     allergies, visible_injuries, triage_priority,
                     nursing_notes, immediate_care, status, completed_at)
                 VALUES
                    (:encounter_id, :nurse_id, :symptoms, :blood_pressure,
                     :pulse, :respiratory_rate, :temperature,
                     :oxygen_saturation, :consciousness_level, :pain_score,
                     :allergies, :visible_injuries, :triage_priority,
                     :nursing_notes, :immediate_care, :status, :completed_at)'
            );
            $insert->execute($parameters);
        } else {
            $update = $database->prepare(
                'UPDATE emergency_nursing_assessments
                 SET symptoms = :symptoms,
                     blood_pressure = :blood_pressure,
                     pulse = :pulse,
                     respiratory_rate = :respiratory_rate,
                     temperature = :temperature,
                     oxygen_saturation = :oxygen_saturation,
                     consciousness_level = :consciousness_level,
                     pain_score = :pain_score,
                     allergies = :allergies,
                     visible_injuries = :visible_injuries,
                     triage_priority = :triage_priority,
                     nursing_notes = :nursing_notes,
                     immediate_care = :immediate_care,
                     status = :status,
                     completed_at = :completed_at
                 WHERE emergency_nursing_assessment_id =
                       :assessment_id
                   AND nurse_user_id = :nurse_id'
            );
            $parameters['assessment_id'] = (int) $assessment['emergency_nursing_assessment_id'];
            $updateParameters = $parameters;
            unset($updateParameters['encounter_id']);
            $update->execute($updateParameters);
        }
        $triage = $values['triage_priority'];
        $triageUpdate = $database->prepare(
            'UPDATE emergency_encounters
             SET triage_level = :triage_level
             WHERE emergency_encounter_id = :encounter_id'
        );
        $triageUpdate->execute([
            'triage_level' => $triage,
            'encounter_id' => $encounterId,
        ]);
        recordEmergencyAudit(
            $database,
            (int) $user['user_id'],
            $status === 'complete'
                ? 'emergency.nurse.assessment.completed'
                : 'emergency.nurse.assessment.saved',
            (int) $encounter['patient_id'],
            (int) $visitId
        );
        $database->commit();
    } catch (Throwable $exception) {
        if ($database->inTransaction()) {
            $database->rollBack();
        }
        error_log('Emergency nurse assessment save failed: ' . $exception->getMessage());
        jsonError('Unable to save the emergency nursing assessment right now.', [], 500);
    }

    jsonSuccess('Emergency nursing assessment saved.', [
        'visit_id' => (int) $visitId,
        'status' => $status,
    ]);
}

function handleEmergencyNurseObservationCreate(string $visitId): void
{
    if (!ctype_digit($visitId) || (int) $visitId < 1) {
        jsonError('A valid visit ID is required.', [], 422);
    }
    $database = getDatabaseConnection();
    $user = requireEmergencyNurseRead($database);
    requireEmergencyNurseWrite($user);
    requireCsrfRequest();
    $input = readJsonRequest();
    $fields = [
        'blood_pressure' => 40,
        'pulse' => 40,
        'respiratory_rate' => 40,
        'temperature' => 40,
        'oxygen_saturation' => 40,
        'consciousness_level' => 100,
        'pain_score' => 40,
        'nursing_notes' => 10000,
        'immediate_care' => 10000,
    ];
    $values = [];
    $errors = [];
    $hasObservation = false;
    foreach ($fields as $field => $maxLength) {
        $value = $input[$field] ?? null;
        if ($value !== null && !is_string($value) && !is_numeric($value)) {
            $errors[$field] = 'Enter text or a numeric value.';
            continue;
        }
        $value = $value === null ? '' : trim((string) $value);
        if (strlen($value) > $maxLength) {
            $errors[$field] = 'This field is too long.';
            continue;
        }
        if (in_array($field, [
            'pulse', 'respiratory_rate', 'temperature', 'oxygen_saturation',
        ], true) && $value !== '' && !preg_match('/^[0-9]+(?:\.[0-9]+)?$/', $value)) {
            $errors[$field] = 'Enter a numeric observation.';
            continue;
        }
        $values[$field] = $value === '' ? null : $value;
        $hasObservation = $hasObservation || $value !== '';
    }
    if (!$hasObservation) {
        $errors['observation'] = 'Enter at least one observation or nursing note.';
    }
    if ($errors !== []) {
        jsonError('Please correct the emergency observation.', $errors, 422);
    }

    try {
        $database->beginTransaction();
        $encounter = emergencyNurseEncounter($database, (int) $visitId, true);
        $encounterId = (int) $encounter['emergency_encounter_id'];
        emergencyNurseAssignment($database, $encounterId, (int) $user['user_id']);
        $insert = $database->prepare(
            'INSERT INTO emergency_nursing_observations
                (emergency_encounter_id, nurse_user_id, blood_pressure, pulse,
                 respiratory_rate, temperature, oxygen_saturation,
                 consciousness_level, pain_score, nursing_notes, immediate_care)
             VALUES
                (:encounter_id, :nurse_id, :blood_pressure, :pulse,
                 :respiratory_rate, :temperature, :oxygen_saturation,
                 :consciousness_level, :pain_score, :nursing_notes, :immediate_care)'
        );
        $insert->execute($values + [
            'encounter_id' => $encounterId,
            'nurse_id' => (int) $user['user_id'],
        ]);
        recordEmergencyAudit(
            $database,
            (int) $user['user_id'],
            'emergency.nurse.observation.recorded',
            (int) $encounter['patient_id'],
            (int) $visitId
        );
        $database->commit();
    } catch (Throwable $exception) {
        if ($database->inTransaction()) {
            $database->rollBack();
        }
        error_log('Emergency nurse observation save failed: ' . $exception->getMessage());
        jsonError('Unable to save the emergency observation right now.', [], 500);
    }

    jsonSuccess('Emergency observation recorded.', [
        'visit_id' => (int) $visitId,
        'observation_id' => (int) $database->lastInsertId(),
    ], 201);
}

function handleEmergencyNurseHandover(string $visitId): void
{
    if (!ctype_digit($visitId) || (int) $visitId < 1) {
        jsonError('A valid visit ID is required.', [], 422);
    }
    $database = getDatabaseConnection();
    $user = requireEmergencyNurseRead($database);
    requireEmergencyNurseWrite($user);
    requireCsrfRequest();

    try {
        $database->beginTransaction();
        $encounter = emergencyNurseEncounter($database, (int) $visitId, true);
        emergencyNurseAssignment(
            $database,
            (int) $encounter['emergency_encounter_id'],
            (int) $user['user_id']
        );
        if ($encounter['handover_status'] !== 'not_requested') {
            $database->rollBack();
            jsonError('A doctor handover has already been requested or accepted.', [], 409);
        }
        $update = $database->prepare(
            'UPDATE emergency_encounters
             SET handover_status = \'pending\',
                 handover_requested_at = CURRENT_TIMESTAMP
             WHERE emergency_encounter_id = :encounter_id
               AND handover_status = \'not_requested\''
        );
        $update->execute([
            'encounter_id' => (int) $encounter['emergency_encounter_id'],
        ]);
        if ($update->rowCount() !== 1) {
            $database->rollBack();
            jsonError('The handover status changed before this request.', [], 409);
        }
        recordEmergencyAudit(
            $database,
            (int) $user['user_id'],
            'emergency.nurse.doctor_handover.requested',
            (int) $encounter['patient_id'],
            (int) $visitId
        );
        $database->commit();
    } catch (Throwable $exception) {
        if ($database->inTransaction()) {
            $database->rollBack();
        }
        error_log('Emergency doctor handover request failed: ' . $exception->getMessage());
        jsonError('Unable to request doctor handover right now.', [], 500);
    }

    jsonSuccess('Emergency Doctor handover requested.', [
        'visit_id' => (int) $visitId,
        'handover_status' => 'pending',
    ], 201);
}

function handleEmergencyHandoverAccept(string $visitId): void
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
            'SELECT emergency_encounter_id, handover_status, emergency_doctor_id
             FROM emergency_encounters
             WHERE visit_id = :visit_id
             LIMIT 1
             FOR UPDATE'
        );
        $encounterStatement->execute(['visit_id' => (int) $visitId]);
        $encounter = $encounterStatement->fetch();
        if ($encounter === false || $encounter['handover_status'] !== 'pending'
            || $encounter['emergency_doctor_id'] !== null) {
            $database->rollBack();
            jsonError('There is no pending nurse handover to accept.', [], 409);
        }
        $update = $database->prepare(
            'UPDATE emergency_encounters
             SET emergency_doctor_id = :doctor_id,
                 handover_status = \'accepted\',
                 handover_accepted_at = CURRENT_TIMESTAMP
             WHERE emergency_encounter_id = :encounter_id
               AND handover_status = \'pending\'
               AND emergency_doctor_id IS NULL'
        );
        $update->execute([
            'doctor_id' => (int) $user['user_id'],
            'encounter_id' => (int) $encounter['emergency_encounter_id'],
        ]);
        if ($update->rowCount() !== 1) {
            $database->rollBack();
            jsonError('The handover was accepted by another doctor.', [], 409);
        }
        recordEmergencyAudit(
            $database,
            (int) $user['user_id'],
            'emergency.doctor_handover.accepted',
            (int) $visit['patient_id'],
            (int) $visitId
        );
        $database->commit();
    } catch (Throwable $exception) {
        if ($database->inTransaction()) {
            $database->rollBack();
        }
        error_log('Emergency doctor handover acceptance failed: ' . $exception->getMessage());
        jsonError('Unable to accept doctor handover right now.', [], 500);
    }

    jsonSuccess('Emergency nurse handover accepted.', [
        'visit_id' => (int) $visitId,
        'doctor_user_id' => (int) $user['user_id'],
        'handover_status' => 'accepted',
    ]);
}

function emergencyDoctorEncounterForUpdate(
    PDO $database,
    int $visitId,
    int $doctorId
): array {
    $visit = emergencyVisitForUpdate($database, $visitId);
    $statement = $database->prepare(
        'SELECT emergency_encounter_id, emergency_doctor_id, ended_at
         FROM emergency_encounters
         WHERE visit_id = :visit_id
         LIMIT 1
         FOR UPDATE'
    );
    $statement->execute(['visit_id' => $visitId]);
    $encounter = $statement->fetch();
    if ($encounter === false) {
        jsonError('The nurse must acknowledge the patient before doctor care.', [], 409);
    }
    if ($encounter['ended_at'] !== null) {
        jsonError('This emergency encounter is already complete.', [], 409);
    }
    if ($encounter['emergency_doctor_id'] === null
        || (int) $encounter['emergency_doctor_id'] !== $doctorId) {
        jsonError('Accept the pending handover before recording emergency care.', [], 409);
    }

    return [
        'visit' => $visit,
        'encounter_id' => (int) $encounter['emergency_encounter_id'],
    ];
}

function emergencyDoctorText(
    array $input,
    string $field,
    int $maximum,
    bool $required,
    array &$errors
): ?string {
    $value = $input[$field] ?? null;
    if ($value !== null && !is_string($value)) {
        $errors[$field] = 'Enter text.';
        return null;
    }
    $text = is_string($value) ? trim($value) : '';
    if ($required && $text === '') {
        $errors[$field] = 'This field is required.';
    } elseif (strlen($text) > $maximum) {
        $errors[$field] = 'This field is too long.';
    }

    return $text === '' ? null : $text;
}

function handleEmergencyDoctorAssessmentSave(string $visitId): void
{
    if (!ctype_digit($visitId) || (int) $visitId < 1) {
        jsonError('A valid visit ID is required.', [], 422);
    }
    $database = getDatabaseConnection();
    $user = requireEmergencyAccess($database, true);
    requireCsrfRequest();
    $input = readJsonRequest();
    $errors = [];
    $complaint = emergencyDoctorText(
        $input,
        'presenting_complaint',
        5000,
        false,
        $errors
    );
    $observations = emergencyDoctorText(
        $input,
        'clinical_observations',
        10000,
        false,
        $errors
    );
    $findings = emergencyDoctorText(
        $input,
        'examination_findings',
        10000,
        false,
        $errors
    );
    $notes = emergencyDoctorText($input, 'notes', 10000, false, $errors);
    if ($observations === null && $findings === null && $notes === null) {
        $errors['assessment'] = 'Enter clinical observations, examination findings, or notes.';
    }
    if ($errors !== []) {
        jsonError('Please correct the emergency assessment.', $errors, 422);
    }

    try {
        $database->beginTransaction();
        $case = emergencyDoctorEncounterForUpdate(
            $database,
            (int) $visitId,
            (int) $user['user_id']
        );
        $existing = $database->prepare(
            "SELECT consultation_id
             FROM consultations
             WHERE visit_id = :visit_id
               AND clinician_user_id = :doctor_id
               AND consultation_type = 'emergency'
               AND next_step = 'undecided'
             ORDER BY consultation_id DESC
             LIMIT 1
             FOR UPDATE"
        );
        $existing->execute([
            'visit_id' => (int) $visitId,
            'doctor_id' => (int) $user['user_id'],
        ]);
        $consultationId = $existing->fetchColumn();
        $isNewAssessment = $consultationId === false;
        if ($isNewAssessment) {
            $insert = $database->prepare(
                "INSERT INTO consultations
                    (visit_id, clinician_user_id, consultation_type,
                     presenting_complaint, clinical_observations,
                     examination_findings, notes, next_step)
                 VALUES
                    (:visit_id, :doctor_id, 'emergency', :complaint,
                     :observations, :findings, :notes, 'undecided')"
            );
            $insert->execute([
                'visit_id' => (int) $visitId,
                'doctor_id' => (int) $user['user_id'],
                'complaint' => $complaint,
                'observations' => $observations,
                'findings' => $findings,
                'notes' => $notes,
            ]);
            $consultationId = (int) $database->lastInsertId();
        } else {
            $update = $database->prepare(
                'UPDATE consultations
                 SET presenting_complaint = :complaint,
                     clinical_observations = :observations,
                     examination_findings = :findings,
                     notes = :notes
                 WHERE consultation_id = :consultation_id'
            );
            $update->execute([
                'complaint' => $complaint,
                'observations' => $observations,
                'findings' => $findings,
                'notes' => $notes,
                'consultation_id' => (int) $consultationId,
            ]);
            $consultationId = (int) $consultationId;
        }
        recordEmergencyAudit(
            $database,
            (int) $user['user_id'],
            'emergency.doctor.assessment.saved',
            (int) $case['visit']['patient_id'],
            (int) $visitId
        );
        $database->commit();
    } catch (Throwable $exception) {
        if ($database->inTransaction()) {
            $database->rollBack();
        }
        error_log('Emergency doctor assessment save failed: ' . $exception->getMessage());
        jsonError('Unable to save the emergency assessment right now.', [], 500);
    }

    jsonSuccess('Emergency clinical assessment saved.', [
        'visit_id' => (int) $visitId,
        'consultation_id' => $consultationId,
    ], $isNewAssessment ? 201 : 200);
}

function handleEmergencyDoctorDiagnosisSave(string $visitId): void
{
    if (!ctype_digit($visitId) || (int) $visitId < 1) {
        jsonError('A valid visit ID is required.', [], 422);
    }
    $database = getDatabaseConnection();
    $user = requireEmergencyAccess($database, true);
    requireCsrfRequest();
    $input = readJsonRequest();
    $consultationId = $input['consultation_id'] ?? null;
    if ((!is_int($consultationId)
            && !(is_string($consultationId) && ctype_digit($consultationId)))
        || (int) $consultationId < 1) {
        jsonError('A valid consultation is required.', [
            'consultation_id' => 'Open or save the clinical assessment first.',
        ], 422);
    }
    $diagnosisMade = $input['diagnosis_made'] ?? null;
    if (!is_bool($diagnosisMade)) {
        jsonError('Indicate whether a diagnosis was made.', [
            'diagnosis_made' => 'Choose yes or no.',
        ], 422);
    }
    $stage = $input['diagnosis_stage'] ?? 'final';
    if (!is_string($stage) || !in_array($stage, ['preliminary', 'final'], true)) {
        jsonError('Choose a supported diagnosis stage.', [
            'diagnosis_stage' => 'Choose preliminary or final.',
        ], 422);
    }

    $errors = [];
    $description = emergencyDoctorText(
        $input,
        'diagnosis_description',
        5000,
        $diagnosisMade,
        $errors
    );
    $findings = emergencyDoctorText(
        $input,
        'clinical_findings',
        10000,
        false,
        $errors
    );
    $treatmentPlan = emergencyDoctorText(
        $input,
        'treatment_plan',
        10000,
        $diagnosisMade,
        $errors
    );
    $notes = emergencyDoctorText($input, 'notes', 10000, false, $errors);
    if ($errors !== []) {
        jsonError('Please correct the emergency diagnosis.', $errors, 422);
    }

    try {
        $database->beginTransaction();
        $case = emergencyDoctorEncounterForUpdate(
            $database,
            (int) $visitId,
            (int) $user['user_id']
        );
        $consultation = $database->prepare(
            "SELECT consultation_id, next_step
             FROM consultations
             WHERE consultation_id = :consultation_id
               AND visit_id = :visit_id
               AND clinician_user_id = :doctor_id
               AND consultation_type = 'emergency'
               AND next_step = 'undecided'
             LIMIT 1
             FOR UPDATE"
        );
        $consultation->execute([
            'consultation_id' => (int) $consultationId,
            'visit_id' => (int) $visitId,
            'doctor_id' => (int) $user['user_id'],
        ]);
        if ($consultation->fetch() === false) {
            $database->rollBack();
            jsonError('Save an unfinished assessment before recording its diagnosis.', [], 409);
        }

        $diagnosis = $database->prepare(
            'INSERT INTO diagnoses
                (visit_id, consultation_id, diagnosed_by_user_id,
                 diagnosis_made, diagnosis_stage, diagnosis_description,
                 clinical_findings, treatment_plan, notes)
             VALUES
                (:visit_id, :consultation_id, :doctor_id, :diagnosis_made,
                 :diagnosis_stage, :description, :findings, :treatment_plan, :notes)'
        );
        $diagnosis->execute([
            'visit_id' => (int) $visitId,
            'consultation_id' => (int) $consultationId,
            'doctor_id' => (int) $user['user_id'],
            'diagnosis_made' => $diagnosisMade ? 1 : 0,
            'diagnosis_stage' => $stage,
            'description' => $description,
            'findings' => $findings,
            'treatment_plan' => $treatmentPlan,
            'notes' => $notes,
        ]);
        $diagnosisId = (int) $database->lastInsertId();
        $update = $database->prepare(
            'UPDATE consultations
             SET diagnosis_made = :diagnosis_made,
                 diagnosis_text = :diagnosis_text,
                 treatment_plan = :treatment_plan,
                 next_step = :next_step
             WHERE consultation_id = :consultation_id'
        );
        $update->execute([
            'diagnosis_made' => $diagnosisMade ? 1 : 0,
            'diagnosis_text' => $description,
            'treatment_plan' => $treatmentPlan,
            'next_step' => $diagnosisMade && $stage === 'final'
                ? 'completed'
                : 'undecided',
            'consultation_id' => (int) $consultationId,
        ]);
        recordEmergencyAudit(
            $database,
            (int) $user['user_id'],
            'emergency.doctor.diagnosis.recorded',
            (int) $case['visit']['patient_id'],
            (int) $visitId
        );
        $database->commit();
    } catch (Throwable $exception) {
        if ($database->inTransaction()) {
            $database->rollBack();
        }
        error_log('Emergency doctor diagnosis save failed: ' . $exception->getMessage());
        jsonError('Unable to save the emergency diagnosis right now.', [], 500);
    }

    jsonSuccess('Emergency diagnosis recorded.', [
        'visit_id' => (int) $visitId,
        'consultation_id' => (int) $consultationId,
        'diagnosis_id' => $diagnosisId,
        'diagnosis_stage' => $stage,
    ], 201);
}

function handleEmergencyDoctorTreatmentSave(string $visitId): void
{
    if (!ctype_digit($visitId) || (int) $visitId < 1) {
        jsonError('A valid visit ID is required.', [], 422);
    }
    $database = getDatabaseConnection();
    $user = requireEmergencyAccess($database, true);
    requireCsrfRequest();
    $input = readJsonRequest();
    $errors = [];
    $treatment = emergencyDoctorText($input, 'treatment', 10000, false, $errors);
    $instructions = emergencyDoctorText($input, 'instructions', 10000, false, $errors);
    if ($treatment === null && $instructions === null) {
        $errors['treatment'] = 'Enter treatment or instructions to save.';
    }
    if ($errors !== []) {
        jsonError('Please correct the treatment update.', $errors, 422);
    }

    try {
        $database->beginTransaction();
        $case = emergencyDoctorEncounterForUpdate(
            $database,
            (int) $visitId,
            (int) $user['user_id']
        );
        $insert = $database->prepare(
            'INSERT INTO emergency_doctor_notes
                (emergency_encounter_id, doctor_user_id, note_type, note_text)
             VALUES (:encounter_id, :doctor_id, :note_type, :note_text)'
        );
        foreach ([
            'treatment' => $treatment,
            'instruction' => $instructions,
        ] as $type => $text) {
            if ($text !== null) {
                $insert->execute([
                    'encounter_id' => $case['encounter_id'],
                    'doctor_id' => (int) $user['user_id'],
                    'note_type' => $type,
                    'note_text' => $text,
                ]);
            }
        }
        recordEmergencyAudit(
            $database,
            (int) $user['user_id'],
            'emergency.doctor.treatment.recorded',
            (int) $case['visit']['patient_id'],
            (int) $visitId
        );
        $database->commit();
    } catch (Throwable $exception) {
        if ($database->inTransaction()) {
            $database->rollBack();
        }
        error_log('Emergency doctor treatment save failed: ' . $exception->getMessage());
        jsonError('Unable to save the treatment update right now.', [], 500);
    }

    jsonSuccess('Emergency treatment update saved.', [
        'visit_id' => (int) $visitId,
        'instructions_recorded' => $instructions !== null,
    ], 201);
}

function handleEmergencyDoctorOrdersCreate(string $visitId): void
{
    if (!ctype_digit($visitId) || (int) $visitId < 1) {
        jsonError('A valid visit ID is required.', [], 422);
    }
    $database = getDatabaseConnection();
    $user = requireEmergencyAccess($database, true);
    requireCsrfRequest();
    $input = readJsonRequest();
    $testTypes = $input['test_types'] ?? null;
    $departments = doctorTestDepartments();
    $errors = [];
    if (!is_array($testTypes) || count($testTypes) < 1
        || count($testTypes) > count($departments)) {
        $errors['test_types'] = 'Choose one or more supported in-house investigations.';
    } else {
        foreach ($testTypes as $type) {
            if (!is_string($type) || !isset($departments[$type])) {
                $errors['test_types'] = 'Choose only supported in-house investigations.';
                break;
            }
        }
        if (count(array_unique($testTypes)) !== count($testTypes)) {
            $errors['test_types'] = 'Remove duplicate investigation types.';
        }
    }
    if ($errors !== []) {
        jsonError('Please correct the investigation orders.', $errors, 422);
    }

    try {
        $database->beginTransaction();
        $case = emergencyDoctorEncounterForUpdate(
            $database,
            (int) $visitId,
            (int) $user['user_id']
        );
        $insert = $database->prepare(
            'INSERT INTO test_orders
                (visit_id, test_type, department, ordered_by_user_id)
             VALUES (:visit_id, :test_type, :department, :doctor_id)'
        );
        $orderIds = [];
        foreach ($testTypes as $type) {
            $insert->execute([
                'visit_id' => (int) $visitId,
                'test_type' => $type,
                'department' => $departments[$type],
                'doctor_id' => (int) $user['user_id'],
            ]);
            $orderIds[] = (int) $database->lastInsertId();
        }
        recordEmergencyAudit(
            $database,
            (int) $user['user_id'],
            'emergency.doctor.investigations.ordered',
            (int) $case['visit']['patient_id'],
            (int) $visitId
        );
        $database->commit();
    } catch (Throwable $exception) {
        if ($database->inTransaction()) {
            $database->rollBack();
        }
        error_log('Emergency investigation order failed: ' . $exception->getMessage());
        jsonError('Unable to save the investigation orders right now.', [], 500);
    }

    jsonSuccess('Emergency investigations sent to the existing testing queues.', [
        'visit_id' => (int) $visitId,
        'test_order_ids' => $orderIds,
    ], 201);
}

function handleEmergencyDoctorReferralCreate(string $visitId): void
{
    if (!ctype_digit($visitId) || (int) $visitId < 1) {
        jsonError('A valid visit ID is required.', [], 422);
    }
    $database = getDatabaseConnection();
    $user = requireEmergencyAccess($database, true);
    requireCsrfRequest();
    $input = readJsonRequest();
    $type = $input['referral_type'] ?? null;
    if (!is_string($type) || !in_array($type, ['specialist', 'outside'], true)) {
        jsonError('Choose a supported referral destination.', [
            'referral_type' => 'Choose specialist or outside facility.',
        ], 422);
    }
    $errors = [];
    $reason = emergencyDoctorText(
        $input,
        'reason',
        5000,
        $type === 'specialist',
        $errors
    );
    $summary = emergencyDoctorText(
        $input,
        'clinical_summary',
        10000,
        $type === 'outside',
        $errors
    );
    $provider = emergencyDoctorText($input, 'provider_name', 150, false, $errors);
    $consultationId = $input['consultation_id'] ?? null;
    if ((!is_int($consultationId)
            && !(is_string($consultationId) && ctype_digit($consultationId)))
        || (int) $consultationId < 1) {
        $errors['consultation_id'] = 'Save a clinical assessment before referring.';
    }
    $specialistId = $input['specialist_user_id'] ?? null;
    if ($type === 'specialist'
        && ((!is_int($specialistId)
                && !(is_string($specialistId) && ctype_digit($specialistId)))
            || (int) $specialistId < 1)) {
        $errors['specialist_user_id'] = 'Choose an active resident or locum specialist.';
    }
    $testsPayable = $input['outside_tests_payable'] ?? true;
    if (!is_bool($testsPayable)) {
        $errors['outside_tests_payable'] = 'Choose whether outside tests are payable.';
    }
    if ($errors !== []) {
        jsonError('Please correct the referral details.', $errors, 422);
    }

    try {
        $database->beginTransaction();
        $case = emergencyDoctorEncounterForUpdate(
            $database,
            (int) $visitId,
            (int) $user['user_id']
        );
        $consultation = $database->prepare(
            "SELECT consultation_id
             FROM consultations
             WHERE consultation_id = :consultation_id
               AND visit_id = :visit_id
               AND consultation_type = 'emergency'
             LIMIT 1
             FOR UPDATE"
        );
        $consultation->execute([
            'consultation_id' => (int) $consultationId,
            'visit_id' => (int) $visitId,
        ]);
        if ($consultation->fetchColumn() === false) {
            $database->rollBack();
            jsonError('The selected assessment does not belong to this emergency visit.', [], 409);
        }

        if ($type === 'specialist') {
            $specialist = $database->prepare(
                "SELECT user.user_id
                 FROM users user
                 INNER JOIN roles role ON role.role_id = user.role_id
                 WHERE user.user_id = :user_id AND user.is_active = 1
                   AND role.role_name IN ('resident_specialist', 'locum_specialist')
                 LIMIT 1"
            );
            $specialist->execute(['user_id' => (int) $specialistId]);
            if ($specialist->fetchColumn() === false) {
                $database->rollBack();
                jsonError('The selected specialist is unavailable.', [
                    'specialist_user_id' => 'Choose an active resident or locum specialist.',
                ], 422);
            }
            $insert = $database->prepare(
                'INSERT INTO specialist_referrals
                    (visit_id, source_consultation_id, specialist_user_id,
                     reason, clinical_summary)
                 VALUES
                    (:visit_id, :consultation_id, :specialist_id,
                     :reason, :summary)'
            );
            $insert->execute([
                'visit_id' => (int) $visitId,
                'consultation_id' => (int) $consultationId,
                'specialist_id' => (int) $specialistId,
                'reason' => $reason,
                'summary' => $summary,
            ]);
            $referralId = (int) $database->lastInsertId();
        } else {
            $insert = $database->prepare(
                'INSERT INTO outside_referrals
                    (visit_id, source_consultation_id, provider_name,
                     clinical_summary, outside_tests_payable)
                 VALUES
                    (:visit_id, :consultation_id, :provider_name,
                     :summary, :outside_tests_payable)'
            );
            $insert->execute([
                'visit_id' => (int) $visitId,
                'consultation_id' => (int) $consultationId,
                'provider_name' => $provider,
                'summary' => $summary ?? $reason ?? 'Outside referral',
                'outside_tests_payable' => $testsPayable ? 1 : 0,
            ]);
            $referralId = (int) $database->lastInsertId();
        }
        $nextStep = $type === 'specialist' ? 'specialist_referral' : 'outside_referral';
        $updateConsultation = $database->prepare(
            'UPDATE consultations
             SET next_step = :next_step
             WHERE consultation_id = :consultation_id'
        );
        $updateConsultation->execute([
            'next_step' => $nextStep,
            'consultation_id' => (int) $consultationId,
        ]);
        recordEmergencyAudit(
            $database,
            (int) $user['user_id'],
            'emergency.doctor.referral.created',
            (int) $case['visit']['patient_id'],
            (int) $visitId
        );
        $database->commit();
    } catch (Throwable $exception) {
        if ($database->inTransaction()) {
            $database->rollBack();
        }
        error_log('Emergency referral creation failed: ' . $exception->getMessage());
        jsonError('Unable to save the emergency referral right now.', [], 500);
    }

    jsonSuccess('Emergency referral recorded.', [
        'visit_id' => (int) $visitId,
        'referral_id' => $referralId,
        'referral_type' => $type,
    ], 201);
}

function handleEmergencyDoctorOutcomeSave(string $visitId): void
{
    if (!ctype_digit($visitId) || (int) $visitId < 1) {
        jsonError('A valid visit ID is required.', [], 422);
    }
    $database = getDatabaseConnection();
    $user = requireEmergencyAccess($database, true);
    requireCsrfRequest();
    $input = readJsonRequest();
    $outcome = $input['outcome'] ?? null;
    if (!is_string($outcome)
        || !in_array($outcome, ['observation', 'admission', 'transfer', 'discharge'], true)) {
        jsonError('Choose a supported emergency outcome.', [
            'outcome' => 'Choose observation, admission, transfer, or discharge.',
        ], 422);
    }
    $errors = [];
    $notes = emergencyDoctorText($input, 'outcome_notes', 10000, false, $errors);
    if ($errors !== []) {
        jsonError('Please correct the outcome notes.', $errors, 422);
    }

    try {
        $database->beginTransaction();
        $case = emergencyDoctorEncounterForUpdate(
            $database,
            (int) $visitId,
            (int) $user['user_id']
        );
        $outcomeUpdate = $database->prepare(
            'UPDATE emergency_encounters
             SET outcome = :outcome,
                 outcome_notes = :notes,
                 outcome_recorded_at = CURRENT_TIMESTAMP,
                 outcome_recorded_by_user_id = :doctor_id
             WHERE emergency_encounter_id = :encounter_id'
        );
        $outcomeUpdate->execute([
            'outcome' => $outcome,
            'notes' => $notes,
            'doctor_id' => (int) $user['user_id'],
            'encounter_id' => $case['encounter_id'],
        ]);
        if ($outcome === 'discharge') {
            $prescription = $database->prepare(
                "SELECT prescription_id FROM prescriptions
                 WHERE visit_id = :visit_id AND status = 'active'
                 LIMIT 1"
            );
            $prescription->execute(['visit_id' => (int) $visitId]);
            $nextStatus = $prescription->fetchColumn() !== false
                ? 'awaiting_pharmacy'
                : 'awaiting_payment';
            $finish = $database->prepare(
                'UPDATE emergency_encounters
                 SET ended_at = CURRENT_TIMESTAMP
                 WHERE emergency_encounter_id = :encounter_id
                   AND ended_at IS NULL'
            );
            $finish->execute(['encounter_id' => $case['encounter_id']]);
            $visitUpdate = $database->prepare(
                'UPDATE visits SET status = :status
                 WHERE visit_id = :visit_id
                   AND status = \'emergency_in_progress\''
            );
            $visitUpdate->execute([
                'status' => $nextStatus,
                'visit_id' => (int) $visitId,
            ]);
            if ($finish->rowCount() !== 1 || $visitUpdate->rowCount() !== 1) {
                $database->rollBack();
                jsonError('The emergency visit changed before discharge was saved.', [], 409);
            }
        }
        recordEmergencyAudit(
            $database,
            (int) $user['user_id'],
            'emergency.doctor.outcome.recorded',
            (int) $case['visit']['patient_id'],
            (int) $visitId
        );
        $database->commit();
    } catch (Throwable $exception) {
        if ($database->inTransaction()) {
            $database->rollBack();
        }
        error_log('Emergency doctor outcome save failed: ' . $exception->getMessage());
        jsonError('Unable to save the emergency outcome right now.', [], 500);
    }

    jsonSuccess('Emergency outcome recorded.', [
        'visit_id' => (int) $visitId,
        'outcome' => $outcome,
        'visit_status' => $outcome === 'discharge' ? $nextStatus : 'emergency_in_progress',
        'encounter_closed' => $outcome === 'discharge',
    ]);
}
