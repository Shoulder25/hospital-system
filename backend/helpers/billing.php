<?php

declare(strict_types=1);

function billingGenerateBillNumber(PDO $database): string
{
    for ($attempt = 0; $attempt < 5; $attempt++) {
        $number = 'BILL-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(6)));
        $statement = $database->prepare(
            'SELECT 1 FROM bills WHERE bill_number = :bill_number LIMIT 1'
        );
        $statement->execute(['bill_number' => $number]);
        if ($statement->fetchColumn() === false) {
            return $number;
        }
    }

    throw new RuntimeException('Unable to generate a unique bill number.');
}

function billingGenerateReceiptNumber(PDO $database): string
{
    for ($attempt = 0; $attempt < 5; $attempt++) {
        $number = 'RCT-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(6)));
        $statement = $database->prepare(
            'SELECT 1 FROM payments WHERE receipt_number = :receipt_number LIMIT 1'
        );
        $statement->execute(['receipt_number' => $number]);
        if ($statement->fetchColumn() === false) {
            return $number;
        }
    }

    throw new RuntimeException('Unable to generate a unique receipt number.');
}

function billingFeeAllowsCashierAdd(string $feeCode): bool
{
    return preg_match(
        '/^(TICKET|TEST|THERAPY|MEDICATION|MINOR|EMERGENCY|OUTSIDE)(_|$)/',
        $feeCode
    ) !== 1;
}

function billingGetOrCreateBill(PDO $database, int $visitId): array
{
    $statement = $database->prepare(
        'SELECT bill_id, visit_id, bill_number, total_amount, status
         FROM bills
         WHERE visit_id = :visit_id
         LIMIT 1
         FOR UPDATE'
    );
    $statement->execute(['visit_id' => $visitId]);
    $bill = $statement->fetch();
    if ($bill !== false) {
        return $bill;
    }

    $billNumber = billingGenerateBillNumber($database);
    $insert = $database->prepare(
        'INSERT INTO bills (visit_id, bill_number, status)
         VALUES (:visit_id, :bill_number, \'draft\')'
    );
    $insert->execute([
        'visit_id' => $visitId,
        'bill_number' => $billNumber,
    ]);

    return [
        'bill_id' => (int) $database->lastInsertId(),
        'visit_id' => $visitId,
        'bill_number' => $billNumber,
        'total_amount' => '0.00',
        'status' => 'draft',
    ];
}

function billingAddFeeItemOnce(
    PDO $database,
    int $billId,
    string $feeCode,
    string $chargeType,
    ?int $testOrderId = null,
    ?int $therapySessionId = null,
    ?int $emergencyEncounterId = null,
    ?int $outsideReferralId = null,
    ?string $manualAmount = null,
    ?string $descriptionSuffix = null
): array {
    $feeStatement = $database->prepare(
        'SELECT fee_id, fee_code, description, default_amount, is_demo
         FROM fee_catalog
         WHERE fee_code = :fee_code AND is_active = 1
         LIMIT 1
         FOR UPDATE'
    );
    $feeStatement->execute(['fee_code' => $feeCode]);
    $fee = $feeStatement->fetch();
    if ($fee === false) {
        throw new RuntimeException('The required active fee is missing or invalid.');
    }
    if ($manualAmount !== null
        && ($chargeType !== 'outside_referral'
            || $feeCode !== 'OUTSIDE_TESTS_MANUAL'
            || $outsideReferralId === null
            || !preg_match('/^\d{1,8}(?:\.\d{1,2})?$/', $manualAmount)
            || (float) $manualAmount <= 0)) {
        throw new RuntimeException('The manual outside-referral amount is invalid.');
    }
    if ($chargeType === 'outside_referral'
        && ($outsideReferralId === null || $manualAmount === null)) {
        throw new RuntimeException('An outside-referral charge needs its referral and amount.');
    }
    if ($outsideReferralId !== null && $chargeType !== 'outside_referral') {
        throw new RuntimeException(
            'An outside referral can only be linked to an outside-referral charge.'
        );
    }
    $unitAmount = $manualAmount ?? (string) $fee['default_amount'];
    if ((float) $unitAmount <= 0) {
        throw new RuntimeException('The required active fee is missing or invalid.');
    }
    $description = (string) $fee['description'];
    if ($descriptionSuffix !== null && trim($descriptionSuffix) !== '') {
        $description .= ' - ' . trim($descriptionSuffix);
    }

    if ($testOrderId !== null && $chargeType !== 'test') {
        throw new RuntimeException('A test order can only be linked to a test charge.');
    }
    if ($therapySessionId !== null && $chargeType !== 'therapy') {
        throw new RuntimeException('A therapy session can only be linked to a therapy charge.');
    }
    if ($emergencyEncounterId !== null && $chargeType !== 'emergency') {
        throw new RuntimeException(
            'An emergency encounter can only be linked to an emergency charge.'
        );
    }
    if ($chargeType === 'therapy' && $therapySessionId === null) {
        throw new RuntimeException('A therapy charge must be linked to a therapy session.');
    }
    if ($chargeType === 'emergency' && $emergencyEncounterId === null) {
        throw new RuntimeException('An emergency charge must be linked to an encounter.');
    }
    if (($testOrderId !== null && $therapySessionId !== null)
        || ($emergencyEncounterId !== null
            && ($testOrderId !== null || $therapySessionId !== null))) {
        throw new RuntimeException('A bill item cannot link multiple workflow records.');
    }

    $existingSql = 'SELECT bill_item_id, fee_id, description, quantity, unit_amount
                    FROM bill_items
                    WHERE bill_id = :bill_id AND charge_type = :charge_type';
    if ($testOrderId !== null) {
        $existingSql .= ' AND test_order_id = :test_order_id';
    }
    if ($therapySessionId !== null) {
        $existingSql .= ' AND therapy_session_id = :therapy_session_id';
    }
    if ($emergencyEncounterId !== null) {
        $existingSql .= ' AND emergency_encounter_id = :emergency_encounter_id';
    }
    if ($outsideReferralId !== null) {
        $existingSql .= ' AND outside_referral_id = :outside_referral_id';
    }
    $existingSql .= ' ORDER BY bill_item_id LIMIT 1 FOR UPDATE';
    $existingStatement = $database->prepare($existingSql);
    $existingParameters = [
        'bill_id' => $billId,
        'charge_type' => $chargeType,
    ];
    if ($testOrderId !== null) {
        $existingParameters['test_order_id'] = $testOrderId;
    }
    if ($therapySessionId !== null) {
        $existingParameters['therapy_session_id'] = $therapySessionId;
    }
    if ($emergencyEncounterId !== null) {
        $existingParameters['emergency_encounter_id'] = $emergencyEncounterId;
    }
    if ($outsideReferralId !== null) {
        $existingParameters['outside_referral_id'] = $outsideReferralId;
    }
    $existingStatement->execute($existingParameters);
    $existing = $existingStatement->fetch();
    if ($existing !== false) {
        if ((int) $existing['fee_id'] !== (int) $fee['fee_id']
            || number_format((float) $existing['unit_amount'], 2, '.', '')
                !== number_format((float) $unitAmount, 2, '.', '')) {
            throw new RuntimeException('A different fee is already recorded for this charge.');
        }

        return [
            'bill_item_id' => (int) $existing['bill_item_id'],
            'fee_id' => (int) $existing['fee_id'],
            'description' => $existing['description'],
            'quantity' => (string) $existing['quantity'],
            'unit_amount' => (string) $existing['unit_amount'],
            'created' => false,
        ];
    }

    $insert = $database->prepare(
        'INSERT INTO bill_items
            (bill_id, fee_id, charge_type, description, quantity, unit_amount,
             test_order_id, therapy_session_id, emergency_encounter_id,
             outside_referral_id)
         VALUES
            (:bill_id, :fee_id, :charge_type, :description, 1.00, :unit_amount,
             :test_order_id, :therapy_session_id, :emergency_encounter_id,
             :outside_referral_id)'
    );
    $insert->execute([
        'bill_id' => $billId,
        'fee_id' => (int) $fee['fee_id'],
        'charge_type' => $chargeType,
        'description' => $description,
        'unit_amount' => $unitAmount,
        'test_order_id' => $testOrderId,
        'therapy_session_id' => $therapySessionId,
        'emergency_encounter_id' => $emergencyEncounterId,
        'outside_referral_id' => $outsideReferralId,
    ]);

    return [
        'bill_item_id' => (int) $database->lastInsertId(),
        'fee_id' => (int) $fee['fee_id'],
        'description' => $description,
        'quantity' => '1.00',
        'unit_amount' => $unitAmount,
        'created' => true,
    ];
}

function billingAddMedicationItemOnce(
    PDO $database,
    int $billId,
    int $prescriptionItemId
): array {
    $itemStatement = $database->prepare(
        'SELECT prescription_item.prescription_item_id,
                prescription_item.prescription_id,
                prescription_item.quantity, prescription.status,
                medicine.medicine_id, medicine.medicine_name,
                medicine.unit_price
         FROM prescription_items prescription_item
         INNER JOIN prescriptions prescription
            ON prescription.prescription_id = prescription_item.prescription_id
         INNER JOIN medicines medicine
            ON medicine.medicine_id = prescription_item.medicine_id
         WHERE prescription_item.prescription_item_id = :prescription_item_id
         LIMIT 1
         FOR UPDATE'
    );
    $itemStatement->execute(['prescription_item_id' => $prescriptionItemId]);
    $item = $itemStatement->fetch();
    if ($item === false || $item['status'] !== 'active'
        || (float) $item['unit_price'] < 0
        || (float) $item['quantity'] <= 0) {
        throw new RuntimeException('The active prescription item is unavailable.');
    }

    $existingStatement = $database->prepare(
        'SELECT bill_item_id, bill_id, fee_id, description, quantity, unit_amount
         FROM bill_items
         WHERE prescription_item_id = :prescription_item_id
         ORDER BY bill_item_id
         LIMIT 1
         FOR UPDATE'
    );
    $existingStatement->execute([
        'prescription_item_id' => $prescriptionItemId,
    ]);
    $existing = $existingStatement->fetch();
    if ($existing !== false) {
        if ((int) $existing['bill_id'] !== $billId) {
            throw new RuntimeException(
                'This prescription item is already billed to another visit.'
            );
        }

        return [
            'bill_item_id' => (int) $existing['bill_item_id'],
            'fee_id' => $existing['fee_id'] === null
                ? null
                : (int) $existing['fee_id'],
            'description' => $existing['description'],
            'quantity' => (string) $existing['quantity'],
            'unit_amount' => (string) $existing['unit_amount'],
            'created' => false,
        ];
    }

    $insert = $database->prepare(
        'INSERT INTO bill_items
            (bill_id, charge_type, description, quantity, unit_amount,
             prescription_item_id)
         VALUES
            (:bill_id, \'medication\', :description, :quantity, :unit_amount,
             :prescription_item_id)'
    );
    $insert->execute([
        'bill_id' => $billId,
        'description' => $item['medicine_name'],
        'quantity' => $item['quantity'],
        'unit_amount' => $item['unit_price'],
        'prescription_item_id' => $prescriptionItemId,
    ]);

    return [
        'bill_item_id' => (int) $database->lastInsertId(),
        'fee_id' => null,
        'description' => $item['medicine_name'],
        'quantity' => (string) $item['quantity'],
        'unit_amount' => (string) $item['unit_price'],
        'created' => true,
    ];
}

function billingCalculateBillTotal(PDO $database, int $billId): string
{
    $totalStatement = $database->prepare(
        'SELECT COALESCE(SUM(quantity * unit_amount), 0.00)
         FROM bill_items
         WHERE bill_id = :bill_id AND is_voided = 0'
    );
    $totalStatement->execute(['bill_id' => $billId]);
    return (string) $totalStatement->fetchColumn();
}

function billingRefreshBillTotal(PDO $database, int $billId): string
{
    $totalAmount = billingCalculateBillTotal($database, $billId);
    $balance = billingGetBillBalance($database, $billId);
    $status = (float) $balance['billed_amount'] <= 0
        ? null
        : ((float) $balance['balance'] <= 0
            ? 'paid'
            : ((float) $balance['approved_amount'] > 0
                ? 'partially_paid'
                : 'pending'));
    $update = $database->prepare(
        'UPDATE bills
         SET total_amount = :total_amount,
             status = CASE
             WHEN status = \'cancelled\' OR :keep_status = 1 THEN status
             ELSE :new_status
             END
         WHERE bill_id = :bill_id'
    );
    $update->execute([
        'total_amount' => $totalAmount,
        'keep_status' => $status === null ? 1 : 0,
        'new_status' => $status,
        'bill_id' => $billId,
    ]);

    return $totalAmount;
}

function billingGetBillBalance(PDO $database, int $billId): array
{
    $statement = $database->prepare(
        'SELECT totals.billed_amount,
                totals.approved_amount,
                CAST(totals.billed_amount - totals.approved_amount AS DECIMAL(12,2))
                    AS balance
         FROM (
            SELECT bill.bill_id,
                   COALESCE((
                       SELECT SUM(item.quantity * item.unit_amount)
                       FROM bill_items item
                       WHERE item.bill_id = bill.bill_id
                         AND item.is_voided = 0
                   ), 0.00) AS billed_amount,
                   COALESCE((
                       SELECT SUM(payment.amount)
                       FROM payments payment
                       WHERE payment.bill_id = bill.bill_id
                         AND payment.status = \'approved\'
                   ), 0.00) AS approved_amount
            FROM bills bill
            WHERE bill.bill_id = :bill_id
         ) totals
         LIMIT 1'
    );
    $statement->execute(['bill_id' => $billId]);
    $balance = $statement->fetch();
    if ($balance === false) {
        throw new RuntimeException('The bill could not be found.');
    }

    return [
        'billed_amount' => (string) $balance['billed_amount'],
        'approved_amount' => (string) $balance['approved_amount'],
        'balance' => (string) $balance['balance'],
    ];
}

function billingGetApprovedPayments(PDO $database, int $billId): string
{
    return billingGetBillBalance($database, $billId)['approved_amount'];
}

function billingGetOutstandingBalance(PDO $database, int $billId): string
{
    return billingGetBillBalance($database, $billId)['balance'];
}
