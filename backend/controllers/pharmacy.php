<?php

declare(strict_types=1);

function requirePharmacyAccess(PDO $database): array
{
    $user = getAuthenticatedUser($database);
    if ($user === null) {
        jsonError('Authentication is required.', [], 401);
    }

    if (!in_array($user['role_name'], ['pharmacist', 'system_administrator'], true)) {
        jsonError('Pharmacist or system administrator access is required.', [], 403);
    }

    return $user;
}

function recordPharmacyAudit(
    PDO $database,
    int $userId,
    string $action,
    ?int $patientId,
    ?int $visitId
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

function pharmacyVisitForUpdate(PDO $database, int $visitId): array|false
{
    $statement = $database->prepare(
        'SELECT visit.visit_id, visit.patient_id, visit.status,
                visit.is_emergency, visit.medication_collection_only,
                visit.arrived_at, patient.patient_number, patient.first_name,
                patient.last_name
         FROM visits visit
         INNER JOIN patients patient ON patient.patient_id = visit.patient_id
         WHERE visit.visit_id = :visit_id
         LIMIT 1
         FOR UPDATE'
    );
    $statement->execute(['visit_id' => $visitId]);

    return $statement->fetch();
}

function pharmacyEnsurePrescriptionCharges(PDO $database, array $visit, array $bill): void
{
    if ((int) $visit['medication_collection_only'] === 1) {
        return;
    }

    $prescriptions = $database->prepare(
        'SELECT prescription_id
         FROM prescriptions
         WHERE visit_id = :visit_id AND status = \'active\'
         ORDER BY prescription_id
         FOR UPDATE'
    );
    $prescriptions->execute(['visit_id' => (int) $visit['visit_id']]);
    $items = $database->prepare(
        'SELECT prescription_item_id
         FROM prescription_items
         WHERE prescription_id = :prescription_id
         ORDER BY prescription_item_id
         FOR UPDATE'
    );
    foreach ($prescriptions->fetchAll() as $prescription) {
        $items->execute(['prescription_id' => (int) $prescription['prescription_id']]);
        foreach ($items->fetchAll() as $item) {
            billingAddMedicationItemOnce(
                $database,
                (int) $bill['bill_id'],
                (int) $item['prescription_item_id']
            );
        }
    }
}

function pharmacyEnsureEmergencyCharge(
    PDO $database,
    array $visit,
    array $bill,
    array $user
): bool {
    if ((int) $visit['is_emergency'] !== 1) {
        return true;
    }

    $encounterStatement = $database->prepare(
        'SELECT emergency_encounter_id
         FROM emergency_encounters
         WHERE visit_id = :visit_id AND ended_at IS NOT NULL
         LIMIT 1 FOR UPDATE'
    );
    $encounterStatement->execute(['visit_id' => (int) $visit['visit_id']]);
    $encounterId = $encounterStatement->fetchColumn();
    if ($encounterId === false) {
        return false;
    }

    $existingStatement = $database->prepare(
        'SELECT bill_item_id
         FROM bill_items
         WHERE bill_id = :bill_id AND emergency_encounter_id = :encounter_id
           AND charge_type = \'emergency\' AND is_voided = 0
         LIMIT 1 FOR UPDATE'
    );
    $existingStatement->execute([
        'bill_id' => (int) $bill['bill_id'],
        'encounter_id' => (int) $encounterId,
    ]);
    if ($existingStatement->fetchColumn() !== false) {
        return true;
    }

    $feeStatement = $database->prepare(
        'SELECT fee_code
         FROM fee_catalog
         WHERE fee_code = :fee_code AND is_active = 1 AND default_amount > 0
         LIMIT 1 FOR UPDATE'
    );
    $feeStatement->execute(['fee_code' => 'EMERGENCY_CARE_DEMO']);
    if ($feeStatement->fetchColumn() === false) {
        return false;
    }

    billingAddFeeItemOnce(
        $database,
        (int) $bill['bill_id'],
        'EMERGENCY_CARE_DEMO',
        'emergency',
        null,
        null,
        (int) $encounterId
    );
    recordPharmacyAudit(
        $database,
        (int) $user['user_id'],
        'pharmacy.emergency_charge.added',
        (int) $visit['patient_id'],
        (int) $visit['visit_id']
    );

    return true;
}

function pharmacyRelatedPrescriptions(
    PDO $database,
    int $visitId,
    int $billId
): array {
    $statement = $database->prepare(
        'SELECT DISTINCT prescription.prescription_id,
                prescription.visit_id AS prescription_visit_id,
                prescription.status, prescription.created_at,
                prescription.pharmacy_verified_at,
                prescription.pharmacy_verified_by_user_id,
                prescriber.display_name AS prescribed_by,
                source_visit.arrived_at AS prescription_visit_at,
                dispensing.dispensing_id, dispensing.dispensed_at
         FROM prescriptions prescription
         INNER JOIN visits source_visit
            ON source_visit.visit_id = prescription.visit_id
         INNER JOIN users prescriber
            ON prescriber.user_id = prescription.prescribed_by_user_id
         LEFT JOIN dispensings dispensing
            ON dispensing.prescription_id = prescription.prescription_id
         WHERE prescription.visit_id = :visit_id
            OR EXISTS (
                SELECT 1
                FROM prescription_items item
                INNER JOIN bill_items bill_item
                    ON bill_item.prescription_item_id = item.prescription_item_id
                WHERE item.prescription_id = prescription.prescription_id
                  AND bill_item.bill_id = :bill_id
            )
         ORDER BY prescription.created_at, prescription.prescription_id'
    );
    $statement->execute([
        'visit_id' => $visitId,
        'bill_id' => $billId,
    ]);

    $prescriptions = [];
    $items = $database->prepare(
        'SELECT item.prescription_item_id, item.medicine_id,
                medicine.medicine_name, item.dosage, item.frequency,
                item.duration, item.quantity, item.instructions,
                medicine.unit_price, medicine.quantity_in_stock,
                bill_item.bill_item_id,
                dispensing_item.dispensing_item_id,
                dispensing_item.quantity_dispensed
         FROM prescription_items item
         INNER JOIN medicines medicine ON medicine.medicine_id = item.medicine_id
         LEFT JOIN bill_items bill_item
            ON bill_item.prescription_item_id = item.prescription_item_id
           AND bill_item.bill_id = :bill_id
         LEFT JOIN dispensing_items dispensing_item
            ON dispensing_item.prescription_item_id = item.prescription_item_id
         WHERE item.prescription_id = :prescription_id
         ORDER BY item.prescription_item_id'
    );
    foreach ($statement->fetchAll() as $row) {
        $prescriptionId = (int) $row['prescription_id'];
        $items->execute([
            'bill_id' => $billId,
            'prescription_id' => $prescriptionId,
        ]);
        $prescriptions[] = [
            'prescription_id' => $prescriptionId,
            'prescription_visit_id' => (int) $row['prescription_visit_id'],
            'status' => $row['status'],
            'created_at' => $row['created_at'],
            'prescribed_by' => $row['prescribed_by'],
            'pharmacy_verified_at' => $row['pharmacy_verified_at'],
            'pharmacy_verified_by_user_id' =>
                $row['pharmacy_verified_by_user_id'] === null
                    ? null
                    : (int) $row['pharmacy_verified_by_user_id'],
            'is_dispensed' => $row['dispensing_id'] !== null,
            'dispensed_at' => $row['dispensed_at'],
            'items' => array_map(static function (array $item): array {
                return [
                    'prescription_item_id' => (int) $item['prescription_item_id'],
                    'medicine_id' => (int) $item['medicine_id'],
                    'medicine_name' => $item['medicine_name'],
                    'dosage' => $item['dosage'],
                    'frequency' => $item['frequency'],
                    'duration' => $item['duration'],
                    'quantity' => (string) $item['quantity'],
                    'instructions' => $item['instructions'],
                    'unit_price' => (string) $item['unit_price'],
                    'stock_available' => (string) $item['quantity_in_stock'],
                    'is_billed' => $item['bill_item_id'] !== null,
                    'is_dispensed' => $item['dispensing_item_id'] !== null,
                    'quantity_dispensed' => $item['quantity_dispensed'] === null
                        ? null
                        : (string) $item['quantity_dispensed'],
                ];
            }, $items->fetchAll()),
        ];
    }

    return $prescriptions;
}

function pharmacyDispensingHistory(PDO $database, int $visitId): array
{
    $statement = $database->prepare(
        'SELECT dispensing.dispensing_id, dispensing.prescription_id,
                dispensing.dispensed_at, dispensing.dispensing_notes,
                pharmacist.display_name AS pharmacist_name,
                prescription_item.prescription_item_id, medicine.medicine_name,
                dispensing_item.quantity_dispensed, dispensing_item.unit_price
         FROM dispensings dispensing
         INNER JOIN prescriptions prescription
            ON prescription.prescription_id = dispensing.prescription_id
         INNER JOIN users pharmacist
            ON pharmacist.user_id = dispensing.pharmacist_user_id
         INNER JOIN dispensing_items dispensing_item
            ON dispensing_item.dispensing_id = dispensing.dispensing_id
         INNER JOIN prescription_items prescription_item
            ON prescription_item.prescription_item_id =
               dispensing_item.prescription_item_id
         INNER JOIN medicines medicine
            ON medicine.medicine_id = dispensing_item.medicine_id
         WHERE dispensing.visit_id = :visit_id
         ORDER BY dispensing.dispensed_at, dispensing.dispensing_id,
                  dispensing_item.dispensing_item_id'
    );
    $statement->execute(['visit_id' => $visitId]);
    $dispensings = [];
    foreach ($statement->fetchAll() as $row) {
        $dispensingId = (int) $row['dispensing_id'];
        if (!isset($dispensings[$dispensingId])) {
            $dispensings[$dispensingId] = [
                'dispensing_id' => $dispensingId,
                'prescription_id' => (int) $row['prescription_id'],
                'dispensed_at' => $row['dispensed_at'],
                'notes' => $row['dispensing_notes'],
                'pharmacist_name' => $row['pharmacist_name'],
                'items' => [],
            ];
        }
        $dispensings[$dispensingId]['items'][] = [
            'prescription_item_id' => (int) $row['prescription_item_id'],
            'medicine_name' => $row['medicine_name'],
            'quantity_dispensed' => (string) $row['quantity_dispensed'],
            'unit_price' => (string) $row['unit_price'],
        ];
    }

    return array_values($dispensings);
}

function pharmacyAvailablePrescriptions(
    PDO $database,
    int $patientId,
    int $visitId,
    string $arrivedAt
): array {
    $statement = $database->prepare(
        'SELECT prescription.prescription_id, prescription.visit_id,
                prescription.created_at, source_visit.arrived_at,
                COUNT(item.prescription_item_id) AS item_count
         FROM prescriptions prescription
         INNER JOIN visits source_visit
            ON source_visit.visit_id = prescription.visit_id
         INNER JOIN prescription_items item
            ON item.prescription_id = prescription.prescription_id
         WHERE source_visit.patient_id = :patient_id
           AND prescription.visit_id <> :visit_id
           AND source_visit.arrived_at <= :arrived_at
           AND prescription.status = \'active\'
           AND NOT EXISTS (
                SELECT 1 FROM dispensings dispensing
                WHERE dispensing.prescription_id = prescription.prescription_id
           )
           AND NOT EXISTS (
                SELECT 1
                FROM prescription_items existing_item
                INNER JOIN bill_items existing_charge
                    ON existing_charge.prescription_item_id =
                       existing_item.prescription_item_id
                WHERE existing_item.prescription_id = prescription.prescription_id
           )
         GROUP BY prescription.prescription_id, prescription.visit_id,
                  prescription.created_at, source_visit.arrived_at
         ORDER BY source_visit.arrived_at DESC, prescription.prescription_id DESC'
    );
    $statement->execute([
        'patient_id' => $patientId,
        'visit_id' => $visitId,
        'arrived_at' => $arrivedAt,
    ]);

    $prescriptions = [];
    $itemStatement = $database->prepare(
        'SELECT item.prescription_item_id, item.medicine_id,
                medicine.medicine_name, item.dosage, item.frequency,
                item.duration, item.quantity, item.instructions,
                medicine.unit_price, medicine.quantity_in_stock
         FROM prescription_items item
         INNER JOIN medicines medicine ON medicine.medicine_id = item.medicine_id
         WHERE item.prescription_id = :prescription_id
         ORDER BY item.prescription_item_id'
    );
    foreach ($statement->fetchAll() as $row) {
        $itemStatement->execute([
            'prescription_id' => (int) $row['prescription_id'],
        ]);
        $prescriptions[] = [
            'prescription_id' => (int) $row['prescription_id'],
            'prescription_visit_id' => (int) $row['visit_id'],
            'prescription_visit_at' => $row['arrived_at'],
            'item_count' => (int) $row['item_count'],
            'items' => array_map(static function (array $item): array {
                return [
                    'prescription_item_id' => (int) $item['prescription_item_id'],
                    'medicine_id' => (int) $item['medicine_id'],
                    'medicine_name' => $item['medicine_name'],
                    'dosage' => $item['dosage'],
                    'frequency' => $item['frequency'],
                    'duration' => $item['duration'],
                    'quantity' => (string) $item['quantity'],
                    'instructions' => $item['instructions'],
                    'unit_price' => (string) $item['unit_price'],
                    'stock_available' => (string) $item['quantity_in_stock'],
                ];
            }, $itemStatement->fetchAll()),
        ];
    }

    return $prescriptions;
}

function pharmacyBillItems(PDO $database, int $billId): array
{
    $statement = $database->prepare(
        'SELECT bill_item_id, charge_type, description, quantity, unit_amount,
                CAST(quantity * unit_amount AS DECIMAL(12,2)) AS line_total,
                test_order_id, prescription_item_id, minor_treatment_item_id,
                therapy_session_id, outside_referral_id, emergency_encounter_id
         FROM bill_items
         WHERE bill_id = :bill_id
         ORDER BY FIELD(charge_type, \'ticket\', \'test\', \'minor_treatment\',
                        \'emergency\', \'therapy\', \'outside_referral\',
                        \'medication\'), bill_item_id'
    );
    $statement->execute(['bill_id' => $billId]);

    $groups = [];
    foreach ($statement->fetchAll() as $row) {
        $type = $row['charge_type'];
        if (!isset($groups[$type])) {
            $groups[$type] = [
                'charge_type' => $type,
                'items' => [],
                'total' => '0.00',
            ];
        }
        $groups[$type]['items'][] = [
            'bill_item_id' => (int) $row['bill_item_id'],
            'description' => $row['description'],
            'quantity' => (string) $row['quantity'],
            'unit_amount' => (string) $row['unit_amount'],
            'line_total' => (string) $row['line_total'],
            'test_order_id' => $row['test_order_id'] === null
                ? null
                : (int) $row['test_order_id'],
            'prescription_item_id' => $row['prescription_item_id'] === null
                ? null
                : (int) $row['prescription_item_id'],
            'minor_treatment_item_id' => $row['minor_treatment_item_id'] === null
                ? null
                : (int) $row['minor_treatment_item_id'],
            'therapy_session_id' => $row['therapy_session_id'] === null
                ? null
                : (int) $row['therapy_session_id'],
            'outside_referral_id' => $row['outside_referral_id'] === null
                ? null
                : (int) $row['outside_referral_id'],
            'emergency_encounter_id' => $row['emergency_encounter_id'] === null
                ? null
                : (int) $row['emergency_encounter_id'],
        ];
        $groups[$type]['total'] = number_format(
            (float) $groups[$type]['total'] + (float) $row['line_total'],
            2,
            '.',
            ''
        );
    }

    return array_values($groups);
}

function pharmacyUpdateVisitCompletion(
    PDO $database,
    int $visitId,
    int $billId,
    array $balance,
    bool $medicationOnly
): bool {
    if ((float) $balance['balance'] > 0) {
        return false;
    }

    if ($medicationOnly) {
        $selectedPrescription = $database->prepare(
            'SELECT 1
             FROM bill_items
             WHERE bill_id = :bill_id
               AND prescription_item_id IS NOT NULL
             LIMIT 1'
        );
        $selectedPrescription->execute(['bill_id' => $billId]);
        if ($selectedPrescription->fetchColumn() === false) {
            return false;
        }
    }

    $billUpdate = $database->prepare(
        'UPDATE bills SET status = \'paid\' WHERE bill_id = :bill_id'
    );
    $billUpdate->execute(['bill_id' => $billId]);

    $undispensed = $database->prepare(
        'SELECT 1
         FROM prescriptions prescription
         WHERE (
             prescription.visit_id = :visit_id
             OR EXISTS (
                SELECT 1
                FROM prescription_items item
                INNER JOIN bill_items bill_item
                    ON bill_item.prescription_item_id = item.prescription_item_id
                WHERE item.prescription_id = prescription.prescription_id
                  AND bill_item.bill_id = :bill_id
             )
         )
           AND NOT EXISTS (
                SELECT 1 FROM dispensings dispensing
                WHERE dispensing.prescription_id = prescription.prescription_id
           )
           AND prescription.status = \'active\'
         LIMIT 1'
    );
    $undispensed->execute([
        'visit_id' => $visitId,
        'bill_id' => $billId,
    ]);
    if ($undispensed->fetchColumn() !== false) {
        return false;
    }

    $visitUpdate = $database->prepare(
        'UPDATE visits
         SET status = \'completed\'
         WHERE visit_id = :visit_id
           AND status IN (\'awaiting_pharmacy\', \'awaiting_payment\')'
    );
    $visitUpdate->execute(['visit_id' => $visitId]);

    return $visitUpdate->rowCount() === 1;
}

function pharmacyVisitDetails(PDO $database, array $visit, array $bill): array
{
    $balance = billingGetBillBalance($database, (int) $bill['bill_id']);
    $paymentStatement = $database->prepare(
        'SELECT payment_id, payment_method, amount, status, is_simulated,
                receipt_number, paid_at
         FROM payments
         WHERE bill_id = :bill_id
         ORDER BY created_at, payment_id'
    );
    $paymentStatement->execute(['bill_id' => (int) $bill['bill_id']]);
    $payments = array_map(static function (array $payment): array {
        return [
            'payment_id' => (int) $payment['payment_id'],
            'payment_method' => $payment['payment_method'],
            'amount' => (string) $payment['amount'],
            'status' => $payment['status'],
            'is_simulated' => (bool) $payment['is_simulated'],
            'receipt_number' => $payment['receipt_number'],
            'paid_at' => $payment['paid_at'],
        ];
    }, $paymentStatement->fetchAll());
    $schemes = $database->query(
        'SELECT medical_aid_scheme_id, scheme_name
         FROM medical_aid_schemes
         WHERE is_active = 1
         ORDER BY scheme_name'
    )->fetchAll();
    $membershipStatement = $database->prepare(
        'SELECT membership.membership_id, membership.medical_aid_scheme_id,
                scheme.scheme_name, membership.member_number,
                membership.member_name
         FROM patient_medical_aid_memberships membership
         INNER JOIN medical_aid_schemes scheme
            ON scheme.medical_aid_scheme_id = membership.medical_aid_scheme_id
         WHERE membership.patient_id = :patient_id
           AND membership.is_active = 1
           AND scheme.is_active = 1
         ORDER BY scheme.scheme_name, membership.member_name'
    );
    $membershipStatement->execute(['patient_id' => (int) $visit['patient_id']]);

    return [
        'visit' => [
            'visit_id' => (int) $visit['visit_id'],
            'patient_id' => (int) $visit['patient_id'],
            'patient_number' => $visit['patient_number'],
            'patient_name' => trim(
                (string) $visit['first_name'] . ' ' . (string) $visit['last_name']
            ),
            'status' => $visit['status'],
            'arrived_at' => $visit['arrived_at'],
            'is_emergency' => (bool) $visit['is_emergency'],
            'medication_collection_only' => (bool) $visit['medication_collection_only'],
        ],
        'bill' => [
            'bill_id' => (int) $bill['bill_id'],
            'bill_number' => $bill['bill_number'],
            'status' => $bill['status'],
            'groups' => pharmacyBillItems($database, (int) $bill['bill_id']),
            'total' => $balance['billed_amount'],
            'approved_payments' => $balance['approved_amount'],
            'balance' => $balance['balance'],
        ],
        'prescriptions' => pharmacyRelatedPrescriptions(
            $database,
            (int) $visit['visit_id'],
            (int) $bill['bill_id']
        ),
        'available_prescriptions' => (bool) $visit['medication_collection_only']
            ? pharmacyAvailablePrescriptions(
                $database,
                (int) $visit['patient_id'],
                (int) $visit['visit_id'],
                (string) $visit['arrived_at']
            )
            : [],
        'payments' => $payments,
        'dispensings' => pharmacyDispensingHistory(
            $database,
            (int) $visit['visit_id']
        ),
        'medical_aid_memberships' => array_map(
            static function (array $membership): array {
                return [
                    'membership_id' => (int) $membership['membership_id'],
                    'medical_aid_scheme_id' =>
                        (int) $membership['medical_aid_scheme_id'],
                    'scheme_name' => $membership['scheme_name'],
                    'member_number' => $membership['member_number'],
                    'member_name' => $membership['member_name'],
                ];
            },
            $membershipStatement->fetchAll()
        ),
        'medical_aid_schemes' => array_map(static function (array $scheme): array {
            return [
                'medical_aid_scheme_id' => (int) $scheme['medical_aid_scheme_id'],
                'scheme_name' => $scheme['scheme_name'],
            ];
        }, $schemes),
    ];
}

function handlePharmacyQueueGet(string $search): void
{
    $database = getDatabaseConnection();
    $user = requirePharmacyAccess($database);
    $search = trim($search);
    if (strlen($search) > 100) {
        jsonError('Search text must be 100 characters or fewer.', [
            'search' => 'Shorten the search text.',
        ], 422);
    }
    $searchSql = '';
    $parameters = [];
    if ($search !== '') {
        $searchSql = ' AND (
            patient.patient_number LIKE :patient_number
            OR patient.first_name LIKE :first_name
            OR patient.last_name LIKE :last_name
            OR CONCAT_WS(\' \', patient.first_name, patient.last_name) LIKE :full_name
            OR CAST(visit.visit_id AS CHAR) LIKE :visit_id
        )';
        $pattern = '%' . $search . '%';
        $parameters = [
            'patient_number' => $pattern,
            'first_name' => $pattern,
            'last_name' => $pattern,
            'full_name' => $pattern,
            'visit_id' => $pattern,
        ];
    }

    $statement = $database->prepare(
        'SELECT visit.visit_id, visit.patient_id, visit.status, visit.arrived_at,
                visit.is_emergency, visit.medication_collection_only,
                patient.patient_number, patient.first_name, patient.last_name,
                COALESCE((
                    SELECT SUM(item.quantity * item.unit_amount)
                    FROM bill_items item
                    INNER JOIN bills visit_bill ON visit_bill.bill_id = item.bill_id
                    WHERE visit_bill.visit_id = visit.visit_id
                      AND item.is_voided = 0
                ), 0.00) AS billed_amount,
                COALESCE((
                    SELECT SUM(payment.amount)
                    FROM payments payment
                    INNER JOIN bills visit_bill ON visit_bill.bill_id = payment.bill_id
                    WHERE visit_bill.visit_id = visit.visit_id
                      AND payment.status = \'approved\'
                ), 0.00) AS approved_amount,
                COALESCE((
                    SELECT COUNT(*)
                    FROM prescriptions prescription
                    WHERE (
                        prescription.visit_id = visit.visit_id
                        OR EXISTS (
                            SELECT 1
                            FROM prescription_items item
                            INNER JOIN bill_items bill_item
                                ON bill_item.prescription_item_id =
                                   item.prescription_item_id
                            INNER JOIN bills linked_bill
                                ON linked_bill.bill_id = bill_item.bill_id
                            WHERE item.prescription_id =
                                  prescription.prescription_id
                              AND linked_bill.visit_id = visit.visit_id
                        )
                    )
                      AND prescription.status = \'active\'
                ), 0) AS active_prescription_count,
                (
                    SELECT bill.status
                    FROM bills bill
                    WHERE bill.visit_id = visit.visit_id
                    LIMIT 1
                ) AS bill_status
         FROM visits visit
         INNER JOIN patients patient ON patient.patient_id = visit.patient_id
         WHERE visit.status IN (\'awaiting_pharmacy\', \'awaiting_payment\')'
            . $searchSql .
        ' ORDER BY visit.arrived_at, visit.visit_id
          LIMIT 250'
    );
    $statement->execute($parameters);
    $visits = [];
    foreach ($statement->fetchAll() as $row) {
        $visits[] = [
            'visit_id' => (int) $row['visit_id'],
            'patient_id' => (int) $row['patient_id'],
            'patient_number' => $row['patient_number'],
            'patient_name' => trim(
                (string) $row['first_name'] . ' ' . (string) $row['last_name']
            ),
            'status' => $row['status'],
            'arrived_at' => $row['arrived_at'],
            'is_emergency' => (bool) $row['is_emergency'],
            'medication_collection_only' => (bool) $row['medication_collection_only'],
            'prescription_count' => (int) $row['active_prescription_count'],
            'bill_status' => $row['bill_status'] ?? 'not_billed',
            'bill_total' => number_format((float) $row['billed_amount'], 2, '.', ''),
            'approved_payments' => number_format(
                (float) $row['approved_amount'],
                2,
                '.',
                ''
            ),
            'balance' => number_format(
                max(0, (float) $row['billed_amount'] - (float) $row['approved_amount']),
                2,
                '.',
                ''
            ),
        ];
        recordPharmacyAudit(
            $database,
            (int) $user['user_id'],
            'pharmacy.queue.viewed',
            (int) $row['patient_id'],
            (int) $row['visit_id']
        );
    }

    jsonSuccess('Pharmacy queue loaded.', [
        'visits' => $visits,
        'count' => count($visits),
    ]);
}

function handlePharmacyVisitGet(string $visitId): void
{
    if (!ctype_digit($visitId) || (int) $visitId < 1) {
        jsonError('A valid visit ID is required.', [
            'visit_id' => 'Enter a positive visit ID.',
        ], 422);
    }

    $database = getDatabaseConnection();
    $user = requirePharmacyAccess($database);
    try {
        $database->beginTransaction();
        $visit = pharmacyVisitForUpdate($database, (int) $visitId);
        if ($visit === false) {
            $database->rollBack();
            jsonError('Visit not found.', [], 404);
        }
        if (!in_array($visit['status'], ['awaiting_pharmacy', 'awaiting_payment'], true)) {
            $database->rollBack();
            jsonError('This visit is not in the pharmacy queue.', [], 409);
        }
        $bill = billingGetOrCreateBill($database, (int) $visitId);
        if (!pharmacyEnsureEmergencyCharge($database, $visit, $bill, $user)) {
            $database->rollBack();
            jsonError(
                'Emergency service pricing is unavailable at Pharmacy; no treatment was blocked.',
                [],
                503
            );
        }
        pharmacyEnsurePrescriptionCharges($database, $visit, $bill);
        billingRefreshBillTotal($database, (int) $bill['bill_id']);
        $balance = billingGetBillBalance($database, (int) $bill['bill_id']);
        $visitCompleted = pharmacyUpdateVisitCompletion(
            $database,
            (int) $visitId,
            (int) $bill['bill_id'],
            $balance,
            (bool) $visit['medication_collection_only']
        );

        recordPharmacyAudit(
            $database,
            (int) $user['user_id'],
            'pharmacy.visit.viewed',
            (int) $visit['patient_id'],
            (int) $visitId
        );
        $database->commit();
    } catch (Throwable $exception) {
        if ($database->inTransaction()) {
            $database->rollBack();
        }
        error_log('Pharmacy visit opening failed: ' . $exception->getMessage());
        jsonError('Unable to open the pharmacy visit right now.', [], 500);
    }

    if ($visitCompleted) {
        $visit['status'] = 'completed';
    }
    if ((float) $balance['balance'] <= 0) {
        $bill['status'] = 'paid';
    }
    $details = pharmacyVisitDetails($database, $visit, $bill);
    jsonSuccess('Pharmacy visit loaded.', $details);
}

function handlePharmacyDispensingHistoryGet(string $visitId): void
{
    if (!ctype_digit($visitId) || (int) $visitId < 1) {
        jsonError('A valid visit ID is required.', [
            'visit_id' => 'Enter a positive visit ID.',
        ], 422);
    }

    $database = getDatabaseConnection();
    $user = requirePharmacyAccess($database);
    $visitStatement = $database->prepare(
        'SELECT visit.patient_id, patient.patient_number,
                patient.first_name, patient.last_name
         FROM visits visit
         INNER JOIN patients patient ON patient.patient_id = visit.patient_id
         WHERE visit.visit_id = :visit_id
         LIMIT 1'
    );
    $visitStatement->execute(['visit_id' => (int) $visitId]);
    $visit = $visitStatement->fetch();
    if ($visit === false) {
        jsonError('Visit not found.', [], 404);
    }

    recordPharmacyAudit(
        $database,
        (int) $user['user_id'],
        'pharmacy.dispensing_history.viewed',
        (int) $visit['patient_id'],
        (int) $visitId
    );
    jsonSuccess('Dispensing history loaded.', [
        'visit' => [
            'visit_id' => (int) $visitId,
            'patient_number' => $visit['patient_number'],
            'patient_name' => trim(
                (string) $visit['first_name'] . ' ' . (string) $visit['last_name']
            ),
        ],
        'dispensings' => pharmacyDispensingHistory($database, (int) $visitId),
    ]);
}

function handlePharmacyPrescriptionSelect(string $visitId): void
{
    if (!ctype_digit($visitId) || (int) $visitId < 1) {
        jsonError('A valid visit ID is required.', [
            'visit_id' => 'Enter a positive visit ID.',
        ], 422);
    }
    $database = getDatabaseConnection();
    $user = requirePharmacyAccess($database);
    requireCsrfRequest();
    $input = readJsonRequest();
    $prescriptionId = $input['prescription_id'] ?? null;
    if ((!is_int($prescriptionId)
            && !(is_string($prescriptionId) && ctype_digit($prescriptionId)))
        || (int) $prescriptionId < 1) {
        jsonError('A valid prescription ID is required.', [
            'prescription_id' => 'Select an active prescription.',
        ], 422);
    }

    try {
        $database->beginTransaction();
        $visit = pharmacyVisitForUpdate($database, (int) $visitId);
        if ($visit === false) {
            $database->rollBack();
            jsonError('Visit not found.', [], 404);
        }
        if ((int) $visit['medication_collection_only'] !== 1
            || $visit['status'] !== 'awaiting_pharmacy') {
            $database->rollBack();
            jsonError('Prescription selection is only available for medication-only visits.', [], 409);
        }

        $prescription = $database->prepare(
            'SELECT prescription.prescription_id, prescription.visit_id
             FROM prescriptions prescription
             INNER JOIN visits source_visit
                ON source_visit.visit_id = prescription.visit_id
             WHERE prescription.prescription_id = :prescription_id
               AND source_visit.patient_id = :patient_id
               AND source_visit.visit_id <> :visit_id
               AND source_visit.arrived_at <= :arrived_at
               AND prescription.status = \'active\'
               AND NOT EXISTS (
                    SELECT 1 FROM dispensings dispensing
                    WHERE dispensing.prescription_id = prescription.prescription_id
               )
               AND NOT EXISTS (
                    SELECT 1
                    FROM prescription_items item
                    INNER JOIN bill_items item_bill
                        ON item_bill.prescription_item_id = item.prescription_item_id
                    WHERE item.prescription_id = prescription.prescription_id
               )
             LIMIT 1
             FOR UPDATE'
        );
        $prescription->execute([
            'prescription_id' => (int) $prescriptionId,
            'patient_id' => (int) $visit['patient_id'],
            'visit_id' => (int) $visitId,
            'arrived_at' => $visit['arrived_at'],
        ]);
        if ($prescription->fetch() === false) {
            $database->rollBack();
            jsonError('This active prescription is not available for this patient visit.', [], 409);
        }

        $bill = billingGetOrCreateBill($database, (int) $visitId);
        $itemStatement = $database->prepare(
            'SELECT prescription_item_id
             FROM prescription_items
             WHERE prescription_id = :prescription_id
             ORDER BY prescription_item_id
             FOR UPDATE'
        );
        $itemStatement->execute(['prescription_id' => (int) $prescriptionId]);
        $items = $itemStatement->fetchAll();
        if ($items === []) {
            $database->rollBack();
            jsonError('The selected prescription has no medicine items.', [], 409);
        }
        foreach ($items as $item) {
            billingAddMedicationItemOnce(
                $database,
                (int) $bill['bill_id'],
                (int) $item['prescription_item_id']
            );
        }
        $billTotal = billingRefreshBillTotal($database, (int) $bill['bill_id']);
        recordPharmacyAudit(
            $database,
            (int) $user['user_id'],
            'pharmacy.medication_only.prescription_selected',
            (int) $visit['patient_id'],
            (int) $visitId
        );
        $database->commit();
    } catch (Throwable $exception) {
        if ($database->inTransaction()) {
            $database->rollBack();
        }
        error_log('Medication-only prescription selection failed: ' . $exception->getMessage());
        jsonError('Unable to select the prescription right now.', [], 500);
    }

    jsonSuccess('Prescription selected and medication charges added.', [
        'visit_id' => (int) $visitId,
        'prescription_id' => (int) $prescriptionId,
        'bill_total' => $billTotal,
    ], 201);
}

function handlePharmacyPaymentCreate(string $visitId): void
{
    if (!ctype_digit($visitId) || (int) $visitId < 1) {
        jsonError('A valid visit ID is required.', [
            'visit_id' => 'Enter a positive visit ID.',
        ], 422);
    }
    $database = getDatabaseConnection();
    $user = requirePharmacyAccess($database);
    requireCsrfRequest();
    $input = readJsonRequest();
    $paymentMethod = $input['payment_method'] ?? null;
    if (!is_string($paymentMethod)
        || !in_array($paymentMethod, ['cash', 'card', 'medical_aid'], true)) {
        jsonError('Choose a valid payment method.', [
            'payment_method' => 'Choose cash, card, or medical aid.',
        ], 422);
    }
    $amountValue = $input['amount'] ?? null;
    if ($amountValue !== null
        && ((!is_string($amountValue) && !is_int($amountValue) && !is_float($amountValue))
            || !preg_match('/^[0-9]+(?:\.[0-9]{1,2})?$/', (string) $amountValue)
            || !is_finite((float) $amountValue)
            || (float) $amountValue <= 0)) {
        jsonError('Enter a payment amount greater than zero with up to two decimals.', [
            'amount' => 'Enter a valid positive amount.',
        ], 422);
    }
    $requestId = $input['client_request_id'] ?? null;
    if (!is_string($requestId)
        || !preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $requestId
        )) {
        jsonError('A valid payment request ID is required.', [
            'client_request_id' => 'Send a UUID for this payment submission.',
        ], 422);
    }
    $schemeId = $input['medical_aid_scheme_id'] ?? null;
    $memberNumber = $input['member_number'] ?? null;
    $memberName = $input['member_name'] ?? null;
    if ($paymentMethod === 'medical_aid') {
        if ((!is_int($schemeId) && !(is_string($schemeId) && ctype_digit($schemeId)))
            || (int) $schemeId < 1
            || !is_string($memberNumber)
            || trim($memberNumber) === ''
            || strlen(trim($memberNumber)) > 100) {
            jsonError('Medical-aid scheme and member number are required.', [
                'medical_aid_scheme_id' => 'Select a medical-aid scheme.',
                'member_number' => 'Enter the member number.',
            ], 422);
        }
        if ($memberName !== null && (!is_string($memberName)
            || strlen(trim($memberName)) > 150)) {
            jsonError('Enter a valid medical-aid member name.', [
                'member_name' => 'Enter no more than 150 characters.',
            ], 422);
        }
    }
    $requestHash = hash('sha256', json_encode([
        'payment_method' => $paymentMethod,
        'amount' => $amountValue === null
            ? null
            : number_format((float) $amountValue, 2, '.', ''),
        'medical_aid_scheme_id' => $paymentMethod === 'medical_aid'
            ? (int) $schemeId
            : null,
        'member_number' => $paymentMethod === 'medical_aid'
            ? trim($memberNumber)
            : null,
        'member_name' => $paymentMethod === 'medical_aid'
            && is_string($memberName) && trim($memberName) !== ''
                ? trim($memberName)
                : null,
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

    try {
        $database->beginTransaction();
        $visit = pharmacyVisitForUpdate($database, (int) $visitId);
        if ($visit === false) {
            $database->rollBack();
            jsonError('Visit not found.', [], 404);
        }

        $existingPaymentStatement = $database->prepare(
            'SELECT payment.payment_id, payment.bill_id,
                    bill.visit_id AS payment_visit_id,
                    payment.recorded_by_user_id, payment.payment_method,
                    payment.amount, payment.status, payment.is_simulated,
                    payment.receipt_number, payment.membership_id,
                    payment.client_request_hash
             FROM payments payment
             INNER JOIN bills bill ON bill.bill_id = payment.bill_id
             WHERE payment.client_request_id = :client_request_id
             LIMIT 1
             FOR UPDATE'
        );
        $existingPaymentStatement->execute([
            'client_request_id' => $requestId,
        ]);
        $existingPayment = $existingPaymentStatement->fetch();
        if ($existingPayment !== false) {
            if ((int) $existingPayment['payment_visit_id'] !== (int) $visitId
                || !is_string($existingPayment['client_request_hash'])
                || !hash_equals($existingPayment['client_request_hash'], $requestHash)) {
                $database->rollBack();
                jsonError('This payment request ID was already used for a different payment.', [], 409);
            }
            $balance = billingGetBillBalance(
                $database,
                (int) $existingPayment['bill_id']
            );
            $database->commit();
            jsonSuccess('This payment was already recorded.', [
                'payment_id' => (int) $existingPayment['payment_id'],
                'receipt_number' => $existingPayment['receipt_number'],
                'amount' => (string) $existingPayment['amount'],
                'payment_method' => $existingPayment['payment_method'],
                'is_simulated' => (bool) $existingPayment['is_simulated'],
                'membership_id' => $existingPayment['membership_id'] === null
                    ? null
                    : (int) $existingPayment['membership_id'],
                'bill_id' => (int) $existingPayment['bill_id'],
                'bill_total' => $balance['billed_amount'],
                'approved_payments' => $balance['approved_amount'],
                'balance' => $balance['balance'],
                'bill_status' => (float) $balance['balance'] <= 0
                    ? 'paid'
                    : 'partially_paid',
                'visit_id' => (int) $visitId,
                'visit_completed' => $visit['status'] === 'completed',
                'visit_status' => $visit['status'],
                'created' => false,
            ]);
        }

        if (!in_array($visit['status'], ['awaiting_pharmacy', 'awaiting_payment'], true)) {
            $database->rollBack();
            jsonError('This visit is not awaiting pharmacy payment.', [], 409);
        }
        $bill = billingGetOrCreateBill($database, (int) $visitId);
        if (!pharmacyEnsureEmergencyCharge($database, $visit, $bill, $user)) {
            $database->rollBack();
            jsonError(
                'Emergency-care pricing or encounter completion is missing; configure the charge before accepting payment.',
                [],
                503
            );
        }
        pharmacyEnsurePrescriptionCharges($database, $visit, $bill);
        $billTotal = billingRefreshBillTotal($database, (int) $bill['bill_id']);
        $balance = billingGetBillBalance($database, (int) $bill['bill_id']);
        if ((float) $balance['balance'] <= 0) {
            $database->rollBack();
            jsonError('This visit has no outstanding balance.', [], 409);
        }

        if ((int) $visit['medication_collection_only'] === 1) {
            $selectedPrescription = $database->prepare(
                'SELECT 1
                 FROM bill_items bill_item
                 INNER JOIN prescription_items item
                    ON item.prescription_item_id = bill_item.prescription_item_id
                 INNER JOIN prescriptions prescription
                    ON prescription.prescription_id = item.prescription_id
                 WHERE bill_item.bill_id = :bill_id
                   AND prescription.status = \'active\'
                 LIMIT 1'
            );
            $selectedPrescription->execute(['bill_id' => (int) $bill['bill_id']]);
            if ($selectedPrescription->fetchColumn() === false) {
                $database->rollBack();
                jsonError('Select an active prescription before paying for medication-only collection.', [], 409);
            }
        }

        $amount = $amountValue === null
            ? (float) $balance['balance']
            : (float) $amountValue;
        if ($amount - (float) $balance['balance'] > 0.00001) {
            $database->rollBack();
            jsonError('Payment cannot exceed the outstanding bill balance.', [
                'amount' => 'Reduce the payment amount to the current balance.',
            ], 422);
        }

        $membershipId = null;
        if ($paymentMethod === 'medical_aid') {
            $scheme = $database->prepare(
                'SELECT medical_aid_scheme_id
                 FROM medical_aid_schemes
                 WHERE medical_aid_scheme_id = :scheme_id AND is_active = 1
                 LIMIT 1
                 FOR UPDATE'
            );
            $scheme->execute(['scheme_id' => (int) $schemeId]);
            if ($scheme->fetchColumn() === false) {
                $database->rollBack();
                jsonError('The selected medical-aid scheme is unavailable.', [], 422);
            }
            $membership = $database->prepare(
                'SELECT membership_id
                 FROM patient_medical_aid_memberships
                 WHERE patient_id = :patient_id
                   AND medical_aid_scheme_id = :scheme_id
                   AND member_number = :member_number
                 ORDER BY membership_id
                 LIMIT 1
                 FOR UPDATE'
            );
            $membership->execute([
                'patient_id' => (int) $visit['patient_id'],
                'scheme_id' => (int) $schemeId,
                'member_number' => trim($memberNumber),
            ]);
            $membershipId = $membership->fetchColumn();
            if ($membershipId === false) {
                $memberName = is_string($memberName) && trim($memberName) !== ''
                    ? trim($memberName)
                    : trim(
                    (string) $visit['first_name'] . ' ' . (string) $visit['last_name']
                );
                $membershipInsert = $database->prepare(
                    'INSERT INTO patient_medical_aid_memberships
                        (patient_id, medical_aid_scheme_id, member_number,
                         member_name, is_active)
                     VALUES
                        (:patient_id, :scheme_id, :member_number, :member_name, 1)'
                );
                $membershipInsert->execute([
                    'patient_id' => (int) $visit['patient_id'],
                    'scheme_id' => (int) $schemeId,
                    'member_number' => trim($memberNumber),
                    'member_name' => $memberName,
                ]);
                $membershipId = (int) $database->lastInsertId();
            } else {
                $membershipId = (int) $membershipId;
                $database->prepare(
                    'UPDATE patient_medical_aid_memberships
                     SET is_active = 1
                     WHERE membership_id = :membership_id'
                )->execute(['membership_id' => $membershipId]);
            }
        }

        $receiptNumber = billingGenerateReceiptNumber($database);
        $paymentInsert = $database->prepare(
            'INSERT INTO payments
                (bill_id, recorded_by_user_id, membership_id, payment_method,
                     amount, status, receipt_number, is_simulated,
                     client_request_id, client_request_hash, paid_at)
                 VALUES
                    (:bill_id, :user_id, :membership_id, :payment_method,
                     :amount, \'approved\', :receipt_number, :is_simulated,
                     :client_request_id, :client_request_hash, CURRENT_TIMESTAMP)'
        );
        $paymentInsert->execute([
            'bill_id' => (int) $bill['bill_id'],
            'user_id' => (int) $user['user_id'],
            'membership_id' => $membershipId,
            'payment_method' => $paymentMethod,
            'amount' => number_format($amount, 2, '.', ''),
            'receipt_number' => $receiptNumber,
            'is_simulated' => $paymentMethod === 'cash' ? 0 : 1,
            'client_request_id' => $requestId,
            'client_request_hash' => $requestHash,
        ]);
        $paymentId = (int) $database->lastInsertId();
        $balance = billingGetBillBalance($database, (int) $bill['bill_id']);
        $billStatus = (float) $balance['balance'] <= 0 ? 'paid' : 'partially_paid';
        $database->prepare(
            'UPDATE bills SET status = :status WHERE bill_id = :bill_id'
        )->execute([
            'status' => $billStatus,
            'bill_id' => (int) $bill['bill_id'],
        ]);
        $visitCompleted = pharmacyUpdateVisitCompletion(
            $database,
            (int) $visitId,
            (int) $bill['bill_id'],
            $balance,
            (bool) $visit['medication_collection_only']
        );
        recordPharmacyAudit(
            $database,
            (int) $user['user_id'],
            'pharmacy.payment.recorded',
            (int) $visit['patient_id'],
            (int) $visitId
        );
        if ($paymentMethod === 'medical_aid') {
            recordPharmacyAudit(
                $database,
                (int) $user['user_id'],
                'pharmacy.medical_aid.approved.simulated',
                (int) $visit['patient_id'],
                (int) $visitId
            );
        }
        $database->commit();
    } catch (Throwable $exception) {
        if ($database->inTransaction()) {
            $database->rollBack();
        }
        error_log('Pharmacy payment recording failed: ' . $exception->getMessage());
        jsonError('Unable to record the pharmacy payment right now.', [], 500);
    }

    jsonSuccess('Payment recorded and approved.', [
        'payment_id' => $paymentId,
        'receipt_number' => $receiptNumber,
        'amount' => number_format($amount, 2, '.', ''),
        'payment_method' => $paymentMethod,
        'is_simulated' => $paymentMethod !== 'cash',
        'membership_id' => $membershipId,
        'bill_id' => (int) $bill['bill_id'],
        'bill_total' => $balance['billed_amount'],
        'approved_payments' => $balance['approved_amount'],
        'balance' => $balance['balance'],
        'bill_status' => $billStatus,
        'visit_id' => (int) $visitId,
        'visit_completed' => $visitCompleted,
        'visit_status' => $visitCompleted
            ? 'completed'
            : $visit['status'],
        'created' => true,
    ], 201);
}

function handlePharmacyDispense(string $visitId): void
{
    if (!ctype_digit($visitId) || (int) $visitId < 1) {
        jsonError('A valid visit ID is required.', [
            'visit_id' => 'Enter a positive visit ID.',
        ], 422);
    }
    $database = getDatabaseConnection();
    $user = requirePharmacyAccess($database);
    requireCsrfRequest();
    $input = readJsonRequest();
    $dispensingNotes = $input['notes'] ?? '';
    if (!is_string($dispensingNotes) || strlen($dispensingNotes) > 5000) {
        jsonError('Dispensing notes must be 5,000 characters or fewer.', [
            'notes' => 'Shorten the dispensing notes.',
        ], 422);
    }
    $dispensingNotes = trim($dispensingNotes);

    try {
        $database->beginTransaction();
        $visit = pharmacyVisitForUpdate($database, (int) $visitId);
        if ($visit === false) {
            $database->rollBack();
            jsonError('Visit not found.', [], 404);
        }
        if (!in_array($visit['status'], ['awaiting_pharmacy', 'awaiting_payment'], true)) {
            $database->rollBack();
            jsonError('This visit is not awaiting pharmacy dispensing.', [], 409);
        }
        $bill = billingGetOrCreateBill($database, (int) $visitId);
        pharmacyEnsurePrescriptionCharges($database, $visit, $bill);
        billingRefreshBillTotal($database, (int) $bill['bill_id']);
        $balance = billingGetBillBalance($database, (int) $bill['bill_id']);
        if ((float) $balance['balance'] > 0) {
            $database->rollBack();
            jsonError('The bill must be fully covered by approved payments before dispensing.', [], 409);
        }

        $prescriptionStatement = $database->prepare(
            'SELECT prescription.prescription_id,
                    prescription.pharmacy_verified_at,
                    prescription.pharmacy_verified_by_user_id
             FROM prescriptions prescription
             WHERE prescription.status = \'active\'
               AND NOT EXISTS (
                    SELECT 1 FROM dispensings existing_dispensing
                    WHERE existing_dispensing.prescription_id =
                          prescription.prescription_id
               )
               AND (
                    prescription.visit_id = :visit_id
                    OR EXISTS (
                        SELECT 1
                        FROM prescription_items item
                        INNER JOIN bill_items bill_item
                            ON bill_item.prescription_item_id =
                               item.prescription_item_id
                        WHERE item.prescription_id = prescription.prescription_id
                          AND bill_item.bill_id = :bill_id
                    )
               )
             ORDER BY prescription.prescription_id
             FOR UPDATE'
        );
        $prescriptionStatement->execute([
            'visit_id' => (int) $visitId,
            'bill_id' => (int) $bill['bill_id'],
        ]);
        $prescriptionIds = array_map(
            static fn (array $row): int => (int) $row['prescription_id'],
            $prescriptionStatement->fetchAll()
        );
        if ($prescriptionIds === []) {
            $database->rollBack();
            jsonError('There is no active prescription to dispense.', [], 409);
        }
        $unverified = $database->prepare(
            'SELECT prescription_id
             FROM prescriptions
             WHERE prescription_id IN (' .
                implode(',', array_fill(0, count($prescriptionIds), '?')) . ')
               AND (pharmacy_verified_at IS NULL
                    OR pharmacy_verified_by_user_id IS NULL)
             LIMIT 1'
        );
        $unverified->execute($prescriptionIds);
        if ($unverified->fetchColumn() !== false) {
            $database->rollBack();
            jsonError(
                'Verify every active prescription before dispensing.',
                ['prescription' => 'Open each prescription and record pharmacy verification.'],
                409
            );
        }

        $placeholders = implode(',', array_fill(0, count($prescriptionIds), '?'));
        $itemStatement = $database->prepare(
            'SELECT item.prescription_item_id, item.prescription_id,
                    item.medicine_id, item.quantity, medicine.medicine_name,
                    medicine.unit_price, medicine.quantity_in_stock,
                    medicine.is_active, medicine.expiry_date
             FROM prescription_items item
             INNER JOIN medicines medicine ON medicine.medicine_id = item.medicine_id
             WHERE item.prescription_id IN (' . $placeholders . ')
             ORDER BY item.medicine_id, item.prescription_item_id
             FOR UPDATE'
        );
        $itemStatement->execute($prescriptionIds);
        $items = $itemStatement->fetchAll();
        if ($items === []) {
            $database->rollBack();
            jsonError('The active prescription has no medicine items.', [], 409);
        }
        $requiredStock = [];
        $medicineNames = [];
        $availableStock = [];
        foreach ($items as $item) {
            $medicineId = (int) $item['medicine_id'];
            $requiredStock[$medicineId] = ($requiredStock[$medicineId] ?? 0)
                + (float) $item['quantity'];
            $medicineNames[$medicineId] = $item['medicine_name'];
            $availableStock[$medicineId] = (float) $item['quantity_in_stock'];
            if (!(bool) $item['is_active']) {
                $database->rollBack();
                jsonError(
                    'Insufficient stock for ' . $item['medicine_name'] . '.',
                    ['medicine_id' => 'Restock the medicine before dispensing.'],
                    409
                );
            }
            if ($item['expiry_date'] !== null
                && $item['expiry_date'] < date('Y-m-d')) {
                $database->rollBack();
                jsonError(
                    $item['medicine_name'] . ' is expired and cannot be dispensed.',
                    ['medicine_id' => 'Remove expired stock and contact the prescriber.'],
                    409
                );
            }
        }
        foreach ($requiredStock as $medicineId => $requiredQuantity) {
            if ($availableStock[$medicineId] < $requiredQuantity) {
                $database->rollBack();
                jsonError(
                    'Insufficient stock for ' . $medicineNames[$medicineId] . '.',
                    ['medicine_id' => 'Restock the medicine before dispensing.'],
                    409
                );
            }
        }

        $paymentStatement = $database->prepare(
            'SELECT payment_id
             FROM payments
             WHERE bill_id = :bill_id AND status = \'approved\'
             ORDER BY paid_at DESC, payment_id DESC
             LIMIT 1'
        );
        $paymentStatement->execute(['bill_id' => (int) $bill['bill_id']]);
        $paymentId = $paymentStatement->fetchColumn();
        $paymentId = $paymentId === false ? null : (int) $paymentId;

        $dispensingInsert = $database->prepare(
            'INSERT INTO dispensings
                (visit_id, prescription_id, pharmacist_user_id, payment_id,
                 dispensing_notes)
             VALUES
                (:visit_id, :prescription_id, :pharmacist_user_id, :payment_id,
                 :dispensing_notes)'
        );
        $dispensingItemInsert = $database->prepare(
            'INSERT INTO dispensing_items
                (dispensing_id, prescription_item_id, medicine_id,
                 quantity_dispensed, unit_price)
             VALUES
                (:dispensing_id, :prescription_item_id, :medicine_id,
                 :quantity_dispensed, :unit_price)'
        );
        $stockUpdate = $database->prepare(
            'UPDATE medicines
             SET quantity_in_stock = quantity_in_stock - :quantity
             WHERE medicine_id = :medicine_id
               AND is_active = 1
               AND quantity_in_stock >= :required_quantity'
        );
        $dispensingIds = [];
        $dispensingByPrescription = [];
        foreach ($prescriptionIds as $prescriptionId) {
            $dispensingInsert->execute([
                'visit_id' => (int) $visitId,
                'prescription_id' => $prescriptionId,
                'pharmacist_user_id' => (int) $user['user_id'],
                'payment_id' => $paymentId,
                'dispensing_notes' => $dispensingNotes === '' ? null : $dispensingNotes,
            ]);
            $dispensingId = (int) $database->lastInsertId();
            $dispensingIds[] = $dispensingId;
            $dispensingByPrescription[$prescriptionId] = $dispensingId;
        }
        foreach ($items as $item) {
            $dispensingId = $dispensingByPrescription[(int) $item['prescription_id']];
            $dispensingItemInsert->execute([
                'dispensing_id' => $dispensingId,
                'prescription_item_id' => (int) $item['prescription_item_id'],
                'medicine_id' => (int) $item['medicine_id'],
                'quantity_dispensed' => $item['quantity'],
                'unit_price' => $item['unit_price'],
            ]);
            $stockUpdate->execute([
                'quantity' => $item['quantity'],
                'medicine_id' => (int) $item['medicine_id'],
                'required_quantity' => $item['quantity'],
            ]);
            if ($stockUpdate->rowCount() !== 1) {
                throw new RuntimeException('Medicine stock changed during dispensing.');
            }
            $movement = $database->prepare(
                'INSERT INTO pharmacy_stock_movements
                    (medicine_id, recorded_by_user_id, movement_type,
                     quantity_change, stock_after, dispensing_id, notes)
                 SELECT :medicine_id, :user_id, \'dispensed\', :quantity_change,
                        medicine.quantity_in_stock, :dispensing_id, :notes
                 FROM medicines medicine
                 WHERE medicine.medicine_id = :stock_medicine_id'
            );
            $movement->execute([
                'medicine_id' => (int) $item['medicine_id'],
                'user_id' => (int) $user['user_id'],
                'quantity_change' => number_format(
                    -(float) $item['quantity'],
                    2,
                    '.',
                    ''
                ),
                'dispensing_id' => $dispensingId,
                'notes' => $dispensingNotes === '' ? null : $dispensingNotes,
                'stock_medicine_id' => (int) $item['medicine_id'],
            ]);
        }

        $prescriptionUpdate = $database->prepare(
            'UPDATE prescriptions
             SET status = \'completed\'
             WHERE prescription_id = :prescription_id AND status = \'active\''
        );
        foreach ($prescriptionIds as $prescriptionId) {
            $prescriptionUpdate->execute(['prescription_id' => $prescriptionId]);
            if ($prescriptionUpdate->rowCount() !== 1) {
                throw new RuntimeException('Prescription status changed during dispensing.');
            }
        }
        $billUpdate = $database->prepare(
            'UPDATE bills SET status = \'paid\' WHERE bill_id = :bill_id'
        );
        $billUpdate->execute(['bill_id' => (int) $bill['bill_id']]);
        $visitUpdate = $database->prepare(
            'UPDATE visits
             SET status = \'completed\'
             WHERE visit_id = :visit_id
               AND status IN (\'awaiting_pharmacy\', \'awaiting_payment\')'
        );
        $visitUpdate->execute(['visit_id' => (int) $visitId]);
        if ($visitUpdate->rowCount() !== 1) {
            $database->rollBack();
            jsonError('The visit could not be completed after dispensing.', [], 409);
        }
        recordPharmacyAudit(
            $database,
            (int) $user['user_id'],
            'pharmacy.medication.dispensed',
            (int) $visit['patient_id'],
            (int) $visitId
        );
        $database->commit();
    } catch (Throwable $exception) {
        if ($database->inTransaction()) {
            $database->rollBack();
        }
        error_log('Pharmacy dispensing failed: ' . $exception->getMessage());
        jsonError('Unable to dispense the prescription right now.', [], 500);
    }

    jsonSuccess('Medication dispensed and visit completed.', [
        'visit_id' => (int) $visitId,
        'visit_status' => 'completed',
        'prescription_ids' => $prescriptionIds,
        'dispensing_ids' => $dispensingIds,
        'dispensed_item_count' => count($items),
        'payment_id' => $paymentId,
    ], 201);
}

function handlePharmacyReceiptGet(string $paymentId): void
{
    if (!ctype_digit($paymentId) || (int) $paymentId < 1) {
        jsonError('A valid payment ID is required.', [
            'payment_id' => 'Enter a positive payment ID.',
        ], 422);
    }
    $database = getDatabaseConnection();
    $user = requirePharmacyAccess($database);
    $statement = $database->prepare(
        'SELECT payment.payment_id, payment.payment_method, payment.amount,
                payment.status AS payment_status, payment.is_simulated,
                payment.receipt_number, payment.paid_at, payment.created_at,
                bill.bill_id, bill.bill_number, bill.total_amount,
                visit.visit_id, visit.patient_id,
                patient.patient_number, patient.first_name, patient.last_name
         FROM payments payment
         INNER JOIN bills bill ON bill.bill_id = payment.bill_id
         INNER JOIN visits visit ON visit.visit_id = bill.visit_id
         INNER JOIN patients patient ON patient.patient_id = visit.patient_id
         WHERE payment.payment_id = :payment_id
         LIMIT 1'
    );
    $statement->execute(['payment_id' => (int) $paymentId]);
    $payment = $statement->fetch();
    if ($payment === false) {
        jsonError('Payment receipt not found.', [], 404);
    }
    recordPharmacyAudit(
        $database,
        (int) $user['user_id'],
        'pharmacy.receipt.viewed',
        (int) $payment['patient_id'],
        (int) $payment['visit_id']
    );
    $items = $database->prepare(
        'SELECT charge_type, description, quantity, unit_amount,
                CAST(quantity * unit_amount AS DECIMAL(12,2)) AS line_total
         FROM bill_items
         WHERE bill_id = :bill_id
         ORDER BY bill_item_id'
    );
    $items->execute(['bill_id' => (int) $payment['bill_id']]);
    $balance = billingGetBillBalance($database, (int) $payment['bill_id']);
    $priorPaymentStatement = $database->prepare(
        'SELECT payment_id, payment_method, amount, status, is_simulated,
                receipt_number, paid_at
         FROM payments
         WHERE bill_id = :bill_id
           AND (
                created_at < :created_at
                OR (created_at = :created_at AND payment_id < :payment_id)
           )
         ORDER BY created_at, payment_id'
    );
    $priorPaymentStatement->execute([
        'bill_id' => (int) $payment['bill_id'],
        'created_at' => $payment['created_at'],
        'payment_id' => (int) $payment['payment_id'],
    ]);

    jsonSuccess('Payment receipt loaded.', [
        'payment' => [
            'payment_id' => (int) $payment['payment_id'],
            'payment_method' => $payment['payment_method'],
            'amount' => (string) $payment['amount'],
            'status' => $payment['payment_status'],
            'is_simulated' => (bool) $payment['is_simulated'],
            'receipt_number' => $payment['receipt_number'],
            'paid_at' => $payment['paid_at'],
        ],
        'bill' => [
            'bill_id' => (int) $payment['bill_id'],
            'bill_number' => $payment['bill_number'],
            'total_amount' => (string) $payment['total_amount'],
            'approved_payments' => $balance['approved_amount'],
            'remaining_balance' => $balance['balance'],
            'items' => $items->fetchAll(),
        ],
        'prior_payments' => array_map(static function (array $prior): array {
            return [
                'payment_id' => (int) $prior['payment_id'],
                'payment_method' => $prior['payment_method'],
                'amount' => (string) $prior['amount'],
                'status' => $prior['status'],
                'is_simulated' => (bool) $prior['is_simulated'],
                'receipt_number' => $prior['receipt_number'],
                'paid_at' => $prior['paid_at'],
            ];
        }, $priorPaymentStatement->fetchAll()),
        'visit' => [
            'visit_id' => (int) $payment['visit_id'],
            'patient_number' => $payment['patient_number'],
            'patient_name' => trim(
                (string) $payment['first_name'] . ' ' . (string) $payment['last_name']
            ),
        ],
    ]);
}

function handlePharmacyDashboardGet(): void
{
    $database = getDatabaseConnection();
    $user = requirePharmacyAccess($database);
    $counts = $database->query(
        'SELECT
            (SELECT COUNT(*)
             FROM prescriptions prescription
             WHERE prescription.status = \'active\'
               AND NOT EXISTS (
                    SELECT 1 FROM dispensings dispensing
                    WHERE dispensing.prescription_id = prescription.prescription_id
               )) AS pending_prescriptions,
            (SELECT COUNT(*)
             FROM visits
             WHERE status IN (\'awaiting_pharmacy\', \'awaiting_payment\'))
                AS pharmacy_visits,
            (SELECT COUNT(*)
             FROM medicines
             WHERE is_active = 1 AND quantity_in_stock <= reorder_level)
                AS low_stock_medicines,
            (SELECT COUNT(*)
             FROM medicines
             WHERE is_active = 1 AND expiry_date < CURRENT_DATE)
                AS expired_medicines,
            (SELECT COUNT(*)
             FROM dispensings
             WHERE DATE(dispensed_at) = CURRENT_DATE) AS dispensed_today'
    )->fetch();
    recordPharmacyAudit(
        $database,
        (int) $user['user_id'],
        'pharmacy.dashboard.viewed',
        null,
        null
    );
    jsonSuccess('Pharmacy dashboard loaded.', [
        'pending' => (int) $counts['pending_prescriptions'],
        'visits' => (int) $counts['pharmacy_visits'],
        'low_stock' => (int) $counts['low_stock_medicines'],
        'expired' => (int) $counts['expired_medicines'],
        'dispensed_today' => (int) $counts['dispensed_today'],
    ]);
}

function handlePharmacyPrescriptionsGet(string $search, string $scope): void
{
    $database = getDatabaseConnection();
    $user = requirePharmacyAccess($database);
    $search = trim($search);
    if (strlen($search) > 100
        || !in_array($scope, ['all', 'pending', 'patients', 'history'], true)) {
        jsonError('Invalid prescription search filters.', [], 422);
    }

    $filters = '';
    $parameters = [];
    if ($scope === 'pending' || $scope === 'patients') {
        $filters .= ' AND prescription.status = \'active\'
            AND NOT EXISTS (
                SELECT 1 FROM dispensings existing_dispensing
                WHERE existing_dispensing.prescription_id =
                      prescription.prescription_id
            )';
    } elseif ($scope === 'all') {
        $filters .= ' AND prescription.status IN (\'active\', \'draft\')';
    }
    if ($search !== '') {
        $filters .= ' AND (
            patient.patient_number LIKE :patient_number
            OR patient.first_name LIKE :first_name
            OR patient.last_name LIKE :last_name
            OR CONCAT_WS(\' \', patient.first_name, patient.last_name)
                LIKE :full_name
            OR CAST(visit.visit_id AS CHAR) LIKE :visit_id
            OR CAST(prescription.prescription_id AS CHAR)
                LIKE :prescription_id
        )';
        $pattern = '%' . $search . '%';
        $parameters = [
            'patient_number' => $pattern,
            'first_name' => $pattern,
            'last_name' => $pattern,
            'full_name' => $pattern,
            'visit_id' => $pattern,
            'prescription_id' => $pattern,
        ];
    }

    $statement = $database->prepare(
        'SELECT prescription.prescription_id, prescription.visit_id,
                prescription.status, prescription.created_at,
                prescription.pharmacy_verified_at,
                prescriber.display_name AS prescribed_by,
                visit.patient_id, visit.status AS visit_status,
                visit.arrived_at, patient.patient_number,
                patient.first_name, patient.last_name,
                COUNT(item.prescription_item_id) AS medicine_count,
                GROUP_CONCAT(DISTINCT medicine.medicine_name
                    ORDER BY medicine.medicine_name SEPARATOR \', \')
                    AS medicine_names,
                EXISTS (
                    SELECT 1 FROM dispensings dispensing
                    WHERE dispensing.prescription_id =
                          prescription.prescription_id
                ) AS is_dispensed,
                COALESCE((
                    SELECT SUM(bill_item.quantity * bill_item.unit_amount)
                    FROM bills bill
                    INNER JOIN bill_items bill_item
                        ON bill_item.bill_id = bill.bill_id
                    LEFT JOIN prescription_items charged_item
                        ON charged_item.prescription_item_id =
                           bill_item.prescription_item_id
                    WHERE bill.visit_id = visit.visit_id
                      AND bill_item.is_voided = 0
                      AND (
                        bill_item.prescription_item_id IS NULL
                        OR charged_item.prescription_id =
                           prescription.prescription_id
                      )
                ), 0.00) AS billed_amount,
                COALESCE((
                    SELECT SUM(payment.amount)
                    FROM payments payment
                    INNER JOIN bills payment_bill
                        ON payment_bill.bill_id = payment.bill_id
                    WHERE payment_bill.visit_id = visit.visit_id
                      AND payment.status = \'approved\'
                ), 0.00) AS approved_amount
         FROM prescriptions prescription
         INNER JOIN visits visit ON visit.visit_id = prescription.visit_id
         INNER JOIN patients patient ON patient.patient_id = visit.patient_id
         INNER JOIN users prescriber
            ON prescriber.user_id = prescription.prescribed_by_user_id
         LEFT JOIN prescription_items item
            ON item.prescription_id = prescription.prescription_id
         LEFT JOIN medicines medicine ON medicine.medicine_id = item.medicine_id
         WHERE 1 = 1 ' . $filters . '
         GROUP BY prescription.prescription_id, prescription.visit_id,
                  prescription.status, prescription.created_at,
                  prescription.pharmacy_verified_at, prescriber.display_name,
                  visit.patient_id, visit.status, visit.arrived_at,
                  patient.patient_number, patient.first_name, patient.last_name
         ORDER BY prescription.created_at DESC, prescription.prescription_id DESC
         LIMIT 250'
    );
    $statement->execute($parameters);
    $prescriptions = [];
    foreach ($statement->fetchAll() as $row) {
        $prescriptions[] = [
            'prescription_id' => (int) $row['prescription_id'],
            'visit_id' => (int) $row['visit_id'],
            'patient_id' => (int) $row['patient_id'],
            'patient_number' => $row['patient_number'],
            'patient_name' => trim(
                (string) $row['first_name'] . ' ' . (string) $row['last_name']
            ),
            'visit_status' => $row['visit_status'],
            'prescription_status' => $row['status'],
            'prescribed_by' => $row['prescribed_by'],
            'prescription_date' => $row['created_at'],
            'arrived_at' => $row['arrived_at'],
            'medicine_count' => (int) $row['medicine_count'],
            'medicine_names' => $row['medicine_names'] ?? '',
            'is_verified' => $row['pharmacy_verified_at'] !== null,
            'is_dispensed' => (bool) $row['is_dispensed'],
            'balance' => number_format(
                max(0, (float) $row['billed_amount'] - (float) $row['approved_amount']),
                2,
                '.',
                ''
            ),
        ];
        recordPharmacyAudit(
            $database,
            (int) $user['user_id'],
            'pharmacy.prescriptions.viewed',
            (int) $row['patient_id'],
            (int) $row['visit_id']
        );
    }
    jsonSuccess('Pharmacy prescriptions loaded.', [
        'prescriptions' => $prescriptions,
        'count' => count($prescriptions),
    ]);
}

function handlePharmacyPrescriptionDetailGet(string $prescriptionId): void
{
    if (!ctype_digit($prescriptionId) || (int) $prescriptionId < 1) {
        jsonError('A valid prescription ID is required.', [], 422);
    }
    $database = getDatabaseConnection();
    $user = requirePharmacyAccess($database);
    $statement = $database->prepare(
        'SELECT prescription.prescription_id, prescription.visit_id,
                prescription.status, prescription.created_at,
                prescription.pharmacy_verified_at,
                verifier.display_name AS verified_by,
                prescriber.display_name AS prescribed_by,
                visit.patient_id, visit.status AS visit_status,
                visit.arrived_at, patient.patient_number,
                patient.first_name, patient.last_name
         FROM prescriptions prescription
         INNER JOIN visits visit ON visit.visit_id = prescription.visit_id
         INNER JOIN patients patient ON patient.patient_id = visit.patient_id
         INNER JOIN users prescriber
            ON prescriber.user_id = prescription.prescribed_by_user_id
         LEFT JOIN users verifier
            ON verifier.user_id = prescription.pharmacy_verified_by_user_id
         WHERE prescription.prescription_id = :prescription_id
         LIMIT 1'
    );
    $statement->execute(['prescription_id' => (int) $prescriptionId]);
    $row = $statement->fetch();
    if ($row === false) {
        jsonError('Prescription not found.', [], 404);
    }
    $itemStatement = $database->prepare(
        'SELECT item.prescription_item_id, item.medicine_id,
                medicine.medicine_name, item.dosage, item.frequency,
                item.duration, item.quantity, item.instructions,
                medicine.quantity_in_stock, medicine.expiry_date,
                medicine.is_active,
                EXISTS (
                    SELECT 1 FROM dispensing_items dispensed_item
                    WHERE dispensed_item.prescription_item_id =
                          item.prescription_item_id
                ) AS is_dispensed
         FROM prescription_items item
         INNER JOIN medicines medicine ON medicine.medicine_id = item.medicine_id
         WHERE item.prescription_id = :prescription_id
         ORDER BY item.prescription_item_id'
    );
    $itemStatement->execute(['prescription_id' => (int) $prescriptionId]);
    $items = array_map(static function (array $item): array {
        return [
            'prescription_item_id' => (int) $item['prescription_item_id'],
            'medicine_id' => (int) $item['medicine_id'],
            'medicine_name' => $item['medicine_name'],
            'dosage' => $item['dosage'],
            'frequency' => $item['frequency'],
            'duration' => $item['duration'],
            'quantity' => (string) $item['quantity'],
            'instructions' => $item['instructions'],
            'stock_available' => (string) $item['quantity_in_stock'],
            'expiry_date' => $item['expiry_date'],
            'medicine_active' => (bool) $item['is_active'],
            'is_dispensed' => (bool) $item['is_dispensed'],
        ];
    }, $itemStatement->fetchAll());
    $exceptionStatement = $database->prepare(
        'SELECT exception.exception_id, exception.prescription_item_id,
                exception.status, exception.reason, exception.created_at,
                recorder.display_name AS recorded_by
         FROM pharmacy_prescription_exceptions exception
         INNER JOIN prescription_items item
            ON item.prescription_item_id = exception.prescription_item_id
         INNER JOIN users recorder
            ON recorder.user_id = exception.recorded_by_user_id
         WHERE item.prescription_id = :prescription_id
         ORDER BY exception.created_at DESC, exception.exception_id DESC'
    );
    $exceptionStatement->execute(['prescription_id' => (int) $prescriptionId]);
    $exceptions = array_map(static function (array $exception): array {
        return [
            'exception_id' => (int) $exception['exception_id'],
            'prescription_item_id' => (int) $exception['prescription_item_id'],
            'status' => $exception['status'],
            'reason' => $exception['reason'],
            'created_at' => $exception['created_at'],
            'recorded_by' => $exception['recorded_by'],
        ];
    }, $exceptionStatement->fetchAll());
    recordPharmacyAudit(
        $database,
        (int) $user['user_id'],
        'pharmacy.prescription.viewed',
        (int) $row['patient_id'],
        (int) $row['visit_id']
    );
    jsonSuccess('Prescription details loaded.', [
        'prescription' => [
            'prescription_id' => (int) $row['prescription_id'],
            'visit_id' => (int) $row['visit_id'],
            'patient_id' => (int) $row['patient_id'],
            'patient_number' => $row['patient_number'],
            'patient_name' => trim(
                (string) $row['first_name'] . ' ' . (string) $row['last_name']
            ),
            'visit_status' => $row['visit_status'],
            'prescription_status' => $row['status'],
            'prescribed_by' => $row['prescribed_by'],
            'prescription_date' => $row['created_at'],
            'arrived_at' => $row['arrived_at'],
            'is_verified' => $row['pharmacy_verified_at'] !== null,
            'verified_by' => $row['verified_by'],
            'verified_at' => $row['pharmacy_verified_at'],
            'items' => $items,
            'exceptions' => $exceptions,
        ],
    ]);
}

function handlePharmacyPrescriptionVerify(string $prescriptionId): void
{
    if (!ctype_digit($prescriptionId) || (int) $prescriptionId < 1) {
        jsonError('A valid prescription ID is required.', [], 422);
    }
    $database = getDatabaseConnection();
    $user = requirePharmacyAccess($database);
    requireCsrfRequest();
    try {
        $database->beginTransaction();
        $statement = $database->prepare(
            'SELECT prescription.visit_id, prescription.status,
                    visit.patient_id
             FROM prescriptions prescription
             INNER JOIN visits visit ON visit.visit_id = prescription.visit_id
             WHERE prescription.prescription_id = :prescription_id
             LIMIT 1 FOR UPDATE'
        );
        $statement->execute(['prescription_id' => (int) $prescriptionId]);
        $prescription = $statement->fetch();
        if ($prescription === false) {
            $database->rollBack();
            jsonError('Prescription not found.', [], 404);
        }
        if ($prescription['status'] !== 'active') {
            $database->rollBack();
            jsonError('Only active prescriptions can be verified.', [], 409);
        }
        $update = $database->prepare(
            'UPDATE prescriptions
             SET pharmacy_verified_by_user_id = :user_id,
                 pharmacy_verified_at = CURRENT_TIMESTAMP
             WHERE prescription_id = :prescription_id
               AND status = \'active\''
        );
        $update->execute([
            'user_id' => (int) $user['user_id'],
            'prescription_id' => (int) $prescriptionId,
        ]);
        if ($update->rowCount() !== 1) {
            $database->rollBack();
            jsonError('Prescription verification could not be saved.', [], 409);
        }
        recordPharmacyAudit(
            $database,
            (int) $user['user_id'],
            'pharmacy.prescription.verified',
            (int) $prescription['patient_id'],
            (int) $prescription['visit_id']
        );
        $database->commit();
    } catch (Throwable $exception) {
        if ($database->inTransaction()) {
            $database->rollBack();
        }
        error_log('Pharmacy prescription verification failed: ' . $exception->getMessage());
        jsonError('Unable to verify this prescription right now.', [], 500);
    }
    jsonSuccess('Prescription verified for dispensing.', [
        'prescription_id' => (int) $prescriptionId,
        'verified_by' => (int) $user['user_id'],
    ]);
}

function handlePharmacyExceptionCreate(string $prescriptionId): void
{
    if (!ctype_digit($prescriptionId) || (int) $prescriptionId < 1) {
        jsonError('A valid prescription ID is required.', [], 422);
    }
    $database = getDatabaseConnection();
    $user = requirePharmacyAccess($database);
    requireCsrfRequest();
    $input = readJsonRequest();
    $itemId = $input['prescription_item_id'] ?? null;
    $reason = $input['reason'] ?? null;
    if ((!is_int($itemId)
            && !(is_string($itemId) && ctype_digit($itemId)))
        || (int) $itemId < 1
        || !is_string($reason)
        || trim($reason) === ''
        || strlen($reason) > 5000) {
        jsonError('Select a prescription item and enter an exception reason.', [
            'reason' => 'A reason of at most 5,000 characters is required.',
        ], 422);
    }
    $statement = $database->prepare(
        'SELECT visit.patient_id, prescription.visit_id, prescription.status
         FROM prescriptions prescription
         INNER JOIN visits visit ON visit.visit_id = prescription.visit_id
         INNER JOIN prescription_items item
            ON item.prescription_id = prescription.prescription_id
         WHERE prescription.prescription_id = :prescription_id
           AND item.prescription_item_id = :item_id
         LIMIT 1'
    );
    $statement->execute([
        'prescription_id' => (int) $prescriptionId,
        'item_id' => (int) $itemId,
    ]);
    $prescription = $statement->fetch();
    if ($prescription === false) {
        jsonError('The selected item does not belong to this prescription.', [], 404);
    }
    if ($prescription['status'] !== 'active') {
        jsonError('Exceptions can only be recorded on active prescriptions.', [], 409);
    }
    try {
        $insert = $database->prepare(
            'INSERT INTO pharmacy_prescription_exceptions
                (prescription_item_id, recorded_by_user_id, reason)
             VALUES (:item_id, :user_id, :reason)'
        );
        $insert->execute([
            'item_id' => (int) $itemId,
            'user_id' => (int) $user['user_id'],
            'reason' => trim($reason),
        ]);
        recordPharmacyAudit(
            $database,
            (int) $user['user_id'],
            'pharmacy.prescription.exception_recorded',
            (int) $prescription['patient_id'],
            (int) $prescription['visit_id']
        );
    } catch (Throwable $exception) {
        error_log('Pharmacy prescription exception could not be saved: ' . $exception->getMessage());
        jsonError('Unable to record the prescription exception right now.', [], 500);
    }
    jsonSuccess('Pharmacy exception recorded.', [
        'exception_id' => (int) $database->lastInsertId(),
    ], 201);
}

function handlePharmacyPrescriptionCancel(string $prescriptionId): void
{
    if (!ctype_digit($prescriptionId) || (int) $prescriptionId < 1) {
        jsonError('A valid prescription ID is required.', [], 422);
    }
    $database = getDatabaseConnection();
    $user = getAuthenticatedUser($database);
    if ($user === null) {
        jsonError('Authentication is required.', [], 401);
    }
    if (!in_array($user['role_name'], [
        'general_doctor',
        'resident_specialist',
        'locum_specialist',
        'emergency_doctor',
        'system_administrator',
    ], true)) {
        jsonError('Only an authorized prescriber may cancel a prescription.', [], 403);
    }
    requireCsrfRequest();
    $input = readJsonRequest();
    $reason = $input['reason'] ?? null;
    if (!is_string($reason) || trim($reason) === '' || strlen($reason) > 5000) {
        jsonError('A cancellation reason is required.', [
            'reason' => 'Enter a reason of at most 5,000 characters.',
        ], 422);
    }
    try {
        $database->beginTransaction();
        $statement = $database->prepare(
            'SELECT prescription.visit_id, prescription.status,
                    prescription.prescribed_by_user_id, visit.patient_id
             FROM prescriptions prescription
             INNER JOIN visits visit ON visit.visit_id = prescription.visit_id
             WHERE prescription.prescription_id = :prescription_id
             LIMIT 1 FOR UPDATE'
        );
        $statement->execute(['prescription_id' => (int) $prescriptionId]);
        $prescription = $statement->fetch();
        if ($prescription === false) {
            $database->rollBack();
            jsonError('Prescription not found.', [], 404);
        }
        if ($user['role_name'] !== 'system_administrator'
            && (int) $prescription['prescribed_by_user_id'] !== (int) $user['user_id']) {
            $database->rollBack();
            jsonError('Only the prescribing clinician may cancel this prescription.', [], 403);
        }
        if (!in_array($prescription['status'], ['active', 'draft'], true)) {
            $database->rollBack();
            jsonError('Only active or draft prescriptions may be cancelled.', [], 409);
        }
        $dispensed = $database->prepare(
            'SELECT 1 FROM dispensings
             WHERE prescription_id = :prescription_id LIMIT 1'
        );
        $dispensed->execute(['prescription_id' => (int) $prescriptionId]);
        if ($dispensed->fetchColumn() !== false) {
            $database->rollBack();
            jsonError('A dispensed prescription cannot be cancelled.', [], 409);
        }
        $payment = $database->prepare(
            'SELECT payment.payment_id
             FROM payments payment
             INNER JOIN bills bill ON bill.bill_id = payment.bill_id
             WHERE bill.visit_id = :visit_id
               AND payment.status = \'approved\'
             LIMIT 1 FOR UPDATE'
        );
        $payment->execute(['visit_id' => (int) $prescription['visit_id']]);
        if ($payment->fetchColumn() !== false) {
            $database->rollBack();
            jsonError(
                'This visit has approved payments; contact the cashier before cancellation.',
                [],
                409
            );
        }
        $database->prepare(
            'UPDATE bill_items bill_item
             INNER JOIN prescription_items item
                ON item.prescription_item_id = bill_item.prescription_item_id
             SET bill_item.is_voided = 1,
                 bill_item.voided_at = CURRENT_TIMESTAMP,
                 bill_item.voided_by_user_id = :user_id
             WHERE item.prescription_id = :prescription_id
               AND bill_item.is_voided = 0'
        )->execute([
            'user_id' => (int) $user['user_id'],
            'prescription_id' => (int) $prescriptionId,
        ]);
        $update = $database->prepare(
            'UPDATE prescriptions SET status = \'cancelled\'
             WHERE prescription_id = :prescription_id
               AND status IN (\'active\', \'draft\')'
        );
        $update->execute(['prescription_id' => (int) $prescriptionId]);
        if ($update->rowCount() !== 1) {
            $database->rollBack();
            jsonError('Prescription cancellation could not be saved.', [], 409);
        }
        $action = $database->prepare(
            'INSERT INTO pharmacy_prescription_actions
                (prescription_id, acted_by_user_id, action_type, reason)
             VALUES (:prescription_id, :user_id, \'cancelled\', :reason)'
        );
        $action->execute([
            'prescription_id' => (int) $prescriptionId,
            'user_id' => (int) $user['user_id'],
            'reason' => trim($reason),
        ]);
        $billStatement = $database->prepare(
            'SELECT bill_id FROM bills WHERE visit_id = :visit_id LIMIT 1'
        );
        $billStatement->execute(['visit_id' => (int) $prescription['visit_id']]);
        $billId = $billStatement->fetchColumn();
        if ($billId !== false) {
            billingRefreshBillTotal($database, (int) $billId);
        }
        recordPharmacyAudit(
            $database,
            (int) $user['user_id'],
            'pharmacy.prescription.cancelled',
            (int) $prescription['patient_id'],
            (int) $prescription['visit_id']
        );
        $database->commit();
    } catch (Throwable $exception) {
        if ($database->inTransaction()) {
            $database->rollBack();
        }
        error_log('Authorized prescription cancellation failed: ' . $exception->getMessage());
        jsonError('Unable to cancel the prescription right now.', [], 500);
    }
    jsonSuccess('Prescription cancelled by its authorized prescriber.', [
        'prescription_id' => (int) $prescriptionId,
        'status' => 'cancelled',
    ]);
}

function handlePharmacyInventoryGet(string $search, string $scope): void
{
    $database = getDatabaseConnection();
    $user = requirePharmacyAccess($database);
    $search = trim($search);
    if (strlen($search) > 100
        || !in_array($scope, ['all', 'low', 'expired', 'alerts'], true)) {
        jsonError('Invalid inventory filters.', [], 422);
    }
    $where = ' WHERE medicine.is_active = 1';
    $parameters = [];
    if ($scope === 'low') {
        $where .= ' AND medicine.quantity_in_stock <= medicine.reorder_level';
    } elseif ($scope === 'expired') {
        $where .= ' AND medicine.expiry_date < CURRENT_DATE';
    } elseif ($scope === 'alerts') {
        $where .= ' AND (
            medicine.quantity_in_stock <= medicine.reorder_level
            OR medicine.expiry_date < CURRENT_DATE
        )';
    }
    if ($search !== '') {
        $where .= ' AND (medicine.medicine_name LIKE :medicine_name
            OR CAST(medicine.medicine_id AS CHAR) LIKE :medicine_id)';
        $pattern = '%' . $search . '%';
        $parameters = [
            'medicine_name' => $pattern,
            'medicine_id' => $pattern,
        ];
    }
    $statement = $database->prepare(
        'SELECT medicine_id, medicine_name, unit_price, quantity_in_stock,
                reorder_level, expiry_date, is_low_schedule, is_demo,
                (quantity_in_stock <= reorder_level) AS is_low_stock,
                (expiry_date IS NOT NULL AND expiry_date < CURRENT_DATE)
                    AS is_expired
         FROM medicines' . str_replace('medicine.', '', $where) . '
         ORDER BY medicine_name
         LIMIT 500'
    );
    $statement->execute($parameters);
    $medicines = array_map(static function (array $medicine): array {
        return [
            'medicine_id' => (int) $medicine['medicine_id'],
            'medicine_name' => $medicine['medicine_name'],
            'unit_price' => (string) $medicine['unit_price'],
            'quantity_in_stock' => (string) $medicine['quantity_in_stock'],
            'reorder_level' => (string) $medicine['reorder_level'],
            'expiry_date' => $medicine['expiry_date'],
            'is_low_schedule' => (bool) $medicine['is_low_schedule'],
            'is_demo' => (bool) $medicine['is_demo'],
            'is_low_stock' => (bool) $medicine['is_low_stock'],
            'is_expired' => (bool) $medicine['is_expired'],
        ];
    }, $statement->fetchAll());
    recordPharmacyAudit(
        $database,
        (int) $user['user_id'],
        'pharmacy.inventory.viewed',
        null,
        null
    );
    jsonSuccess('Pharmacy inventory loaded.', [
        'medicines' => $medicines,
        'count' => count($medicines),
    ]);
}

function handlePharmacyStockMovementCreate(string $medicineId): void
{
    if (!ctype_digit($medicineId) || (int) $medicineId < 1) {
        jsonError('A valid medicine ID is required.', [], 422);
    }
    $database = getDatabaseConnection();
    $user = requirePharmacyAccess($database);
    requireCsrfRequest();
    $input = readJsonRequest();
    $movementType = $input['movement_type'] ?? null;
    $quantityValue = $input['quantity'] ?? null;
    $notes = $input['notes'] ?? null;
    $expiryDate = $input['expiry_date'] ?? null;
    if (!is_string($movementType)
        || !in_array($movementType, ['received', 'adjustment'], true)
        || (!is_string($quantityValue) && !is_int($quantityValue)
            && !is_float($quantityValue))
        || !preg_match('/^-?[0-9]+(?:\.[0-9]{1,2})?$/', (string) $quantityValue)
        || !is_finite((float) $quantityValue)
        || (float) $quantityValue === 0.0
        || ($movementType === 'received' && (float) $quantityValue < 0)
        || !is_string($notes)
        || trim($notes) === ''
        || strlen($notes) > 1000
        || ($expiryDate !== null && $expiryDate !== ''
            && (!is_string($expiryDate)
                || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $expiryDate)
                || !checkdate(
                    (int) substr($expiryDate, 5, 2),
                    (int) substr($expiryDate, 8, 2),
                    (int) substr($expiryDate, 0, 4)
                )
                || $expiryDate < date('Y-m-d')))) {
        jsonError('Enter a valid stock movement, quantity, reason and expiry date.', [
            'quantity' => 'Use a non-zero quantity with at most two decimals.',
            'notes' => 'A reason or stock reference is required.',
        ], 422);
    }
    if ($movementType === 'received' && (float) $quantityValue <= 0) {
        jsonError('Received stock quantity must be greater than zero.', [], 422);
    }
    try {
        $database->beginTransaction();
        $medicineStatement = $database->prepare(
            'SELECT medicine_id, quantity_in_stock, expiry_date, is_active
             FROM medicines
             WHERE medicine_id = :medicine_id
             LIMIT 1 FOR UPDATE'
        );
        $medicineStatement->execute(['medicine_id' => (int) $medicineId]);
        $medicine = $medicineStatement->fetch();
        if ($medicine === false) {
            $database->rollBack();
            jsonError('Medicine not found.', [], 404);
        }
        if (!(bool) $medicine['is_active']) {
            $database->rollBack();
            jsonError('Inactive medicines cannot receive stock changes.', [], 409);
        }
        $change = (float) $quantityValue;
        $stockAfter = (float) $medicine['quantity_in_stock'] + $change;
        if ($stockAfter < 0) {
            $database->rollBack();
            jsonError('Stock adjustment cannot reduce inventory below zero.', [
                'quantity' => 'Reduce the adjustment quantity.',
            ], 409);
        }
        $expiryDate = is_string($expiryDate) && $expiryDate !== ''
            ? $expiryDate
            : null;
        $update = $database->prepare(
            'UPDATE medicines
             SET quantity_in_stock = :quantity_in_stock,
                 expiry_date = COALESCE(:expiry_date, expiry_date)
             WHERE medicine_id = :medicine_id'
        );
        $update->execute([
            'quantity_in_stock' => number_format($stockAfter, 2, '.', ''),
            'expiry_date' => $expiryDate,
            'medicine_id' => (int) $medicineId,
        ]);
        $movement = $database->prepare(
            'INSERT INTO pharmacy_stock_movements
                (medicine_id, recorded_by_user_id, movement_type,
                 quantity_change, stock_after, notes)
             VALUES (:medicine_id, :user_id, :movement_type,
                     :quantity_change, :stock_after, :notes)'
        );
        $movement->execute([
            'medicine_id' => (int) $medicineId,
            'user_id' => (int) $user['user_id'],
            'movement_type' => $movementType,
            'quantity_change' => number_format($change, 2, '.', ''),
            'stock_after' => number_format($stockAfter, 2, '.', ''),
            'notes' => trim($notes),
        ]);
        recordPharmacyAudit(
            $database,
            (int) $user['user_id'],
            'pharmacy.stock.' . $movementType,
            null,
            null
        );
        $database->commit();
    } catch (Throwable $exception) {
        if ($database->inTransaction()) {
            $database->rollBack();
        }
        error_log('Pharmacy stock movement failed: ' . $exception->getMessage());
        jsonError('Unable to save the stock movement right now.', [], 500);
    }
    jsonSuccess('Stock movement recorded.', [
        'medicine_id' => (int) $medicineId,
        'quantity_change' => number_format($change, 2, '.', ''),
        'stock_after' => number_format($stockAfter, 2, '.', ''),
    ], 201);
}

function handlePharmacyStockMovementsGet(): void
{
    $database = getDatabaseConnection();
    $user = requirePharmacyAccess($database);
    $statement = $database->query(
        'SELECT movement.stock_movement_id, movement.medicine_id,
                medicine.medicine_name, movement.movement_type,
                movement.quantity_change, movement.stock_after,
                movement.notes, movement.created_at,
                recorder.display_name AS recorded_by
         FROM pharmacy_stock_movements movement
         INNER JOIN medicines medicine ON medicine.medicine_id = movement.medicine_id
         INNER JOIN users recorder
            ON recorder.user_id = movement.recorded_by_user_id
         ORDER BY movement.created_at DESC, movement.stock_movement_id DESC
         LIMIT 250'
    );
    recordPharmacyAudit(
        $database,
        (int) $user['user_id'],
        'pharmacy.stock_history.viewed',
        null,
        null
    );
    jsonSuccess('Pharmacy stock history loaded.', [
        'movements' => array_map(static function (array $movement): array {
            return [
                'stock_movement_id' => (int) $movement['stock_movement_id'],
                'medicine_id' => (int) $movement['medicine_id'],
                'medicine_name' => $movement['medicine_name'],
                'movement_type' => $movement['movement_type'],
                'quantity_change' => (string) $movement['quantity_change'],
                'stock_after' => (string) $movement['stock_after'],
                'notes' => $movement['notes'],
                'created_at' => $movement['created_at'],
                'recorded_by' => $movement['recorded_by'],
            ];
        }, $statement->fetchAll()),
    ]);
}

function handlePharmacyReportsGet(): void
{
    $database = getDatabaseConnection();
    $user = requirePharmacyAccess($database);
    $statement = $database->query(
        'SELECT
            COUNT(*) AS dispensing_count,
            COUNT(DISTINCT prescription_id) AS prescription_count,
            COALESCE(SUM((
                SELECT COUNT(*)
                FROM dispensing_items item
                WHERE item.dispensing_id = dispensing.dispensing_id
            )), 0) AS dispensed_item_count
         FROM dispensings dispensing
         WHERE DATE(dispensing.dispensed_at) = CURRENT_DATE'
    );
    $summary = $statement->fetch();
    recordPharmacyAudit(
        $database,
        (int) $user['user_id'],
        'pharmacy.reports.viewed',
        null,
        null
    );
    jsonSuccess('Pharmacy report loaded.', [
        'dispensed_today' => (int) $summary['dispensing_count'],
        'prescriptions_today' => (int) $summary['prescription_count'],
        'items_today' => (int) $summary['dispensed_item_count'],
    ]);
}
