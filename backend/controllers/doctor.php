<?php

declare(strict_types=1);

function requireDoctorAccess(PDO $database): array
{
    $user = getAuthenticatedUser($database);
    if ($user === null) {
        jsonError('Authentication is required.', [], 401);
    }

    if (!in_array($user['role_name'], ['general_doctor', 'system_administrator'], true)) {
        jsonError('General doctor access is required.', [], 403);
    }

    return $user;
}

function recordDoctorAudit(
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

function doctorTestDepartments(): array
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

function doctorReadyTestVisitSql(): string
{
    return "(
        v.status = 'awaiting_doctor'
        OR (
            v.status = 'awaiting_tests'
            AND EXISTS (
                SELECT 1
                FROM test_orders ready_order
                INNER JOIN test_results ready_result
                    ON ready_result.test_order_id = ready_order.test_order_id
                   AND ready_result.status IN ('final', 'amended')
                WHERE ready_order.visit_id = v.visit_id
            )
            AND NOT EXISTS (
                SELECT 1
                FROM test_orders pending_order
                WHERE pending_order.visit_id = v.visit_id
                  AND (
                    pending_order.status IN ('ordered', 'in_progress')
                    OR (
                        pending_order.status = 'completed'
                        AND NOT EXISTS (
                            SELECT 1
                            FROM test_results missing_result
                            WHERE missing_result.test_order_id =
                                  pending_order.test_order_id
                              AND missing_result.status IN ('final', 'amended')
                        )
                    )
                  )
            )
        )
    )";
}

function handleDoctorDashboard(): void
{
    $database = getDatabaseConnection();
    requireDoctorAccess($database);
    $eligible = doctorReadyTestVisitSql();

    $statsStatement = $database->query(
        "SELECT
            (SELECT COUNT(*)
             FROM visits v
             WHERE $eligible) AS awaiting_consultation,
            (SELECT COUNT(*)
             FROM consultations c
             WHERE c.consultation_type = 'general'
               AND c.next_step <> 'undecided'
               AND c.consulted_at >= CURDATE()
               AND c.consulted_at < CURDATE() + INTERVAL 1 DAY) AS consultations_today,
                        (SELECT COUNT(*)
                         FROM consultations c
                         WHERE c.consultation_type = 'general'
                             AND c.next_step = 'undecided') AS consultations_in_progress,
                        (SELECT COUNT(*)
                         FROM consultations c
                         WHERE c.consultation_type = 'general'
                             AND c.next_step <> 'undecided') AS completed_consultations,
            (SELECT COUNT(DISTINCT v.visit_id)
             FROM visits v
             WHERE v.status = 'awaiting_tests'
               AND (
                    EXISTS (
                        SELECT 1 FROM test_orders pending_order
                        WHERE pending_order.visit_id = v.visit_id
                          AND pending_order.status IN ('ordered', 'in_progress')
                    )
                    OR NOT EXISTS (
                        SELECT 1
                        FROM test_orders any_order
                        WHERE any_order.visit_id = v.visit_id
                    )
                    OR EXISTS (
                        SELECT 1
                        FROM test_orders missing_result_order
                        WHERE missing_result_order.visit_id = v.visit_id
                          AND missing_result_order.status <> 'cancelled'
                          AND NOT EXISTS (
                              SELECT 1
                              FROM test_results missing_result
                              WHERE missing_result.test_order_id =
                                    missing_result_order.test_order_id
                                AND missing_result.status IN ('final', 'amended')
                          )
                    )
               )) AS awaiting_test_results,
            (SELECT COUNT(*) FROM visits WHERE status = 'awaiting_tests')
                AS further_investigations,
            (SELECT COUNT(*) FROM specialist_referrals
             WHERE status = 'pending') AS specialist_referrals,
                ((SELECT COUNT(*) FROM specialist_referrals
                  WHERE status = 'pending')
                 + (SELECT COUNT(*) FROM therapy_referrals
                     WHERE status IN ('referred', 'in_progress'))
                 + (SELECT COUNT(*) FROM outside_referrals
                     WHERE status IN ('issued', 'sent'))) AS pending_referrals,
            (SELECT COUNT(DISTINCT visit_id) FROM diagnoses
             WHERE diagnosis_made = 1) AS patients_with_diagnosis"
    );
    $stats = $statsStatement->fetch();

    $medicineStatement = $database->prepare(
        'SELECT medicine_id, medicine_name
         FROM medicines
         WHERE is_active = 1
         ORDER BY medicine_name'
    );
    $medicineStatement->execute();

    $specialistStatement = $database->prepare(
        "SELECT u.user_id, u.display_name, r.role_name
         FROM users u
         INNER JOIN roles r ON r.role_id = u.role_id
         WHERE u.is_active = 1
           AND r.role_name IN ('resident_specialist', 'locum_specialist')
         ORDER BY r.role_name, u.display_name"
    );
    $specialistStatement->execute();

        $activityStatement = $database->query(
                'SELECT consultation.consultation_id, consultation.visit_id,
                                consultation.consulted_at, consultation.presenting_complaint,
                                consultation.next_step, visit.status AS visit_status,
                                patient.patient_number, patient.first_name, patient.last_name,
                                (SELECT diagnosis.diagnosis_description
                                 FROM diagnoses diagnosis
                                 WHERE diagnosis.consultation_id = consultation.consultation_id
                                 ORDER BY diagnosis.diagnosis_id DESC LIMIT 1
                                ) AS diagnosis_description
                 FROM consultations consultation
                 INNER JOIN visits visit ON visit.visit_id = consultation.visit_id
                 INNER JOIN patients patient ON patient.patient_id = visit.patient_id
                 WHERE consultation.consultation_type = \'general\'
                     AND consultation.next_step <> \'undecided\'
                 ORDER BY consultation.consulted_at DESC, consultation.consultation_id DESC
                 LIMIT 10'
        );

    jsonSuccess('Doctor dashboard loaded.', [
        'stats' => [
            'awaiting_consultation' => (int) $stats['awaiting_consultation'],
            'consultations_today' => (int) $stats['consultations_today'],
            'consultations_in_progress' => (int) $stats['consultations_in_progress'],
            'completed_consultations' => (int) $stats['completed_consultations'],
            'awaiting_test_results' => (int) $stats['awaiting_test_results'],
            'further_investigations' => (int) $stats['further_investigations'],
            'specialist_referrals' => (int) $stats['specialist_referrals'],
            'pending_referrals' => (int) $stats['pending_referrals'],
            'patients_with_diagnosis' => (int) $stats['patients_with_diagnosis'],
        ],
        'medicines' => array_map(static function (array $medicine): array {
            return [
                'medicine_id' => (int) $medicine['medicine_id'],
                'medicine_name' => $medicine['medicine_name'],
            ];
        }, $medicineStatement->fetchAll()),
        'specialists' => array_map(static function (array $specialist): array {
            return [
                'user_id' => (int) $specialist['user_id'],
                'display_name' => $specialist['display_name'],
                'role_name' => $specialist['role_name'],
            ];
        }, $specialistStatement->fetchAll()),
        'recent_activity' => array_map(static function (array $activity): array {
            return [
                'consultation_id' => (int) $activity['consultation_id'],
                'visit_id' => (int) $activity['visit_id'],
                'patient_number' => $activity['patient_number'],
                'patient_name' => trim(
                    (string) $activity['first_name'] . ' '
                    . (string) $activity['last_name']
                ),
                'updated_at' => $activity['consulted_at'],
                'presenting_complaint' => $activity['presenting_complaint'],
                'diagnosis' => $activity['diagnosis_description'],
                'next_step' => $activity['next_step'],
                'visit_status' => $activity['visit_status'],
            ];
        }, $activityStatement->fetchAll()),
    ]);
}

function handleDoctorQueue(string $search): void
{
    $database = getDatabaseConnection();
    requireDoctorAccess($database);
    $search = trim($search);
    if (strlen($search) > 100) {
        jsonError('Search text must be 100 characters or fewer.', [
            'search' => 'Shorten the search text.',
        ], 422);
    }

    $eligible = doctorReadyTestVisitSql();
    $pattern = '%' . $search . '%';
    $statement = $database->prepare(
        "SELECT v.visit_id, v.arrived_at, v.visit_source, v.status,
                v.is_emergency, p.patient_id, p.patient_number,
                p.first_name, p.last_name,
                EXISTS (
                    SELECT 1 FROM nurse_assessments na
                    WHERE na.visit_id = v.visit_id
                ) AS has_assessment,
                EXISTS (
                    SELECT 1
                    FROM test_orders t
                    INNER JOIN test_results tr ON tr.test_order_id = t.test_order_id
                    WHERE t.visit_id = v.visit_id
                      AND tr.status IN ('final', 'amended')
                     ) AS has_results,
                     (SELECT CONCAT_WS(' · ', NULLIF(nursing.complaint, ''),
                                             NULLIF(nursing.symptoms, ''),
                                             NULLIF(nursing.notes, ''))
                      FROM nurse_assessments nursing
                      WHERE nursing.visit_id = v.visit_id
                      ORDER BY nursing.assessed_at DESC, nursing.assessment_id DESC
                      LIMIT 1
                     ) AS nursing_reason,
                     (SELECT GROUP_CONCAT(
                          CONCAT(test_order.test_type, ': ',
                                    COALESCE(test_result.result_text, test_result.result_notes))
                          ORDER BY test_order.ordered_at, test_order.test_order_id
                          SEPARATOR ' | '
                      )
                      FROM test_orders test_order
                      INNER JOIN test_results test_result
                          ON test_result.test_order_id = test_order.test_order_id
                      WHERE test_order.visit_id = v.visit_id
                         AND test_result.status IN ('final', 'amended')
                     ) AS final_test_summary
         FROM visits v
         INNER JOIN patients p ON p.patient_id = v.patient_id
         WHERE $eligible
           AND v.medication_collection_only = 0
           AND (
                :search_empty = ''
                OR p.patient_number LIKE :patient_number
                OR p.first_name LIKE :first_name
                OR p.last_name LIKE :last_name
                OR CONCAT_WS(' ', p.first_name, p.last_name) LIKE :full_name
           )
         ORDER BY v.is_emergency DESC, v.arrived_at ASC, v.visit_id ASC
         LIMIT 200"
    );
    $statement->execute([
        'search_empty' => $search,
        'patient_number' => $pattern,
        'first_name' => $pattern,
        'last_name' => $pattern,
        'full_name' => $pattern,
    ]);

    $visits = array_map(static function (array $visit): array {
        return [
            'visit_id' => (int) $visit['visit_id'],
            'patient_id' => (int) $visit['patient_id'],
            'patient_number' => $visit['patient_number'],
            'patient_name' => trim($visit['first_name'] . ' ' . $visit['last_name']),
            'arrived_at' => $visit['arrived_at'],
            'visit_source' => $visit['visit_source'],
            'status' => $visit['status'],
            'is_emergency' => (bool) $visit['is_emergency'],
            'has_assessment' => (bool) $visit['has_assessment'],
            'has_results' => (bool) $visit['has_results'],
            'nursing_reason' => $visit['nursing_reason'],
            'final_test_summary' => $visit['final_test_summary'],
        ];
    }, $statement->fetchAll());

    jsonSuccess('Doctor queue loaded.', ['visits' => $visits]);
}

function doctorPatientHistory(PDO $database, int $patientId, int $visitId): array
{
    $statement = $database->prepare(
        'SELECT v.visit_id, v.arrived_at, v.visit_source, v.status,
                c.consultation_id, c.notes AS consultation_notes, c.consulted_at,
                d.diagnosis_id, d.diagnosis_made, d.diagnosis_description,
                d.treatment_plan, d.diagnosed_at
         FROM visits v
         LEFT JOIN consultations c ON c.visit_id = v.visit_id
         LEFT JOIN diagnoses d ON d.consultation_id = c.consultation_id
         WHERE v.patient_id = :patient_id AND v.visit_id <> :visit_id
         ORDER BY v.arrived_at DESC, c.consulted_at DESC, d.diagnosed_at DESC
         LIMIT 50'
    );
    $statement->execute([
        'patient_id' => $patientId,
        'visit_id' => $visitId,
    ]);

    return array_map(static function (array $row): array {
        return [
            'visit_id' => (int) $row['visit_id'],
            'arrived_at' => $row['arrived_at'],
            'visit_source' => $row['visit_source'],
            'status' => $row['status'],
            'consultation' => $row['consultation_id'] === null ? null : [
                'consultation_id' => (int) $row['consultation_id'],
                'notes' => $row['consultation_notes'],
                'consulted_at' => $row['consulted_at'],
            ],
            'diagnosis' => $row['diagnosis_id'] === null ? null : [
                'diagnosis_id' => (int) $row['diagnosis_id'],
                'diagnosis_made' => (bool) $row['diagnosis_made'],
                'description' => $row['diagnosis_description'],
                'treatment_plan' => $row['treatment_plan'],
                'diagnosed_at' => $row['diagnosed_at'],
            ],
        ];
    }, $statement->fetchAll());
}

function handleDoctorVisitGet(string $visitId): void
{
    if (!ctype_digit($visitId) || (int) $visitId < 1) {
        jsonError('A valid visit ID is required.', [], 400);
    }

    $database = getDatabaseConnection();
    $user = requireDoctorAccess($database);
    $visitStatement = $database->prepare(
        "SELECT v.visit_id, v.patient_id, v.arrived_at, v.visit_source,
                v.source_notes, v.status, v.is_emergency,
                v.medication_collection_only, p.patient_number,
                p.first_name, p.last_name, p.date_of_birth, p.gender,
                p.phone, p.address
         FROM visits v
         INNER JOIN patients p ON p.patient_id = v.patient_id
                 WHERE v.visit_id = :visit_id
         LIMIT 1"
    );
    $visitStatement->execute(['visit_id' => (int) $visitId]);
    $visit = $visitStatement->fetch();
    if ($visit === false) {
        jsonError('This visit is not currently eligible for doctor consultation.', [], 404);
    }

    $assessmentStatement = $database->prepare(
        'SELECT na.assessment_id, na.complaint, na.symptoms,
                na.symptom_duration, na.temperature, na.blood_pressure,
                na.pulse, na.respiratory_rate, na.oxygen_saturation,
                na.weight, na.height, na.notes, na.assessed_at,
                u.display_name AS nurse_name
         FROM nurse_assessments na
         INNER JOIN users u ON u.user_id = na.nurse_user_id
         WHERE na.visit_id = :visit_id
         ORDER BY na.assessed_at DESC, na.assessment_id DESC
         LIMIT 1'
    );
    $assessmentStatement->execute(['visit_id' => (int) $visitId]);
    $assessment = $assessmentStatement->fetch();
    if ($assessment !== false) {
        $assessment = [
            'assessment_id' => (int) $assessment['assessment_id'],
            'complaint' => $assessment['complaint'],
            'symptoms' => $assessment['symptoms'],
            'symptom_duration' => $assessment['symptom_duration'],
            'temperature' => $assessment['temperature'],
            'blood_pressure' => $assessment['blood_pressure'],
            'pulse' => $assessment['pulse'] === null ? null : (int) $assessment['pulse'],
            'respiratory_rate' => $assessment['respiratory_rate'] === null
                ? null
                : (int) $assessment['respiratory_rate'],
            'oxygen_saturation' => $assessment['oxygen_saturation'],
            'weight' => $assessment['weight'],
            'height' => $assessment['height'],
            'notes' => $assessment['notes'],
            'nurse_name' => $assessment['nurse_name'],
            'assessed_at' => $assessment['assessed_at'],
        ];
    }

    $testStatement = $database->prepare(
        'SELECT t.test_order_id, t.test_type, t.department, t.status AS order_status,
                t.ordered_at, t.retest_of_order_id, tr.test_result_id,
                tr.result_text, tr.result_notes, tr.status AS result_status, tr.recorded_at,
                recorder.display_name AS recorded_by
         FROM test_orders t
         LEFT JOIN test_results tr ON tr.test_order_id = t.test_order_id
         LEFT JOIN users recorder ON recorder.user_id = tr.recorded_by_user_id
         WHERE t.visit_id = :visit_id
         ORDER BY t.ordered_at, t.test_order_id, tr.recorded_at'
    );
    $testStatement->execute(['visit_id' => (int) $visitId]);
    $testResults = array_map(static function (array $row): array {
        return [
            'test_order_id' => (int) $row['test_order_id'],
            'test_type' => $row['test_type'],
            'department' => $row['department'],
            'order_status' => $row['order_status'],
            'ordered_at' => $row['ordered_at'],
            'retest_of_order_id' => $row['retest_of_order_id'] === null
                ? null
                : (int) $row['retest_of_order_id'],
            'result' => $row['test_result_id'] === null ? null : [
                'test_result_id' => (int) $row['test_result_id'],
                'text' => $row['result_text'],
                'notes' => $row['result_notes'],
                'status' => $row['result_status'],
                'recorded_at' => $row['recorded_at'],
                'recorded_by' => $row['recorded_by'],
            ],
        ];
    }, $testStatement->fetchAll());

    $consultationStatement = $database->prepare(
        'SELECT c.consultation_id, c.presenting_complaint,
                c.clinical_observations, c.examination_findings, c.notes,
                c.diagnosis_made, c.diagnosis_text, c.treatment_plan,
                c.next_step, c.consulted_at, u.display_name AS clinician_name
         FROM consultations c
         INNER JOIN users u ON u.user_id = c.clinician_user_id
         WHERE c.visit_id = :visit_id
         ORDER BY c.consulted_at DESC, c.consultation_id DESC'
    );
    $consultationStatement->execute(['visit_id' => (int) $visitId]);
    $consultations = array_map(static function (array $row): array {
        return [
            'consultation_id' => (int) $row['consultation_id'],
            'presenting_complaint' => $row['presenting_complaint'],
            'clinical_observations' => $row['clinical_observations'],
            'examination_findings' => $row['examination_findings'],
            'notes' => $row['notes'],
            'diagnosis_made' => (bool) $row['diagnosis_made'],
            'diagnosis_text' => $row['diagnosis_text'],
            'treatment_plan' => $row['treatment_plan'],
            'next_step' => $row['next_step'],
            'consulted_at' => $row['consulted_at'],
            'clinician_name' => $row['clinician_name'],
        ];
    }, $consultationStatement->fetchAll());

    $diagnosisStatement = $database->prepare(
        'SELECT diagnosis_id, consultation_id, diagnosis_made,
                diagnosis_description, clinical_findings, treatment_plan,
                notes, diagnosed_at
         FROM diagnoses
         WHERE visit_id = :visit_id
         ORDER BY diagnosed_at DESC, diagnosis_id DESC'
    );
    $diagnosisStatement->execute(['visit_id' => (int) $visitId]);
    $diagnoses = array_map(static function (array $row): array {
        return [
            'diagnosis_id' => (int) $row['diagnosis_id'],
            'consultation_id' => (int) $row['consultation_id'],
            'diagnosis_made' => (bool) $row['diagnosis_made'],
            'description' => $row['diagnosis_description'],
            'clinical_findings' => $row['clinical_findings'],
            'treatment_plan' => $row['treatment_plan'],
            'notes' => $row['notes'],
            'diagnosed_at' => $row['diagnosed_at'],
        ];
    }, $diagnosisStatement->fetchAll());

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
    $prescriptionItemsStatement = $database->prepare(
        'SELECT item.prescription_item_id, item.dosage, item.frequency,
                item.duration, item.quantity, item.instructions,
                medicine.medicine_name, medicine.unit_price
         FROM prescription_items item
         INNER JOIN medicines medicine ON medicine.medicine_id = item.medicine_id
         WHERE item.prescription_id = :prescription_id
         ORDER BY item.prescription_item_id'
    );
    $prescriptions = [];
    foreach ($prescriptionStatement->fetchAll() as $prescription) {
        $prescriptionItemsStatement->execute([
            'prescription_id' => (int) $prescription['prescription_id'],
        ]);
        $prescriptions[] = [
            'prescription_id' => (int) $prescription['prescription_id'],
            'status' => $prescription['status'],
            'created_at' => $prescription['created_at'],
            'prescribed_by' => $prescription['prescribed_by'],
            'items' => array_map(static function (array $item): array {
                return [
                    'medicine_name' => $item['medicine_name'],
                    'dosage' => $item['dosage'],
                    'frequency' => $item['frequency'],
                    'duration' => $item['duration'],
                    'quantity' => (string) $item['quantity'],
                    'instructions' => $item['instructions'],
                    'unit_price' => (string) $item['unit_price'],
                ];
            }, $prescriptionItemsStatement->fetchAll()),
        ];
    }

    $medicationHistoryStatement = $database->prepare(
        'SELECT visit.visit_id, visit.arrived_at,
                prescription.prescription_id, prescription.status,
                prescription_item.dosage, prescription_item.frequency,
                prescription_item.duration, prescription_item.quantity,
                prescription_item.instructions, medicine.medicine_name,
                dispensing.dispensed_at
         FROM visits visit
         INNER JOIN prescriptions prescription ON prescription.visit_id = visit.visit_id
         INNER JOIN prescription_items prescription_item
            ON prescription_item.prescription_id = prescription.prescription_id
         INNER JOIN medicines medicine ON medicine.medicine_id = prescription_item.medicine_id
         LEFT JOIN dispensings dispensing ON dispensing.prescription_id = prescription.prescription_id
         WHERE visit.patient_id = :patient_id
         ORDER BY visit.arrived_at DESC, prescription.prescription_id DESC,
                  prescription_item.prescription_item_id'
    );
    $medicationHistoryStatement->execute(['patient_id' => (int) $visit['patient_id']]);
    $medicationHistory = array_map(static function (array $item): array {
        return [
            'visit_id' => (int) $item['visit_id'],
            'arrived_at' => $item['arrived_at'],
            'prescription_id' => (int) $item['prescription_id'],
            'status' => $item['status'],
            'medicine_name' => $item['medicine_name'],
            'dosage' => $item['dosage'],
            'frequency' => $item['frequency'],
            'duration' => $item['duration'],
            'quantity' => (string) $item['quantity'],
            'instructions' => $item['instructions'],
            'dispensed_at' => $item['dispensed_at'],
        ];
    }, $medicationHistoryStatement->fetchAll());

    $referrals = [];
    $specialistReferrals = $database->prepare(
        'SELECT referral.specialist_referral_id AS referral_id,
                referral.reason, referral.clinical_summary AS details,
                referral.status, referral.created_at,
                specialist.display_name AS destination
         FROM specialist_referrals referral
         INNER JOIN users specialist ON specialist.user_id = referral.specialist_user_id
         WHERE referral.visit_id = :visit_id'
    );
    $specialistReferrals->execute(['visit_id' => (int) $visitId]);
    foreach ($specialistReferrals->fetchAll() as $referral) {
        $referrals[] = ['type' => 'specialist'] + $referral;
    }
    $therapyReferrals = $database->prepare(
        'SELECT therapy_referral_id AS referral_id, therapy_type AS destination,
                reason, status, created_at
         FROM therapy_referrals WHERE visit_id = :visit_id'
    );
    $therapyReferrals->execute(['visit_id' => (int) $visitId]);
    foreach ($therapyReferrals->fetchAll() as $referral) {
        $referrals[] = ['type' => 'therapy', 'details' => null] + $referral;
    }
    $outsideReferrals = $database->prepare(
        'SELECT outside_referral_id AS referral_id,
                COALESCE(provider_name, \'Outside provider\') AS destination,
                clinical_summary AS details, status, created_at
         FROM outside_referrals WHERE visit_id = :visit_id'
    );
    $outsideReferrals->execute(['visit_id' => (int) $visitId]);
    foreach ($outsideReferrals->fetchAll() as $referral) {
        $referrals[] = [
            'type' => 'outside',
            'reason' => null,
        ] + $referral;
    }

    $history = doctorPatientHistory(
        $database,
        (int) $visit['patient_id'],
        (int) $visitId
    );
    recordDoctorAudit(
        $database,
        (int) $user['user_id'],
        'doctor.visit.viewed',
        (int) $visit['patient_id'],
        (int) $visitId
    );

    jsonSuccess('Doctor visit loaded.', [
        'patient' => [
            'patient_id' => (int) $visit['patient_id'],
            'patient_number' => $visit['patient_number'],
            'full_name' => trim($visit['first_name'] . ' ' . $visit['last_name']),
            'date_of_birth' => $visit['date_of_birth'],
            'gender' => $visit['gender'],
            'phone' => $visit['phone'],
            'address' => $visit['address'],
        ],
        'visit' => [
            'visit_id' => (int) $visit['visit_id'],
            'arrived_at' => $visit['arrived_at'],
            'visit_source' => $visit['visit_source'],
            'source_notes' => $visit['source_notes'],
            'status' => $visit['status'],
            'is_emergency' => (bool) $visit['is_emergency'],
            'medication_collection_only' => (bool) $visit['medication_collection_only'],
        ],
        'nursing_assessment' => $assessment,
        'test_results' => $testResults,
        'consultations' => $consultations,
        'diagnoses' => $diagnoses,
        'prescriptions' => $prescriptions,
        'medication_history' => $medicationHistory,
        'referrals' => $referrals,
        'history' => $history,
    ]);
}

function handleDoctorVisitHistory(string $visitId): void
{
    if (!ctype_digit($visitId) || (int) $visitId < 1) {
        jsonError('A valid visit ID is required.', [], 400);
    }

    $database = getDatabaseConnection();
    requireDoctorAccess($database);
    $statement = $database->prepare(
        'SELECT patient_id FROM visits WHERE visit_id = :visit_id LIMIT 1'
    );
    $statement->execute(['visit_id' => (int) $visitId]);
    $patientId = $statement->fetchColumn();
    if ($patientId === false) {
        jsonError('Visit not found.', [], 404);
    }

    jsonSuccess('Patient visit history loaded.', [
        'history' => doctorPatientHistory($database, (int) $patientId, (int) $visitId),
    ]);
}

function handleDoctorHistoryGet(string $search): void
{
    $database = getDatabaseConnection();
    $user = requireDoctorAccess($database);
    $search = trim($search);
    if (strlen($search) > 100) {
        jsonError('Search text must be 100 characters or fewer.', [
            'search' => 'Shorten the search text.',
        ], 422);
    }

    $pattern = '%' . $search . '%';
    $statement = $database->prepare(
        'SELECT consultation.consultation_id, consultation.visit_id,
                consultation.consultation_type, consultation.presenting_complaint,
                consultation.clinical_observations, consultation.examination_findings,
                consultation.notes, consultation.diagnosis_made,
                consultation.diagnosis_text, consultation.treatment_plan,
                consultation.next_step, consultation.consulted_at,
                visit.patient_id, visit.arrived_at, visit.visit_source,
                visit.status AS visit_status, patient.patient_number,
                patient.first_name, patient.last_name,
                clinician.display_name AS clinician_name,
                (SELECT diagnosis.diagnosis_description
                 FROM diagnoses diagnosis
                 WHERE diagnosis.consultation_id = consultation.consultation_id
                 ORDER BY diagnosis.diagnosis_id DESC LIMIT 1
                ) AS diagnosis_description
         FROM consultations consultation
         INNER JOIN visits visit ON visit.visit_id = consultation.visit_id
         INNER JOIN patients patient ON patient.patient_id = visit.patient_id
         INNER JOIN users clinician ON clinician.user_id = consultation.clinician_user_id
         WHERE consultation.consultation_type = \'general\'
           AND consultation.next_step <> \'undecided\'
           AND (
                :search_empty = \'\'
                OR patient.patient_number LIKE :patient_number
                OR patient.first_name LIKE :first_name
                OR patient.last_name LIKE :last_name
                OR CONCAT_WS(\' \', patient.first_name, patient.last_name) LIKE :full_name
                OR CAST(visit.visit_id AS CHAR) LIKE :visit_id
           )
         ORDER BY consultation.consulted_at DESC, consultation.consultation_id DESC
         LIMIT 250'
    );
    $statement->execute([
        'search_empty' => $search,
        'patient_number' => $pattern,
        'first_name' => $pattern,
        'last_name' => $pattern,
        'full_name' => $pattern,
        'visit_id' => $pattern,
    ]);
    $records = $statement->fetchAll();
    foreach ($records as $record) {
        recordDoctorAudit(
            $database,
            (int) $user['user_id'],
            'doctor.history.viewed',
            (int) $record['patient_id'],
            (int) $record['visit_id']
        );
    }

    jsonSuccess('Completed doctor consultations loaded.', [
        'consultations' => array_map(static function (array $record): array {
            return [
                'consultation_id' => (int) $record['consultation_id'],
                'visit_id' => (int) $record['visit_id'],
                'patient_number' => $record['patient_number'],
                'patient_name' => trim(
                    (string) $record['first_name'] . ' ' . (string) $record['last_name']
                ),
                'arrived_at' => $record['arrived_at'],
                'visit_source' => $record['visit_source'],
                'visit_status' => $record['visit_status'],
                'clinician_name' => $record['clinician_name'],
                'consulted_at' => $record['consulted_at'],
                'presenting_complaint' => $record['presenting_complaint'],
                'clinical_observations' => $record['clinical_observations'],
                'examination_findings' => $record['examination_findings'],
                'consultation_notes' => $record['notes'],
                'diagnosis_made' => (bool) $record['diagnosis_made'],
                'diagnosis' => $record['diagnosis_description'] ?? $record['diagnosis_text'],
                'treatment_plan' => $record['treatment_plan'],
                'next_step' => $record['next_step'],
            ];
        }, $records),
    ]);
}

function handleDoctorReferralsGet(string $search): void
{
    $database = getDatabaseConnection();
    $user = requireDoctorAccess($database);
    $search = trim($search);
    if (strlen($search) > 100) {
        jsonError('Search text must be 100 characters or fewer.', [
            'search' => 'Shorten the search text.',
        ], 422);
    }

    $pattern = '%' . $search . '%';
    $statement = $database->prepare(
        'SELECT * FROM (
            SELECT \'specialist\' AS referral_type,
                   referral.specialist_referral_id AS referral_id,
                   referral.visit_id, referral.created_at,
                   referral.reason, referral.clinical_summary AS details,
                   referral.status, specialist.display_name AS destination,
                   visit.patient_id, visit.status AS visit_status,
                   patient.patient_number, patient.first_name, patient.last_name
            FROM specialist_referrals referral
            INNER JOIN visits visit ON visit.visit_id = referral.visit_id
            INNER JOIN patients patient ON patient.patient_id = visit.patient_id
            INNER JOIN users specialist ON specialist.user_id = referral.specialist_user_id
            UNION ALL
            SELECT \'therapy\' AS referral_type,
                   therapy.therapy_referral_id AS referral_id,
                   therapy.visit_id, therapy.created_at, therapy.reason,
                   therapy.therapy_type AS details, therapy.status,
                   therapy.therapy_type AS destination,
                   visit.patient_id, visit.status AS visit_status,
                   patient.patient_number, patient.first_name, patient.last_name
            FROM therapy_referrals therapy
            INNER JOIN visits visit ON visit.visit_id = therapy.visit_id
            INNER JOIN patients patient ON patient.patient_id = visit.patient_id
            UNION ALL
            SELECT \'outside\' AS referral_type,
                   outside_referral.outside_referral_id AS referral_id,
                   outside_referral.visit_id, outside_referral.created_at,
                   NULL AS reason, outside_referral.clinical_summary AS details,
                   outside_referral.status,
                   COALESCE(outside_referral.provider_name, \'Outside provider\') AS destination,
                   visit.patient_id, visit.status AS visit_status,
                   patient.patient_number, patient.first_name, patient.last_name
            FROM outside_referrals outside_referral
            INNER JOIN visits visit ON visit.visit_id = outside_referral.visit_id
            INNER JOIN patients patient ON patient.patient_id = visit.patient_id
         ) referral_activity
         WHERE (
                :search_empty = \'\'
                OR referral_activity.patient_number LIKE :patient_number
                OR referral_activity.first_name LIKE :first_name
                OR referral_activity.last_name LIKE :last_name
                OR CONCAT_WS(\' \', referral_activity.first_name,
                             referral_activity.last_name) LIKE :full_name
                OR CAST(referral_activity.visit_id AS CHAR) LIKE :visit_id
         )
         ORDER BY referral_activity.created_at DESC, referral_activity.referral_id DESC
         LIMIT 250'
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
        recordDoctorAudit(
            $database,
            (int) $user['user_id'],
            'doctor.referrals.viewed:' . $referral['referral_type'],
            (int) $referral['patient_id'],
            (int) $referral['visit_id']
        );
    }

    jsonSuccess('Doctor referrals loaded.', [
        'referrals' => array_map(static function (array $referral): array {
            return [
                'referral_type' => $referral['referral_type'],
                'referral_id' => (int) $referral['referral_id'],
                'visit_id' => (int) $referral['visit_id'],
                'patient_number' => $referral['patient_number'],
                'patient_name' => trim(
                    (string) $referral['first_name'] . ' ' . (string) $referral['last_name']
                ),
                'visit_status' => $referral['visit_status'],
                'created_at' => $referral['created_at'],
                'reason' => $referral['reason'],
                'details' => $referral['details'],
                'destination' => $referral['destination'],
                'status' => $referral['status'],
            ];
        }, $referrals),
    ]);
}

function doctorRequiredText(
    array $input,
    string $field,
    string $label,
    int $maximum,
    bool $required,
    array &$errors
): ?string {
    $value = $input[$field] ?? null;
    if ($value === null || (is_string($value) && trim($value) === '')) {
        if ($required) {
            $errors[$field] = $label . ' is required.';
        }
        return null;
    }
    if (!is_string($value) || strlen(trim($value)) > $maximum) {
        $errors[$field] = $label . ' must be text of ' . $maximum . ' characters or fewer.';
        return null;
    }

    return trim($value);
}

function doctorVisitForUpdate(PDO $database, int $visitId): array
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
    if ($visit === false) {
        jsonError('Visit not found.', [], 404);
    }
    if ((int) $visit['medication_collection_only'] === 1
        || !in_array($visit['status'], ['awaiting_doctor', 'awaiting_tests'], true)) {
        jsonError('This visit is not currently available for doctor consultation.', [], 409);
    }
    if ($visit['status'] === 'awaiting_tests') {
        $readyStatement = $database->prepare(
            "SELECT
                EXISTS (
                    SELECT 1
                    FROM test_orders t
                    INNER JOIN test_results tr ON tr.test_order_id = t.test_order_id
                    WHERE t.visit_id = :visit_id_result
                      AND tr.status IN ('final', 'amended')
                ) AS has_final_result,
                EXISTS (
                    SELECT 1 FROM test_orders pending
                    WHERE pending.visit_id = :visit_id_pending
                      AND (
                        pending.status IN ('ordered', 'in_progress')
                        OR (
                            pending.status = 'completed'
                            AND NOT EXISTS (
                                SELECT 1 FROM test_results missing_result
                                WHERE missing_result.test_order_id =
                                      pending.test_order_id
                                  AND missing_result.status IN ('final', 'amended')
                            )
                        )
                      )
                ) AS has_pending_orders"
        );
        $readyStatement->execute([
            'visit_id_result' => $visitId,
            'visit_id_pending' => $visitId,
        ]);
        $ready = $readyStatement->fetch();
        if (!(bool) $ready['has_final_result'] || (bool) $ready['has_pending_orders']) {
            jsonError('Test results are not ready for doctor review.', [], 409);
        }
    }

    return $visit;
}

function handleDoctorConsultationCreate(): void
{
    $database = getDatabaseConnection();
    $user = requireDoctorAccess($database);
    requireCsrfRequest();
    $input = readJsonRequest();
    $visitId = $input['visit_id'] ?? null;
    if ((!is_int($visitId) && !(is_string($visitId) && ctype_digit($visitId)))
        || (int) $visitId < 1) {
        jsonError('A valid visit ID is required.', [
            'visit_id' => 'Enter a valid visit ID.',
        ], 422);
    }

    $errors = [];
    $complaint = doctorRequiredText(
        $input,
        'presenting_complaint',
        'Presenting complaint',
        5000,
        true,
        $errors
    );
    $observations = doctorRequiredText(
        $input,
        'clinical_observations',
        'Clinical observations',
        10000,
        false,
        $errors
    );
    $findings = doctorRequiredText(
        $input,
        'examination_findings',
        'Examination findings',
        10000,
        false,
        $errors
    );
    $notes = doctorRequiredText($input, 'notes', 'Consultation notes', 10000, false, $errors);
    if ($errors !== []) {
        jsonError('Please correct the consultation information.', $errors, 422);
    }

    try {
        $database->beginTransaction();
        $visit = doctorVisitForUpdate($database, (int) $visitId);

        $unfinishedStatement = $database->prepare(
            "SELECT consultation_id
             FROM consultations
             WHERE visit_id = :visit_id AND next_step = 'undecided'
             ORDER BY consultation_id DESC
             LIMIT 1
             FOR UPDATE"
        );
        $unfinishedStatement->execute(['visit_id' => (int) $visitId]);
        if ($unfinishedStatement->fetch() !== false) {
            $database->rollBack();
            jsonError('Complete the pending consultation decision before adding another consultation.', [], 409);
        }

        $statement = $database->prepare(
            "INSERT INTO consultations
                (visit_id, clinician_user_id, consultation_type,
                 presenting_complaint, clinical_observations,
                 examination_findings, notes, next_step)
             VALUES
                (:visit_id, :clinician_user_id, 'general',
                 :presenting_complaint, :clinical_observations,
                 :examination_findings, :notes, 'undecided')"
        );
        $statement->execute([
            'visit_id' => (int) $visitId,
            'clinician_user_id' => (int) $user['user_id'],
            'presenting_complaint' => $complaint,
            'clinical_observations' => $observations,
            'examination_findings' => $findings,
            'notes' => $notes,
        ]);
        $consultationId = (int) $database->lastInsertId();

        if ($visit['status'] === 'awaiting_tests') {
            $statusStatement = $database->prepare(
                "UPDATE visits SET status = 'awaiting_doctor'
                 WHERE visit_id = :visit_id"
            );
            $statusStatement->execute(['visit_id' => (int) $visitId]);
        }
        recordDoctorAudit(
            $database,
            (int) $user['user_id'],
            'doctor.consultation.created',
            (int) $visit['patient_id'],
            (int) $visitId
        );
        $database->commit();
    } catch (Throwable $exception) {
        if ($database->inTransaction()) {
            $database->rollBack();
        }
        error_log('Doctor consultation creation failed: ' . $exception->getMessage());
        jsonError('Unable to save the consultation right now.', [], 500);
    }

    jsonSuccess('Consultation saved. Record the diagnosis and next step.', [
        'consultation_id' => $consultationId,
        'visit_id' => (int) $visitId,
        'clinician_user_id' => (int) $user['user_id'],
    ], 201);
}

function validateDoctorPrescriptionItems(mixed $items): array
{
    if (!is_array($items) || count($items) > 20) {
        jsonError('Prescription items must be a list of up to 20 medicines.', [
            'items' => 'Select one or more valid medicines.',
        ], 422);
    }
    $errors = [];
    $validated = [];
    $seen = [];
    foreach ($items as $index => $item) {
        if (!is_array($item)) {
            $errors['items.' . $index] = 'Each item must contain a medicine and directions.';
            continue;
        }
        $medicineId = $item['medicine_id'] ?? null;
        if ((!is_int($medicineId) && !(is_string($medicineId) && ctype_digit($medicineId)))
            || (int) $medicineId < 1) {
            $errors['items.' . $index . '.medicine_id'] = 'Choose a valid medicine.';
            continue;
        }
        $id = (int) $medicineId;
        if (isset($seen[$id])) {
            $errors['items.' . $index . '.medicine_id'] = 'Choose each medicine once.';
            continue;
        }
        $seen[$id] = true;

        $quantityValue = $item['quantity'] ?? null;
        if ((!is_int($quantityValue) && !is_float($quantityValue) && !is_string($quantityValue))
            || !is_numeric((string) $quantityValue)
            || !is_finite((float) $quantityValue)
            || (float) $quantityValue <= 0
            || (float) $quantityValue > 10000) {
            $errors['items.' . $index . '.quantity'] = 'Enter a quantity greater than 0 and no more than 10000.';
            continue;
        }

        $directions = [];
        foreach (['dosage', 'frequency', 'duration', 'instructions'] as $field) {
            $value = $item[$field] ?? null;
            $maximum = $field === 'instructions' ? 2000 : 100;
            if ($value !== null && (!is_string($value) || strlen(trim($value)) > $maximum)) {
                $errors['items.' . $index . '.' . $field] = 'Enter text of ' . $maximum . ' characters or fewer.';
            }
            $directions[$field] = is_string($value) && trim($value) !== ''
                ? trim($value)
                : null;
        }
        $validated[] = [
            'medicine_id' => $id,
            'quantity' => number_format((float) $quantityValue, 2, '.', ''),
            'dosage' => $directions['dosage'],
            'frequency' => $directions['frequency'],
            'duration' => $directions['duration'],
            'instructions' => $directions['instructions'],
        ];
    }
    if ($errors !== []) {
        jsonError('Please correct the prescription items.', $errors, 422);
    }

    return $validated;
}

function createDoctorPrescription(
    PDO $database,
    int $visitId,
    ?int $consultationId,
    ?int $diagnosisId,
    int $doctorId,
    array $items
): int {
    if ($items === []) {
        jsonError('Add at least one medicine to create a prescription.', [], 422);
    }

    $medicineIds = array_column($items, 'medicine_id');
    $placeholders = implode(',', array_fill(0, count($medicineIds), '?'));
    $medicineStatement = $database->prepare(
        "SELECT medicine_id FROM medicines
         WHERE is_active = 1 AND medicine_id IN ($placeholders)"
    );
    $medicineStatement->execute($medicineIds);
    $activeIds = array_map('intval', $medicineStatement->fetchAll(PDO::FETCH_COLUMN));
    if (count($activeIds) !== count($medicineIds)) {
        jsonError('One or more selected medicines are unavailable.', [
            'items' => 'Choose only active medicines from the catalogue.',
        ], 422);
    }

    $prescriptionStatement = $database->prepare(
        "INSERT INTO prescriptions
            (visit_id, consultation_id, diagnosis_id, prescribed_by_user_id, status)
         VALUES
            (:visit_id, :consultation_id, :diagnosis_id, :doctor_id, 'active')"
    );
    $prescriptionStatement->execute([
        'visit_id' => $visitId,
        'consultation_id' => $consultationId,
        'diagnosis_id' => $diagnosisId,
        'doctor_id' => $doctorId,
    ]);
    $prescriptionId = (int) $database->lastInsertId();

    $itemStatement = $database->prepare(
        'INSERT INTO prescription_items
            (prescription_id, medicine_id, dosage, frequency, duration,
             quantity, instructions)
         VALUES
            (:prescription_id, :medicine_id, :dosage, :frequency, :duration,
             :quantity, :instructions)'
    );
    foreach ($items as $item) {
        $itemStatement->execute([
            'prescription_id' => $prescriptionId,
            'medicine_id' => $item['medicine_id'],
            'dosage' => $item['dosage'],
            'frequency' => $item['frequency'],
            'duration' => $item['duration'],
            'quantity' => $item['quantity'],
            'instructions' => $item['instructions'],
        ]);
    }

    return $prescriptionId;
}

function createClinicalTherapyReferral(
    PDO $database,
    int $visitId,
    int $consultationId,
    int $diagnosisId,
    int $clinicianId,
    string $therapyType,
    string $reason
): int {
    $statement = $database->prepare(
        'INSERT INTO therapy_referrals
            (visit_id, source_consultation_id, diagnosis_id,
             referred_by_user_id, therapy_type, reason)
         VALUES
            (:visit_id, :consultation_id, :diagnosis_id,
             :clinician_id, :therapy_type, :reason)'
    );
    $statement->execute([
        'visit_id' => $visitId,
        'consultation_id' => $consultationId,
        'diagnosis_id' => $diagnosisId,
        'clinician_id' => $clinicianId,
        'therapy_type' => $therapyType,
        'reason' => $reason,
    ]);

    return (int) $database->lastInsertId();
}

function doctorNextStatusAfterDiagnosis(PDO $database, int $visitId): string
{
    $therapyStatement = $database->prepare(
        "SELECT therapy_referral_id
         FROM therapy_referrals
         WHERE visit_id = :visit_id
           AND status IN ('referred', 'in_progress')
         LIMIT 1"
    );
    $therapyStatement->execute(['visit_id' => $visitId]);
    if ($therapyStatement->fetchColumn() !== false) {
        return 'awaiting_therapy';
    }

    $prescriptionStatement = $database->prepare(
        "SELECT prescription_id
         FROM prescriptions
         WHERE visit_id = :visit_id AND status = 'active'
         LIMIT 1"
    );
    $prescriptionStatement->execute(['visit_id' => $visitId]);
    if ($prescriptionStatement->fetchColumn() !== false) {
        return 'awaiting_pharmacy';
    }

    return 'completed';
}

function handleDoctorDiagnosisCreate(): void
{
    $database = getDatabaseConnection();
    $user = requireDoctorAccess($database);
    requireCsrfRequest();
    $input = readJsonRequest();
    $visitId = $input['visit_id'] ?? null;
    $consultationId = $input['consultation_id'] ?? null;
    foreach (['visit_id' => $visitId, 'consultation_id' => $consultationId] as $field => $value) {
        if ((!is_int($value) && !(is_string($value) && ctype_digit($value)))
            || (int) $value < 1) {
            jsonError('A valid visit and consultation are required.', [
                $field => 'Enter a valid ID.',
            ], 422);
        }
    }

    $diagnosisMade = $input['diagnosis_made'] ?? null;
    if (!is_bool($diagnosisMade)) {
        jsonError('Indicate whether a diagnosis was made.', [
            'diagnosis_made' => 'Choose yes or no.',
        ], 422);
    }
    $outcome = $input['outcome'] ?? null;
    if (!is_string($outcome)) {
        jsonError('Select the doctor decision.', [
            'outcome' => 'Choose diagnosis, more tests, or specialist referral.',
        ], 422);
    }

    $errors = [];
    $description = doctorRequiredText(
        $input,
        'diagnosis_description',
        'Diagnosis description',
        5000,
        $diagnosisMade,
        $errors
    );
    $findings = doctorRequiredText(
        $input,
        'clinical_findings',
        'Clinical findings',
        10000,
        false,
        $errors
    );
    $treatmentPlan = doctorRequiredText(
        $input,
        'treatment_plan',
        'Treatment plan',
        10000,
        $diagnosisMade,
        $errors
    );
    $diagnosisNotes = doctorRequiredText(
        $input,
        'notes',
        'Additional notes',
        10000,
        false,
        $errors
    );
    $testTypes = $input['test_types'] ?? [];
    $specialistId = $input['specialist_user_id'] ?? null;
    $referralReason = doctorRequiredText(
        $input,
        'referral_reason',
        'Referral reason',
        5000,
        !$diagnosisMade && $outcome === 'specialist_referral',
        $errors
    );
    $clinicalSummary = doctorRequiredText(
        $input,
        'clinical_summary',
        'Clinical summary',
        10000,
        false,
        $errors
    );
    $therapyEnabled = $input['refer_for_therapy'] ?? false;
    if (!is_bool($therapyEnabled)) {
        $errors['refer_for_therapy'] = 'Choose whether an in-house therapy referral is required.';
        $therapyEnabled = false;
    }
    $therapyType = doctorRequiredText(
        $input,
        'therapy_type',
        'Therapy type',
        100,
        $diagnosisMade && $therapyEnabled,
        $errors
    );
    $therapyReason = doctorRequiredText(
        $input,
        'therapy_reason',
        'Therapy referral reason',
        5000,
        $diagnosisMade && $therapyEnabled,
        $errors
    );
    $items = validateDoctorPrescriptionItems($input['prescription_items'] ?? []);

    if ($diagnosisMade && $outcome !== 'diagnosis') {
        $errors['outcome'] = 'A recorded diagnosis must use the diagnosis outcome.';
    } elseif (!$diagnosisMade && !in_array($outcome, ['more_tests', 'specialist_referral'], true)) {
        $errors['outcome'] = 'Without a diagnosis, choose more tests or specialist referral.';
    }

    $departments = doctorTestDepartments();
    if ($outcome === 'more_tests') {
        if (!is_array($testTypes) || $testTypes === [] || count($testTypes) > count($departments)) {
            $errors['test_types'] = 'Choose one or more approved in-house tests.';
        } else {
            $allTestTypesAreStrings = true;
            foreach ($testTypes as $testType) {
                if (!is_string($testType) || !isset($departments[$testType])) {
                    $errors['test_types'] = 'Choose only approved in-house tests.';
                    $allTestTypesAreStrings = false;
                }
            }
            if ($allTestTypesAreStrings
                && count(array_unique($testTypes)) !== count($testTypes)) {
                $errors['test_types'] = 'Remove duplicate test selections.';
            }
        }
    } elseif ($testTypes !== []) {
        $errors['test_types'] = 'Test types may only be selected for the more-tests outcome.';
    }
    if ($outcome === 'specialist_referral') {
        if ((!is_int($specialistId)
                && !(is_string($specialistId) && ctype_digit($specialistId)))
            || (int) $specialistId < 1) {
            $errors['specialist_user_id'] = 'Choose an active resident or locum specialist.';
        }
    } elseif ($specialistId !== null && $specialistId !== '') {
        $errors['specialist_user_id'] = 'A specialist may only be selected for a specialist referral.';
    }
    if (!$diagnosisMade && ($items !== [] || $therapyEnabled)) {
        $errors['diagnosis_made'] = 'Prescriptions and therapy referrals require a recorded diagnosis.';
    }
    if ($errors !== []) {
        jsonError('Please correct the diagnosis and decision information.', $errors, 422);
    }

    $prescriptionId = null;
    $diagnosisId = null;
    $nextStatus = '';
    try {
        $database->beginTransaction();
        $visit = doctorVisitForUpdate($database, (int) $visitId);

        $consultationStatement = $database->prepare(
            "SELECT consultation_id
             FROM consultations
             WHERE consultation_id = :consultation_id
               AND visit_id = :visit_id
               AND clinician_user_id = :clinician_user_id
               AND consultation_type = 'general'
               AND next_step = 'undecided'
             LIMIT 1
             FOR UPDATE"
        );
        $consultationStatement->execute([
            'consultation_id' => (int) $consultationId,
            'visit_id' => (int) $visitId,
            'clinician_user_id' => (int) $user['user_id'],
        ]);
        if ($consultationStatement->fetch() === false) {
            $database->rollBack();
            jsonError('The consultation was not found or its decision was already recorded.', [], 409);
        }

        if ($outcome === 'specialist_referral') {
            $specialistStatement = $database->prepare(
                "SELECT u.user_id
                 FROM users u
                 INNER JOIN roles r ON r.role_id = u.role_id
                 WHERE u.user_id = :user_id AND u.is_active = 1
                   AND r.role_name IN ('resident_specialist', 'locum_specialist')
                 LIMIT 1"
            );
            $specialistStatement->execute(['user_id' => (int) $specialistId]);
            if ($specialistStatement->fetchColumn() === false) {
                $database->rollBack();
                jsonError('The selected specialist is unavailable.', [
                    'specialist_user_id' => 'Choose an active resident or locum specialist.',
                ], 422);
            }
        }

        $diagnosisStatement = $database->prepare(
            'INSERT INTO diagnoses
                (visit_id, consultation_id, diagnosed_by_user_id, diagnosis_made,
                 diagnosis_description, clinical_findings, treatment_plan, notes)
             VALUES
                (:visit_id, :consultation_id, :doctor_id, :diagnosis_made,
                 :description, :findings, :treatment_plan, :notes)'
        );
        $diagnosisStatement->execute([
            'visit_id' => (int) $visitId,
            'consultation_id' => (int) $consultationId,
            'doctor_id' => (int) $user['user_id'],
            'diagnosis_made' => $diagnosisMade ? 1 : 0,
            'description' => $description,
            'findings' => $findings,
            'treatment_plan' => $treatmentPlan,
            'notes' => $diagnosisNotes,
        ]);
        $diagnosisId = (int) $database->lastInsertId();

        $nextStep = $outcome === 'diagnosis'
            ? ($therapyEnabled ? 'therapy' : ($items !== [] ? 'prescription' : 'completed'))
            : ($outcome === 'more_tests' ? 'more_tests' : 'specialist_referral');
        $consultationUpdate = $database->prepare(
            'UPDATE consultations
             SET diagnosis_made = :diagnosis_made,
                 diagnosis_text = :diagnosis_text,
                 treatment_plan = :treatment_plan,
                 next_step = :next_step
             WHERE consultation_id = :consultation_id AND next_step = \'undecided\''
        );
        $consultationUpdate->execute([
            'diagnosis_made' => $diagnosisMade ? 1 : 0,
            'diagnosis_text' => $description,
            'treatment_plan' => $treatmentPlan,
            'next_step' => $nextStep,
            'consultation_id' => (int) $consultationId,
        ]);
        if ($consultationUpdate->rowCount() !== 1) {
            $database->rollBack();
            jsonError('The consultation decision was already recorded.', [], 409);
        }

        if ($outcome === 'more_tests') {
            foreach ($testTypes as $testType) {
                $previousOrderStatement = $database->prepare(
                    'SELECT test_order_id
                     FROM test_orders
                     WHERE visit_id = :visit_id AND test_type = :test_type
                     ORDER BY ordered_at DESC, test_order_id DESC
                     LIMIT 1'
                );
                $previousOrderStatement->execute([
                    'visit_id' => (int) $visitId,
                    'test_type' => $testType,
                ]);
                $previousOrderId = $previousOrderStatement->fetchColumn();
                $orderStatement = $database->prepare(
                    'INSERT INTO test_orders
                        (visit_id, test_type, department, ordered_by_user_id,
                         retest_of_order_id)
                     VALUES
                        (:visit_id, :test_type, :department, :doctor_id,
                         :retest_of_order_id)'
                );
                $orderStatement->execute([
                    'visit_id' => (int) $visitId,
                    'test_type' => $testType,
                    'department' => $departments[$testType],
                    'doctor_id' => (int) $user['user_id'],
                    'retest_of_order_id' => $previousOrderId === false
                        ? null
                        : (int) $previousOrderId,
                ]);
            }
            $nextStatus = 'awaiting_tests';
        } elseif ($outcome === 'specialist_referral') {
            $referralStatement = $database->prepare(
                'INSERT INTO specialist_referrals
                    (visit_id, source_consultation_id, specialist_user_id,
                     reason, clinical_summary)
                 VALUES
                    (:visit_id, :consultation_id, :specialist_user_id,
                     :reason, :clinical_summary)'
            );
            $referralStatement->execute([
                'visit_id' => (int) $visitId,
                'consultation_id' => (int) $consultationId,
                'specialist_user_id' => (int) $specialistId,
                'reason' => $referralReason,
                'clinical_summary' => $clinicalSummary,
            ]);
            $nextStatus = 'awaiting_specialist';
        } else {
            if ($therapyEnabled) {
                createClinicalTherapyReferral(
                    $database,
                    (int) $visitId,
                    (int) $consultationId,
                    $diagnosisId,
                    (int) $user['user_id'],
                    $therapyType,
                    $therapyReason
                );
            }
            if ($items !== []) {
                $prescriptionId = createDoctorPrescription(
                    $database,
                    (int) $visitId,
                    (int) $consultationId,
                    $diagnosisId,
                    (int) $user['user_id'],
                    $items
                );
            }
            $nextStatus = doctorNextStatusAfterDiagnosis($database, (int) $visitId);
        }

        $visitUpdate = $database->prepare(
            'UPDATE visits SET status = :status WHERE visit_id = :visit_id'
        );
        $visitUpdate->execute([
            'status' => $nextStatus,
            'visit_id' => (int) $visitId,
        ]);
        recordDoctorAudit(
            $database,
            (int) $user['user_id'],
            'doctor.decision.' . $nextStep,
            (int) $visit['patient_id'],
            (int) $visitId
        );
        $database->commit();
    } catch (Throwable $exception) {
        if ($database->inTransaction()) {
            $database->rollBack();
        }
        error_log('Doctor diagnosis and decision failed: ' . $exception->getMessage());
        jsonError('Unable to save the diagnosis and next step right now.', [], 500);
    }

    jsonSuccess('Diagnosis and next step recorded.', [
        'visit_id' => (int) $visitId,
        'consultation_id' => (int) $consultationId,
        'diagnosis_id' => $diagnosisId,
        'prescription_id' => $prescriptionId,
        'next_step' => $nextStep,
        'status' => $nextStatus,
    ], 201);
}

function handleDoctorPrescriptionCreate(): void
{
    $database = getDatabaseConnection();
    $user = requireDoctorAccess($database);
    requireCsrfRequest();
    $input = readJsonRequest();
    $visitId = $input['visit_id'] ?? null;
    $consultationId = $input['consultation_id'] ?? null;
    $diagnosisId = $input['diagnosis_id'] ?? null;
    foreach (['visit_id' => $visitId, 'consultation_id' => $consultationId, 'diagnosis_id' => $diagnosisId] as $field => $value) {
        if ((!is_int($value) && !(is_string($value) && ctype_digit($value)))
            || (int) $value < 1) {
            jsonError('A valid visit, consultation, and diagnosis are required.', [
                $field => 'Enter a valid ID.',
            ], 422);
        }
    }
    $items = validateDoctorPrescriptionItems($input['items'] ?? null);
    if ($items === []) {
        jsonError('Add at least one medicine to create a prescription.', [], 422);
    }

    $prescriptionId = null;
    try {
        $database->beginTransaction();
        $visitStatement = $database->prepare(
            'SELECT visit_id, patient_id, status, is_emergency, medication_collection_only
             FROM visits
             WHERE visit_id = :visit_id
             LIMIT 1
             FOR UPDATE'
        );
        $visitStatement->execute(['visit_id' => (int) $visitId]);
        $visit = $visitStatement->fetch();
        if ($visit === false) {
            $database->rollBack();
            jsonError('Visit not found.', [], 404);
        }
        if ((int) $visit['medication_collection_only'] === 1
            || !in_array(
                $visit['status'],
                ['awaiting_doctor', 'awaiting_tests', 'awaiting_pharmacy', 'awaiting_therapy'],
                true
            )) {
            $database->rollBack();
            jsonError('A prescription cannot be added at this visit status.', [], 409);
        }
        if ($visit['status'] === 'awaiting_tests') {
            $readyStatement = $database->prepare(
                "SELECT
                    EXISTS (
                        SELECT 1
                        FROM test_orders t
                        INNER JOIN test_results tr ON tr.test_order_id = t.test_order_id
                        WHERE t.visit_id = :visit_id_result
                          AND tr.status IN ('final', 'amended')
                    ) AS has_final_result,
                    EXISTS (
                        SELECT 1 FROM test_orders pending
                        WHERE pending.visit_id = :visit_id_pending
                          AND (
                            pending.status IN ('ordered', 'in_progress')
                            OR (
                                pending.status = 'completed'
                                AND NOT EXISTS (
                                    SELECT 1 FROM test_results missing_result
                                    WHERE missing_result.test_order_id =
                                          pending.test_order_id
                                      AND missing_result.status IN ('final', 'amended')
                                )
                            )
                          )
                    ) AS has_pending_orders"
            );
            $readyStatement->execute([
                'visit_id_result' => (int) $visitId,
                'visit_id_pending' => (int) $visitId,
            ]);
            $ready = $readyStatement->fetch();
            if (!(bool) $ready['has_final_result'] || (bool) $ready['has_pending_orders']) {
                $database->rollBack();
                jsonError('Test results are not ready for doctor review.', [], 409);
            }
        }
        $diagnosisStatement = $database->prepare(
            'SELECT d.diagnosis_id
             FROM diagnoses d
             INNER JOIN consultations c ON c.consultation_id = d.consultation_id
             WHERE d.diagnosis_id = :diagnosis_id
               AND d.visit_id = :visit_id
               AND c.consultation_id = :consultation_id
               AND d.diagnosis_made = 1
             LIMIT 1'
        );
        $diagnosisStatement->execute([
            'diagnosis_id' => (int) $diagnosisId,
            'visit_id' => (int) $visitId,
            'consultation_id' => (int) $consultationId,
        ]);
        if ($diagnosisStatement->fetchColumn() === false) {
            $database->rollBack();
            jsonError('A prescription requires a diagnosis linked to this visit and consultation.', [], 409);
        }
        $prescriptionId = createDoctorPrescription(
            $database,
            (int) $visitId,
            (int) $consultationId,
            (int) $diagnosisId,
            (int) $user['user_id'],
            $items
        );
        $nextStatus = doctorNextStatusAfterDiagnosis($database, (int) $visitId);
        $visitUpdate = $database->prepare(
            'UPDATE visits SET status = :status
             WHERE visit_id = :visit_id'
        );
        $visitUpdate->execute([
            'status' => $nextStatus,
            'visit_id' => (int) $visitId,
        ]);
        recordDoctorAudit(
            $database,
            (int) $user['user_id'],
            'doctor.prescription.created',
            (int) $visit['patient_id'],
            (int) $visitId
        );
        $database->commit();
    } catch (Throwable $exception) {
        if ($database->inTransaction()) {
            $database->rollBack();
        }
        error_log('Doctor prescription creation failed: ' . $exception->getMessage());
        jsonError('Unable to save the prescription right now.', [], 500);
    }

    $message = $nextStatus === 'awaiting_therapy'
        ? 'Prescription created. The visit remains queued for therapy before pharmacy.'
        : 'Prescription created and made available to pharmacy.';
    jsonSuccess($message, [
        'prescription_id' => $prescriptionId,
        'visit_id' => (int) $visitId,
        'status' => $nextStatus,
    ], 201);
}
