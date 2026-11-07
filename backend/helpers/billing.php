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
    ?int $therapySessionId = null
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
    if ($fee === false || (float) $fee['default_amount'] <= 0) {
        throw new RuntimeException('The required active fee is missing or invalid.');
    }

    if ($testOrderId !== null && $chargeType !== 'test') {
        throw new RuntimeException('A test order can only be linked to a test charge.');
    }
    if ($therapySessionId !== null && $chargeType !== 'therapy') {
        throw new RuntimeException('A therapy session can only be linked to a therapy charge.');
    }
    if ($chargeType === 'therapy' && $therapySessionId === null) {
        throw new RuntimeException('A therapy charge must be linked to a therapy session.');
    }
    if ($testOrderId !== null && $therapySessionId !== null) {
        throw new RuntimeException('A bill item cannot link both a test order and therapy session.');
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
    $existingStatement->execute($existingParameters);
    $existing = $existingStatement->fetch();
    if ($existing !== false) {
        if ((int) $existing['fee_id'] !== (int) $fee['fee_id']) {
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
             test_order_id, therapy_session_id)
         VALUES
            (:bill_id, :fee_id, :charge_type, :description, 1.00, :unit_amount,
             :test_order_id, :therapy_session_id)'
    );
    $insert->execute([
        'bill_id' => $billId,
        'fee_id' => (int) $fee['fee_id'],
        'charge_type' => $chargeType,
        'description' => $fee['description'],
        'unit_amount' => $fee['default_amount'],
        'test_order_id' => $testOrderId,
        'therapy_session_id' => $therapySessionId,
    ]);

    return [
        'bill_item_id' => (int) $database->lastInsertId(),
        'fee_id' => (int) $fee['fee_id'],
        'description' => $fee['description'],
        'quantity' => '1.00',
        'unit_amount' => (string) $fee['default_amount'],
        'created' => true,
    ];
}

function billingRefreshBillTotal(PDO $database, int $billId): string
{
    $totalStatement = $database->prepare(
        'SELECT COALESCE(SUM(quantity * unit_amount), 0.00)
         FROM bill_items
         WHERE bill_id = :bill_id'
    );
    $totalStatement->execute(['bill_id' => $billId]);
    $totalAmount = (string) $totalStatement->fetchColumn();

    $update = $database->prepare(
        'UPDATE bills SET total_amount = :total_amount WHERE bill_id = :bill_id'
    );
    $update->execute([
        'total_amount' => $totalAmount,
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
