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

function requireNurseForAssessment(PDO $database): array
{
    $user = requireNursingAccess($database);
    if ($user['role_name'] !== 'nurse') {
        jsonError('Only a nurse can start or manage a nursing assessment.', [], 403);
    }

    return $user;
}

function requireNursingAdministrator(PDO $database): array
{
    $user = requireNursingAccess($database);
    if ($user['role_name'] !== 'system_administrator') {
        jsonError('System administrator access is required.', [], 403);
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
    AND v.medication_collection_only = 0
    AND EXISTS (
        SELECT 1
        FROM bills ticket_bill
        WHERE ticket_bill.visit_id = v.visit_id
          AND COALESCE((
            SELECT SUM(ticket_item.quantity * ticket_item.unit_amount)
            FROM bill_items ticket_item
            WHERE ticket_item.bill_id = ticket_bill.bill_id
              AND ticket_item.charge_type = 'ticket'
              AND ticket_item.is_voided = 0
          ), 0.00) > 0
          AND COALESCE((
            SELECT SUM(ticket_payment.amount)
            FROM payments ticket_payment
            WHERE ticket_payment.bill_id = ticket_bill.bill_id
              AND ticket_payment.status = 'approved'
          ), 0.00) >= (
            SELECT SUM(ticket_item.quantity * ticket_item.unit_amount)
            FROM bill_items ticket_item
            WHERE ticket_item.bill_id = ticket_bill.bill_id
              AND ticket_item.charge_type = 'ticket'
              AND ticket_item.is_voided = 0
          )
    )";
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
            (SELECT COUNT(*) FROM visits v
             INNER JOIN nurse_assessments na ON na.visit_id = v.visit_id
             WHERE $eligible AND na.decision IS NULL) AS currently_assessing,
            (SELECT COUNT(*) FROM visits
             WHERE is_emergency = 1 AND status = 'emergency_in_progress')
                AS urgent_patients,
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
            'currently_assessing' => (int) $stats['currently_assessing'],
            'urgent_patients' => (int) $stats['urgent_patients'],
            'completed_assessments' => (int) $stats['assessed_today'],
            'awaiting_tests' => (int) $stats['awaiting_tests'],
            'awaiting_doctor' => (int) $stats['awaiting_doctor'],
            'minor_treated_today' => (int) $minorCountStatement->fetchColumn(),
        ],
        'minor_treatment_medicines' => $medicines,
    ]);
}

function handleNursingQueue(string $search, string $filter): void
{
    $database = getDatabaseConnection();
    $user = requireNursingAccess($database);
    $search = trim($search);
    if (strlen($search) > 100) {
        jsonError('Search text must be 100 characters or fewer.', [
            'search' => 'Shorten the search text.',
        ], 422);
    }

    if (!in_array($filter, ['all', 'unassigned', 'assigned', 'mine'], true)) {
        jsonError('Choose a supported nursing queue filter.', [
            'filter' => 'Choose all, unassigned, assigned, or mine.',
        ], 422);
    }

    $eligible = nursingQueueCriteriaSql();
    $assignedNurse = 'COALESCE(v.nursing_assigned_to_user_id, na.nurse_user_id)';
    $filterSql = '';
    if ($filter === 'unassigned') {
        $filterSql = " AND $assignedNurse IS NULL";
    } elseif ($filter === 'assigned') {
        $filterSql = " AND $assignedNurse IS NOT NULL";
    } elseif ($filter === 'mine') {
        $filterSql = " AND $assignedNurse = :assigned_user_id";
    }
    $sql = "SELECT v.visit_id, v.arrived_at, v.is_emergency,
                   v.medication_collection_only, v.visit_source, v.status,
                   v.reason_for_visit, v.reception_notes,
                   p.patient_id, p.patient_number, p.first_name, p.last_name,
                   p.date_of_birth, p.gender,
                   TIMESTAMPDIFF(YEAR, p.date_of_birth, CURDATE()) AS age,
                   a.scheduled_at AS appointment_at,
                   a.reason AS appointment_reason,
                   na.assessment_id, na.decision,
                   $assignedNurse AS assigned_nurse_user_id,
                   COALESCE(v.nursing_started_at, na.assessed_at) AS nursing_started_at,
                   assignment_nurse.display_name AS assigned_nurse_name,
                   EXISTS (
                              SELECT 1
                              FROM bills ticket_bill
                              WHERE ticket_bill.visit_id = v.visit_id
                                 AND COALESCE((
                                     SELECT SUM(ticket_item.quantity * ticket_item.unit_amount)
                                     FROM bill_items ticket_item
                                     WHERE ticket_item.bill_id = ticket_bill.bill_id
                                        AND ticket_item.charge_type = 'ticket'
                                        AND ticket_item.is_voided = 0
                                 ), 0.00) > 0
                                 AND COALESCE((
                                     SELECT SUM(ticket_payment.amount)
                                     FROM payments ticket_payment
                                     WHERE ticket_payment.bill_id = ticket_bill.bill_id
                                        AND ticket_payment.status = 'approved'
                                 ), 0.00) >= (
                                     SELECT SUM(ticket_item.quantity * ticket_item.unit_amount)
                                     FROM bill_items ticket_item
                                     WHERE ticket_item.bill_id = ticket_bill.bill_id
                                        AND ticket_item.charge_type = 'ticket'
                                        AND ticket_item.is_voided = 0
                                 )
                   ) AS ticket_payment_confirmed,
                   CASE
                     WHEN na.assessment_id IS NULL THEN 'assessment'
                     ELSE 'minor_treatment'
                   END AS next_action
            FROM visits v
            INNER JOIN patients p ON p.patient_id = v.patient_id
            LEFT JOIN appointments a ON a.visit_id = v.visit_id
            LEFT JOIN nurse_assessments na
                ON na.assessment_id = (
                    SELECT MAX(latest.assessment_id)
                    FROM nurse_assessments latest
                    WHERE latest.visit_id = v.visit_id
                )
            LEFT JOIN users assignment_nurse
                ON assignment_nurse.user_id = $assignedNurse
            WHERE $eligible
              AND (
                    na.assessment_id IS NULL
                    OR na.decision IS NULL
                    OR (
                        na.decision = 'minor_illness'
                        AND NOT EXISTS (
                            SELECT 1 FROM minor_treatment_items mti
                            WHERE mti.assessment_id = na.assessment_id
                        )
                    )
              )
              $filterSql
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
    $parameters = [
        'patient_number' => $search,
        'patient_number_search' => $pattern,
        'first_name_search' => $pattern,
        'last_name_search' => $pattern,
        'full_name_search' => $pattern,
    ];
    if ($filter === 'mine') {
        $parameters['assigned_user_id'] = (int) $user['user_id'];
    }
    $statement->execute($parameters);
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
            'age' => $row['age'] === null ? null : (int) $row['age'],
            'arrived_at' => $row['arrived_at'],
            'is_emergency' => (bool) $row['is_emergency'],
            'visit_source' => $row['visit_source'],
            'status' => $row['status'],
            'reason_for_visit' => $row['reason_for_visit'],
            'reception_notes' => $row['reception_notes'],
            'appointment_at' => $row['appointment_at'],
            'appointment_reason' => $row['appointment_reason'],
            'assessment_id' => $row['assessment_id'] === null
                ? null
                : (int) $row['assessment_id'],
            'decision' => $row['decision'],
            'next_action' => $row['next_action'],
            'assigned_nurse_user_id' => $row['assigned_nurse_user_id'] === null
                ? null
                : (int) $row['assigned_nurse_user_id'],
            'assigned_nurse_name' => $row['assigned_nurse_name'],
            'nursing_started_at' => $row['nursing_started_at'],
            'ticket_payment_confirmed' => (bool) $row['ticket_payment_confirmed'],
        ];
    }, $statement->fetchAll());

    jsonSuccess('Nursing queue loaded.', ['visits' => $queue]);
}

function handleNursingCompletedGet(string $search): void
{
    $database = getDatabaseConnection();
    $user = requireNursingAccess($database);
    $search = trim($search);
    if (strlen($search) > 100) {
        jsonError('Search text must be 100 characters or fewer.', [
            'search' => 'Shorten the search text.',
        ], 422);
    }

    $pattern = '%' . $search . '%';
    $statement = $database->prepare(
        'SELECT assessment.assessment_id, assessment.visit_id,
                assessment.nurse_user_id, assessment.complaint,
                assessment.symptoms, assessment.symptom_duration,
                assessment.blood_pressure, assessment.pulse,
                assessment.temperature, assessment.respiratory_rate,
                assessment.oxygen_saturation, assessment.weight,
                assessment.height, assessment.notes,
                assessment.decision, assessment.minor_treatment_notes,
                assessment.assessed_at, visit.patient_id,
                visit.arrived_at, visit.visit_source, visit.status AS visit_status,
                patient.patient_number, patient.first_name, patient.last_name,
                nurse.display_name AS nurse_name,
                (SELECT GROUP_CONCAT(
                    CONCAT(medicine.medicine_name, \' x \', treatment.quantity)
                    ORDER BY medicine.medicine_name SEPARATOR \', \')
                 FROM minor_treatment_items treatment
                 INNER JOIN medicines medicine ON medicine.medicine_id = treatment.medicine_id
                 WHERE treatment.assessment_id = assessment.assessment_id
                ) AS treatment_summary
         FROM nurse_assessments assessment
         INNER JOIN visits visit ON visit.visit_id = assessment.visit_id
         INNER JOIN patients patient ON patient.patient_id = visit.patient_id
         INNER JOIN users nurse ON nurse.user_id = assessment.nurse_user_id
         WHERE assessment.decision IS NOT NULL
                     AND (
                                assessment.decision <> \'minor_illness\'
                                OR EXISTS (
                                        SELECT 1
                                        FROM minor_treatment_items completed_treatment
                                        WHERE completed_treatment.assessment_id = assessment.assessment_id
                                )
                     )
           AND (
                :search_empty = \'\'
                OR patient.patient_number LIKE :patient_number
                OR patient.first_name LIKE :first_name
                OR patient.last_name LIKE :last_name
                OR CONCAT_WS(\' \', patient.first_name, patient.last_name) LIKE :full_name
                OR CAST(visit.visit_id AS CHAR) LIKE :visit_id
           )
         ORDER BY assessment.assessed_at DESC, assessment.assessment_id DESC
         LIMIT 200'
    );
    $statement->execute([
        'search_empty' => $search,
        'patient_number' => $pattern,
        'first_name' => $pattern,
        'last_name' => $pattern,
        'full_name' => $pattern,
        'visit_id' => $pattern,
    ]);
    $assessments = $statement->fetchAll();
    foreach ($assessments as $assessment) {
        recordNursingAudit(
            $database,
            (int) $user['user_id'],
            'nursing.completed_assessments.viewed',
            (int) $assessment['patient_id'],
            (int) $assessment['visit_id']
        );
    }

    jsonSuccess('Completed nursing assessments loaded.', [
        'assessments' => array_map(static function (array $assessment): array {
            return [
                'assessment_id' => (int) $assessment['assessment_id'],
                'visit_id' => (int) $assessment['visit_id'],
                'patient_number' => $assessment['patient_number'],
                'patient_name' => trim(
                    (string) $assessment['first_name'] . ' '
                    . (string) $assessment['last_name']
                ),
                'arrived_at' => $assessment['arrived_at'],
                'visit_source' => $assessment['visit_source'],
                'visit_status' => $assessment['visit_status'],
                'nurse_user_id' => (int) $assessment['nurse_user_id'],
                'nurse_name' => $assessment['nurse_name'],
                'assessed_at' => $assessment['assessed_at'],
                'complaint' => $assessment['complaint'],
                'symptoms' => $assessment['symptoms'],
                'symptom_duration' => $assessment['symptom_duration'],
                'blood_pressure' => $assessment['blood_pressure'],
                'pulse' => $assessment['pulse'] === null
                    ? null
                    : (int) $assessment['pulse'],
                'temperature' => $assessment['temperature'],
                'respiratory_rate' => $assessment['respiratory_rate'] === null
                    ? null
                    : (int) $assessment['respiratory_rate'],
                'oxygen_saturation' => $assessment['oxygen_saturation'],
                'weight' => $assessment['weight'],
                'height' => $assessment['height'],
                'notes' => $assessment['notes'],
                'decision' => $assessment['decision'],
                'minor_treatment_notes' => $assessment['minor_treatment_notes'],
                'treatment_summary' => $assessment['treatment_summary'],
            ];
        }, $assessments),
    ]);
}

function handleNursingReferralsGet(string $search): void
{
    $database = getDatabaseConnection();
    $user = requireNursingAccess($database);
    $search = trim($search);
    if (strlen($search) > 100) {
        jsonError('Search text must be 100 characters or fewer.', [
            'search' => 'Shorten the search text.',
        ], 422);
    }

    $pattern = '%' . $search . '%';
    $statement = $database->prepare(
        'SELECT assessment.assessment_id, assessment.visit_id,
                assessment.nurse_user_id, assessment.complaint,
                assessment.symptoms, assessment.notes, assessment.assessed_at,
                                COALESCE((
                                        SELECT referral_audit.created_at
                                        FROM audit_log referral_audit
                                        WHERE referral_audit.visit_id = visit.visit_id
                                            AND referral_audit.action = \'nursing.decision.general_doctor\'
                                        ORDER BY referral_audit.created_at DESC
                                        LIMIT 1
                                ), assessment.assessed_at) AS referral_at,
                visit.patient_id, visit.arrived_at, visit.visit_source,
                visit.status AS visit_status, patient.patient_number,
                patient.first_name, patient.last_name,
                nurse.display_name AS nurse_name
         FROM nurse_assessments assessment
         INNER JOIN visits visit ON visit.visit_id = assessment.visit_id
         INNER JOIN patients patient ON patient.patient_id = visit.patient_id
         INNER JOIN users nurse ON nurse.user_id = assessment.nurse_user_id
         WHERE assessment.decision = \'general_doctor\'
           AND (
                :search_empty = \'\'
                OR patient.patient_number LIKE :patient_number
                OR patient.first_name LIKE :first_name
                OR patient.last_name LIKE :last_name
                OR CONCAT_WS(\' \', patient.first_name, patient.last_name) LIKE :full_name
                OR CAST(visit.visit_id AS CHAR) LIKE :visit_id
           )
         ORDER BY assessment.assessed_at DESC, assessment.assessment_id DESC
         LIMIT 200'
    );
    $statement->execute([
        'search_empty' => $search,
        'patient_number' => $pattern,
        'first_name' => $pattern,
        'last_name' => $pattern,
        'full_name' => $pattern,
        'visit_id' => $pattern,
    ]);
    $referrals = $statement->fetchAll();
    foreach ($referrals as $referral) {
        recordNursingAudit(
            $database,
            (int) $user['user_id'],
            'nursing.doctor_referrals.viewed',
            (int) $referral['patient_id'],
            (int) $referral['visit_id']
        );
    }

    jsonSuccess('Nursing doctor referrals loaded.', [
        'referrals' => array_map(static function (array $referral): array {
            return [
                'assessment_id' => (int) $referral['assessment_id'],
                'visit_id' => (int) $referral['visit_id'],
                'patient_number' => $referral['patient_number'],
                'patient_name' => trim(
                    (string) $referral['first_name'] . ' '
                    . (string) $referral['last_name']
                ),
                'arrived_at' => $referral['arrived_at'],
                'visit_source' => $referral['visit_source'],
                'visit_status' => $referral['visit_status'],
                'nurse_user_id' => (int) $referral['nurse_user_id'],
                'nurse_name' => $referral['nurse_name'],
                'referral_at' => $referral['referral_at'],
                'complaint' => $referral['complaint'],
                'symptoms' => $referral['symptoms'],
                'assessment_findings' => $referral['notes'],
            ];
        }, $referrals),
    ]);
}

function handleNursingNursesGet(): void
{
    $database = getDatabaseConnection();
    requireNursingAdministrator($database);
    $statement = $database->prepare(
        'SELECT users.user_id, users.display_name
         FROM users
         INNER JOIN roles ON roles.role_id = users.role_id
         WHERE roles.role_name = \'nurse\' AND users.is_active = 1
         ORDER BY users.display_name, users.user_id'
    );
    $statement->execute();
    $nurses = array_map(static function (array $row): array {
        return [
            'user_id' => (int) $row['user_id'],
            'display_name' => $row['display_name'],
        ];
    }, $statement->fetchAll());

    jsonSuccess('Active nurses loaded.', ['nurses' => $nurses]);
}

function handleNursingVisitGet(string $visitId): void
{
    if (!ctype_digit($visitId) || (int) $visitId < 1) {
        jsonError('A valid visit ID is required.', [], 400);
    }

    $database = getDatabaseConnection();
    $user = requireNursingAccess($database);
    $eligible = nursingQueueCriteriaSql();
    $statement = $database->prepare(
        "SELECT v.visit_id, v.patient_id, v.arrived_at, v.is_emergency,
                v.medication_collection_only, v.visit_source, v.source_notes,
                v.status, v.reason_for_visit, v.reception_notes,
                v.nursing_assigned_to_user_id, v.nursing_started_at,
                EXISTS (
                    SELECT 1
                    FROM bills ticket_bill
                    INNER JOIN bill_items ticket_item
                        ON ticket_item.bill_id = ticket_bill.bill_id
                       AND ticket_item.charge_type = 'ticket'
                    INNER JOIN payments ticket_payment
                        ON ticket_payment.bill_id = ticket_bill.bill_id
                       AND ticket_payment.status = 'approved'
                    WHERE ticket_bill.visit_id = v.visit_id
                ) AS ticket_payment_confirmed,
                a.scheduled_at AS appointment_at,
                a.reason AS appointment_reason,
                p.patient_number, p.first_name, p.last_name,
                p.date_of_birth, p.gender, p.phone, p.address,
                TIMESTAMPDIFF(YEAR, p.date_of_birth, CURDATE()) AS age,
                na.assessment_id, na.nurse_user_id, na.complaint, na.symptoms,
                na.symptom_duration, na.blood_pressure, na.pulse, na.temperature,
                na.respiratory_rate, na.oxygen_saturation, na.weight, na.height,
                na.notes, na.decision, na.minor_treatment_notes, na.assessed_at,
                assigned_nurse.display_name AS assigned_nurse_name
         FROM visits v
         INNER JOIN patients p ON p.patient_id = v.patient_id
         LEFT JOIN appointments a ON a.visit_id = v.visit_id
         LEFT JOIN nurse_assessments na
            ON na.assessment_id = (
                SELECT MAX(latest.assessment_id)
                FROM nurse_assessments latest
                WHERE latest.visit_id = v.visit_id
            )
         LEFT JOIN users assigned_nurse
            ON assigned_nurse.user_id = COALESCE(
                v.nursing_assigned_to_user_id,
                na.nurse_user_id
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
                na.assessment_id, na.nurse_user_id, na.complaint, na.symptoms,
                na.symptom_duration, na.blood_pressure, na.pulse, na.temperature,
                na.respiratory_rate, na.oxygen_saturation, na.weight, na.height,
                na.decision, na.notes, na.assessed_at,
                assessment_nurse.display_name AS assessed_by
         FROM visits v
         LEFT JOIN nurse_assessments na
            ON na.assessment_id = (
                SELECT MAX(latest.assessment_id)
                FROM nurse_assessments latest
                WHERE latest.visit_id = v.visit_id
            )
         LEFT JOIN users assessment_nurse
            ON assessment_nurse.user_id = na.nurse_user_id
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
                'symptom_duration' => $item['symptom_duration'],
                'blood_pressure' => $item['blood_pressure'],
                'pulse' => $item['pulse'] === null ? null : (int) $item['pulse'],
                'temperature' => $item['temperature'],
                'respiratory_rate' => $item['respiratory_rate'] === null
                    ? null
                    : (int) $item['respiratory_rate'],
                'oxygen_saturation' => $item['oxygen_saturation'],
                'weight' => $item['weight'],
                'height' => $item['height'],
                'decision' => $item['decision'],
                'notes' => $item['notes'],
                'assessed_at' => $item['assessed_at'],
                'assessed_by' => $item['assessed_by'],
            ],
        ];
    }, $historyStatement->fetchAll());

    $doctorNotesStatement = $database->prepare(
        'SELECT visit.visit_id, visit.arrived_at, consultation.consultation_type,
                consultation.presenting_complaint,
                COALESCE(diagnosis.clinical_findings,
                    consultation.clinical_observations) AS clinical_observations,
                consultation.examination_findings,
                COALESCE(diagnosis.notes, consultation.notes) AS notes,
                COALESCE(diagnosis.diagnosis_description,
                    consultation.diagnosis_text) AS diagnosis_text,
                COALESCE(diagnosis.treatment_plan,
                    consultation.treatment_plan) AS treatment_plan,
                consultation.consulted_at
         FROM consultations consultation
         INNER JOIN visits visit ON visit.visit_id = consultation.visit_id
         LEFT JOIN diagnoses diagnosis
            ON diagnosis.consultation_id = consultation.consultation_id
         WHERE visit.patient_id = :patient_id
           AND visit.visit_id <> :current_visit_id
         ORDER BY consultation.consulted_at DESC
         LIMIT 10'
    );
    $doctorNotesStatement->execute([
        'patient_id' => (int) $row['patient_id'],
        'current_visit_id' => (int) $visitId,
    ]);

    $testResultsStatement = $database->prepare(
        'SELECT visit.visit_id, visit.arrived_at, test_order.test_type,
                test_order.department, test_order.status AS order_status,
                LEFT(result.result_text, 4000) AS result_text, result.result_notes,
                result.status AS result_status, result.recorded_at
         FROM test_results result
         INNER JOIN test_orders test_order
            ON test_order.test_order_id = result.test_order_id
         INNER JOIN visits visit ON visit.visit_id = test_order.visit_id
         WHERE visit.patient_id = :patient_id
           AND visit.visit_id <> :current_visit_id
         ORDER BY result.recorded_at DESC
         LIMIT 10'
    );
    $testResultsStatement->execute([
        'patient_id' => (int) $row['patient_id'],
        'current_visit_id' => (int) $visitId,
    ]);

    recordNursingAudit(
        $database,
        (int) $user['user_id'],
        'nursing.patient_record.viewed',
        (int) $row['patient_id'],
        (int) $visitId
    );

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
            'age' => $row['age'] === null ? null : (int) $row['age'],
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
            'reason_for_visit' => $row['reason_for_visit'],
            'reception_notes' => $row['reception_notes'],
            'appointment_at' => $row['appointment_at'],
            'appointment_reason' => $row['appointment_reason'],
            'status' => $row['status'],
            'assigned_nurse_user_id' => $row['nursing_assigned_to_user_id'] === null
                ? ($row['nurse_user_id'] === null
                    ? null
                    : (int) $row['nurse_user_id'])
                : (int) $row['nursing_assigned_to_user_id'],
            'assigned_nurse_name' => $row['assigned_nurse_name'],
            'nursing_started_at' => $row['nursing_started_at'] ?? $row['assessed_at'],
            'ticket_payment_confirmed' => (bool) $row['ticket_payment_confirmed'],
        ],
        'assessment' => $assessment,
        'history' => $history,
        'doctor_notes' => $doctorNotesStatement->fetchAll(),
        'test_results' => $testResultsStatement->fetchAll(),
        'allergies_supported' => false,
        'allergies' => [],
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
                status, nursing_assigned_to_user_id, nursing_started_at
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

        $ticketPaymentStatement = $database->prepare(
                'SELECT 1
                 FROM bills ticket_bill
                 WHERE ticket_bill.visit_id = :visit_id
                     AND COALESCE((
                                SELECT SUM(ticket_item.quantity * ticket_item.unit_amount)
                                FROM bill_items ticket_item
                                WHERE ticket_item.bill_id = ticket_bill.bill_id
                                    AND ticket_item.charge_type = \'ticket\'
                                    AND ticket_item.is_voided = 0
                     ), 0.00) > 0
                     AND COALESCE((
                                SELECT SUM(ticket_payment.amount)
                                FROM payments ticket_payment
                                WHERE ticket_payment.bill_id = ticket_bill.bill_id
                                    AND ticket_payment.status = \'approved\'
                     ), 0.00) >= (
                                SELECT SUM(ticket_item.quantity * ticket_item.unit_amount)
                                FROM bill_items ticket_item
                                WHERE ticket_item.bill_id = ticket_bill.bill_id
                                    AND ticket_item.charge_type = \'ticket\'
                                    AND ticket_item.is_voided = 0
                     )
                 LIMIT 1'
        );
        $ticketPaymentStatement->execute(['visit_id' => $visitId]);
        if ($ticketPaymentStatement->fetchColumn() === false) {
                jsonError('An approved ticket payment is required before nursing.', [], 409);
        }

    return $visit;
}

function nursingClaimVisitForUser(PDO $database, array &$visit, array $user): void
{
    $assignedUserId = $visit['nursing_assigned_to_user_id'] === null
        ? null
        : (int) $visit['nursing_assigned_to_user_id'];
    $startedAt = $visit['nursing_started_at'];
    $legacyAssessment = null;

    if ($assignedUserId === null) {
        $assessmentStatement = $database->prepare(
            'SELECT nurse_user_id, assessed_at
             FROM nurse_assessments
             WHERE visit_id = :visit_id
             ORDER BY assessment_id DESC
             LIMIT 1
             FOR UPDATE'
        );
        $assessmentStatement->execute(['visit_id' => (int) $visit['visit_id']]);
        $legacyAssessment = $assessmentStatement->fetch();
        if ($legacyAssessment === false) {
            $legacyAssessment = null;
        }
        if ($legacyAssessment !== null) {
            $assignedUserId = (int) $legacyAssessment['nurse_user_id'];
            $startedAt = $legacyAssessment['assessed_at'];
        }
    }

    if ($assignedUserId !== null && $assignedUserId !== (int) $user['user_id']) {
        jsonError('Another nurse has already started this assessment.', [], 409);
    }

    if ($visit['nursing_assigned_to_user_id'] === null) {
        $assignmentUpdate = $database->prepare(
            'UPDATE visits
             SET nursing_assigned_to_user_id = :nurse_user_id,
                 nursing_started_at = COALESCE(:started_at, CURRENT_TIMESTAMP)
             WHERE visit_id = :visit_id
               AND status = \'awaiting_nurse\'
               AND nursing_assigned_to_user_id IS NULL'
        );
        $assignmentUpdate->execute([
            'nurse_user_id' => $assignedUserId ?? (int) $user['user_id'],
            'started_at' => $startedAt,
            'visit_id' => (int) $visit['visit_id'],
        ]);
        if ($assignmentUpdate->rowCount() !== 1) {
            jsonError('Another nurse has already started this assessment.', [], 409);
        }
        $visit['nursing_assigned_to_user_id'] = $assignedUserId ?? (int) $user['user_id'];
        $startedAtStatement = $database->prepare(
            'SELECT nursing_started_at
             FROM visits
             WHERE visit_id = :visit_id'
        );
        $startedAtStatement->execute(['visit_id' => (int) $visit['visit_id']]);
        $visit['nursing_started_at'] = $startedAtStatement->fetchColumn();
        recordNursingAudit(
            $database,
            (int) $user['user_id'],
            $legacyAssessment === null
                ? 'nursing.assignment.started'
                : 'nursing.assignment.recovered',
            (int) $visit['patient_id'],
            (int) $visit['visit_id']
        );
    } elseif ($startedAt === null) {
        $assignmentUpdate = $database->prepare(
            'UPDATE visits
             SET nursing_started_at = CURRENT_TIMESTAMP
             WHERE visit_id = :visit_id
               AND nursing_assigned_to_user_id = :nurse_user_id
               AND status = \'awaiting_nurse\''
        );
        $assignmentUpdate->execute([
            'visit_id' => (int) $visit['visit_id'],
            'nurse_user_id' => (int) $user['user_id'],
        ]);
        $startedAtStatement = $database->prepare(
            'SELECT nursing_started_at
             FROM visits
             WHERE visit_id = :visit_id'
        );
        $startedAtStatement->execute(['visit_id' => (int) $visit['visit_id']]);
        $visit['nursing_started_at'] = $startedAtStatement->fetchColumn();
    }
}

function handleNursingVisitStart(string $visitId): void
{
    if (!ctype_digit($visitId) || (int) $visitId < 1) {
        jsonError('A valid visit ID is required.', [], 400);
    }

    $database = getDatabaseConnection();
    $user = requireNurseForAssessment($database);
    requireCsrfRequest();

    $visit = ['nursing_started_at' => null];
    try {
        $database->beginTransaction();
        $visit = nursingEligibleVisitForUpdate($database, (int) $visitId);
        nursingClaimVisitForUser($database, $visit, $user);
        $database->commit();
    } catch (Throwable $exception) {
        if ($database->inTransaction()) {
            $database->rollBack();
        }
        error_log('Nursing assessment start failed: ' . $exception->getMessage());
        jsonError('Unable to start the nursing assessment right now.', [], 500);
    }

    jsonSuccess('Visit assigned to you; assessment started.', [
        'visit_id' => (int) $visitId,
        'nurse_user_id' => (int) $user['user_id'],
        'nursing_started_at' => $visit['nursing_started_at'],
    ]);
}

function handleNursingVisitReassign(string $visitId): void
{
    if (!ctype_digit($visitId) || (int) $visitId < 1) {
        jsonError('A valid visit ID is required.', [], 400);
    }

    $database = getDatabaseConnection();
    $user = requireNursingAdministrator($database);
    requireCsrfRequest();
    $input = readJsonRequest();
    $nurseUserId = $input['nurse_user_id'] ?? null;
    if ((!is_int($nurseUserId)
            && !(is_string($nurseUserId) && ctype_digit($nurseUserId)))
        || (int) $nurseUserId < 1) {
        jsonError('Choose an active nurse for reassignment.', [
            'nurse_user_id' => 'Enter a valid nurse user ID.',
        ], 422);
    }
    $nurseUserId = (int) $nurseUserId;

    $previousNurseId = null;
    try {
        $database->beginTransaction();
        $visit = nursingEligibleVisitForUpdate($database, (int) $visitId);
        $assessmentStatement = $database->prepare(
            'SELECT assessment_id
             FROM nurse_assessments
             WHERE visit_id = :visit_id
             LIMIT 1
             FOR UPDATE'
        );
        $assessmentStatement->execute(['visit_id' => (int) $visitId]);
        if ($assessmentStatement->fetchColumn() !== false) {
            $database->rollBack();
            jsonError('A saved assessment cannot be reassigned.', [], 409);
        }

        $nurseStatement = $database->prepare(
            'SELECT users.user_id
             FROM users
             INNER JOIN roles ON roles.role_id = users.role_id
             WHERE users.user_id = :user_id
               AND roles.role_name = \'nurse\'
               AND users.is_active = 1
             LIMIT 1
             FOR UPDATE'
        );
        $nurseStatement->execute(['user_id' => $nurseUserId]);
        if ($nurseStatement->fetchColumn() === false) {
            $database->rollBack();
            jsonError('The selected user is not an active nurse.', [
                'nurse_user_id' => 'Choose an active nurse account.',
            ], 422);
        }

        $previousNurseId = $visit['nursing_assigned_to_user_id'] === null
            ? null
            : (int) $visit['nursing_assigned_to_user_id'];
        if ($previousNurseId === $nurseUserId) {
            $database->rollBack();
            jsonError('This visit is already assigned to that nurse.', [], 409);
        }

        $updateStatement = $database->prepare(
            'UPDATE visits
             SET nursing_assigned_to_user_id = :nurse_user_id,
                 nursing_started_at = CURRENT_TIMESTAMP
             WHERE visit_id = :visit_id AND status = \'awaiting_nurse\''
        );
        $updateStatement->execute([
            'nurse_user_id' => $nurseUserId,
            'visit_id' => (int) $visitId,
        ]);
        if ($updateStatement->rowCount() !== 1) {
            $database->rollBack();
            jsonError('This visit is no longer available for reassignment.', [], 409);
        }

        recordNursingAudit(
            $database,
            (int) $user['user_id'],
            'nursing.assignment.reassigned.from-'
                . ($previousNurseId === null ? 'none' : (string) $previousNurseId)
                . '.to-' . $nurseUserId,
            (int) $visit['patient_id'],
            (int) $visitId
        );
        $database->commit();
    } catch (Throwable $exception) {
        if ($database->inTransaction()) {
            $database->rollBack();
        }
        error_log('Nursing assignment reassignment failed: '
            . $exception->getMessage());
        jsonError('Unable to reassign the nursing visit right now.', [], 500);
    }

    jsonSuccess('Nursing visit reassigned.', [
        'visit_id' => (int) $visitId,
        'previous_nurse_user_id' => $previousNurseId,
        'nurse_user_id' => $nurseUserId,
    ]);
}

function handleNursingAssessmentSave(string $visitId): void
{
    if (!ctype_digit($visitId) || (int) $visitId < 1) {
        jsonError('A valid visit ID is required.', [], 400);
    }

    $database = getDatabaseConnection();
    $user = requireNurseForAssessment($database);
    requireCsrfRequest();
    $input = readJsonRequest();
    $complaint = $input['complaint'] ?? '';
    $symptoms = $input['symptoms'] ?? null;
    $duration = $input['symptom_duration'] ?? null;
    $notes = $input['nursing_notes'] ?? null;
    $errors = [];

    if ($complaint !== null && !is_string($complaint)) {
        $errors['complaint'] = 'Enter text or leave this field blank.';
    } elseif (is_string($complaint) && strlen(trim($complaint)) > 5000) {
        $errors['complaint'] = 'The main complaint must be 5000 characters or fewer.';
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
    $assessmentId = 0;
    try {
        $database->beginTransaction();
        $visit = nursingEligibleVisitForUpdate($database, (int) $visitId);
        nursingClaimVisitForUser($database, $visit, $user);
        $existingStatement = $database->prepare(
            'SELECT assessment_id, decision FROM nurse_assessments
             WHERE visit_id = :visit_id
             ORDER BY assessment_id DESC
             LIMIT 1
             FOR UPDATE'
        );
        $existingStatement->execute(['visit_id' => (int) $visitId]);
        $existingAssessment = $existingStatement->fetch();
        if ($existingAssessment !== false && $existingAssessment['decision'] !== null) {
            $database->rollBack();
            jsonError(
                'This assessment is already complete and can no longer be edited.',
                [],
                409
            );
        }

        $draftValues = [
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
        ];
        if ($existingAssessment !== false) {
            $assessmentId = (int) $existingAssessment['assessment_id'];
            $updateStatement = $database->prepare(
                'UPDATE nurse_assessments
                 SET complaint = :complaint, symptoms = :symptoms,
                     symptom_duration = :symptom_duration,
                     blood_pressure = :blood_pressure, pulse = :pulse,
                     temperature = :temperature, respiratory_rate = :respiratory_rate,
                     oxygen_saturation = :oxygen_saturation, weight = :weight,
                     height = :height, notes = :notes,
                     assessed_at = CURRENT_TIMESTAMP
                 WHERE assessment_id = :assessment_id AND decision IS NULL'
            );
            $updateStatement->execute($draftValues + [
                'assessment_id' => $assessmentId,
            ]);
            if ($updateStatement->rowCount() > 1) {
                throw new RuntimeException('Unexpected duplicate draft update.');
            }
            $auditAction = 'nursing.assessment.draft_updated';
        } else {
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
            $insertStatement->execute($draftValues + [
                'visit_id' => (int) $visitId,
                'nurse_user_id' => (int) $user['user_id'],
            ]);
            $assessmentId = (int) $database->lastInsertId();
            $auditAction = 'nursing.assessment.draft_saved';
        }
        recordNursingAudit(
            $database,
            (int) $user['user_id'],
            $auditAction,
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

    jsonSuccess('Assessment draft saved. Resume it or complete the assessment when ready.', [
        'assessment_id' => $assessmentId,
        'visit_id' => (int) $visitId,
        'nurse_user_id' => (int) $user['user_id'],
        'is_draft' => true,
    ], 201);
}

function handleNursingDecision(string $visitId): void
{
    if (!ctype_digit($visitId) || (int) $visitId < 1) {
        jsonError('A valid visit ID is required.', [], 400);
    }

    $database = getDatabaseConnection();
    $user = requireNurseForAssessment($database);
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
        nursingClaimVisitForUser($database, $visit, $user);
        $assessmentStatement = $database->prepare(
            'SELECT assessment_id, decision, complaint
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
        if (!is_string($assessment['complaint']) || trim($assessment['complaint']) === '') {
            $database->rollBack();
            jsonError('Enter the main complaint before completing the assessment.', [
                'complaint' => 'A main complaint is required to complete the assessment.',
            ], 422);
        }
        if ($assessment['decision'] !== null) {
            $database->rollBack();
            jsonError('A nursing decision has already been submitted for this visit.', [], 409);
        }

        $updateStatement = $database->prepare(
            'UPDATE nurse_assessments
             SET decision = :decision, assessed_at = CURRENT_TIMESTAMP
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
    $user = requireNurseForAssessment($database);
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
        $directions = [];
        foreach (['dosage', 'frequency', 'duration', 'instructions'] as $field) {
            $value = $item[$field] ?? null;
            $maximum = $field === 'instructions' ? 2000 : 100;
            if ($value !== null && (!is_string($value) || strlen(trim($value)) > $maximum)) {
                $errors['items.' . $index . '.' . $field] =
                    'Enter text of ' . $maximum . ' characters or fewer.';
            }
            $directions[$field] = is_string($value) && trim($value) !== ''
                ? trim($value)
                : null;
        }
        $validatedItems[] = [
            'medicine_id' => $id,
            'quantity' => $quantity,
            'dosage' => $directions['dosage'],
            'frequency' => $directions['frequency'],
            'duration' => $directions['duration'],
            'instructions' => $directions['instructions'],
        ];
    }
    if ($errors !== []) {
        jsonError('Please correct the minor treatment items.', $errors, 422);
    }

    $prescriptionId = 0;
    try {
        $database->beginTransaction();
        $visit = nursingEligibleVisitForUpdate($database, (int) $visitId);
        nursingClaimVisitForUser($database, $visit, $user);
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

        $existingPrescription = $database->prepare(
            'SELECT prescription_id
             FROM prescriptions
             WHERE visit_id = :visit_id AND status = \'active\'
             LIMIT 1 FOR UPDATE'
        );
        $existingPrescription->execute(['visit_id' => (int) $visitId]);
        if ($existingPrescription->fetchColumn() !== false) {
            $database->rollBack();
            jsonError('An active prescription already exists for this visit.', [], 409);
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
        $prescriptionInsert = $database->prepare(
            'INSERT INTO prescriptions
                (visit_id, consultation_id, diagnosis_id, prescribed_by_user_id, status)
             VALUES
                (:visit_id, NULL, NULL, :nurse_user_id, \'active\')'
        );
        $prescriptionInsert->execute([
            'visit_id' => (int) $visitId,
            'nurse_user_id' => (int) $user['user_id'],
        ]);
        $prescriptionId = (int) $database->lastInsertId();
        $prescriptionItemInsert = $database->prepare(
            'INSERT INTO prescription_items
                (prescription_id, medicine_id, dosage, frequency, duration,
                 quantity, instructions)
             VALUES
                (:prescription_id, :medicine_id, :dosage, :frequency, :duration,
                 :quantity, :instructions)'
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
            $prescriptionItemInsert->execute([
                'prescription_id' => $prescriptionId,
                'medicine_id' => $item['medicine_id'],
                'dosage' => $item['dosage'],
                'frequency' => $item['frequency'],
                'duration' => $item['duration'],
                'quantity' => $item['quantity'],
                'instructions' => $item['instructions'],
            ]);
        }

        $notesUpdate = $database->prepare(
            'UPDATE nurse_assessments
             SET minor_treatment_notes = :notes, assessed_at = CURRENT_TIMESTAMP
             WHERE assessment_id = :assessment_id'
        );
        $notesUpdate->execute([
            'notes' => trim($notes),
            'assessment_id' => (int) $assessment['assessment_id'],
        ]);
        $visitUpdate = $database->prepare(
            'UPDATE visits
             SET status = \'awaiting_pharmacy\'
             WHERE visit_id = :visit_id AND status = \'awaiting_nurse\''
        );
        $visitUpdate->execute(['visit_id' => (int) $visitId]);
        if ($visitUpdate->rowCount() !== 1) {
            $database->rollBack();
            jsonError('This visit is no longer available for minor treatment.', [], 409);
        }
        recordNursingAudit(
            $database,
            (int) $user['user_id'],
            'nursing.minor_treatment.prescribed',
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

    jsonSuccess('Minor-illness prescription recorded and routed to Pharmacy.', [
        'visit_id' => (int) $visitId,
        'assessment_id' => (int) $assessment['assessment_id'],
        'prescription_id' => $prescriptionId,
        'status' => 'awaiting_pharmacy',
    ]);
}
