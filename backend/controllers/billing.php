<?php

declare(strict_types=1);

function requireBillingAccess(PDO $database): array
{
    $user = getAuthenticatedUser($database);
    if ($user === null) {
        jsonError('Authentication is required.', [], 401);
    }

    if (!in_array($user['role_name'], ['cashier', 'system_administrator'], true)) {
        jsonError('Cashier or system administrator access is required.', [], 403);
    }

    return $user;
}

function recordBillingAudit(
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

function handleBillingQueueGet(string $search): void
{
    $database = getDatabaseConnection();
    $user = requireBillingAccess($database);
    $search = trim($search);
    if (strlen($search) > 100) {
        jsonError('Search text must be 100 characters or fewer.', [
            'search' => 'Shorten the search text.',
        ], 422);
    }

    $feeStatement = $database->prepare(
        'SELECT fee_id, fee_code, description, default_amount, is_demo
         FROM fee_catalog
         WHERE fee_code = :fee_code AND is_active = 1
         LIMIT 1'
    );
    $feeStatement->execute(['fee_code' => 'TICKET_DEMO']);
    $ticketFee = $feeStatement->fetch();
    if ($ticketFee === false || (float) $ticketFee['default_amount'] <= 0) {
        jsonError('The active ticket fee is not configured.', [], 503);
    }

    $searchSql = '';
    $parameters = ['ticket_status' => 'awaiting_ticket_payment'];
    if ($search !== '') {
        $searchSql = ' AND (
            p.patient_number LIKE :patient_number
            OR p.first_name LIKE :first_name
            OR p.last_name LIKE :last_name
            OR CONCAT_WS(\' \', p.first_name, p.last_name) LIKE :full_name
            OR CAST(v.visit_id AS CHAR) LIKE :visit_id
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
        'SELECT v.visit_id, v.patient_id, v.arrived_at, v.visit_source,
                v.source_organization_id, organization.name AS source_organization,
                p.patient_number, p.first_name, p.last_name
         FROM visits v
         INNER JOIN patients p ON p.patient_id = v.patient_id
         LEFT JOIN organizations organization
            ON organization.organization_id = v.source_organization_id
         WHERE v.status = :ticket_status
           AND v.is_emergency = 0
           AND v.medication_collection_only = 0'
            . $searchSql .
        ' ORDER BY v.arrived_at ASC, v.visit_id ASC
          LIMIT 250'
    );
    $statement->execute($parameters);
    $visits = [];
    foreach ($statement->fetchAll() as $row) {
        $visit = [
            'visit_id' => (int) $row['visit_id'],
            'patient_id' => (int) $row['patient_id'],
            'patient_number' => $row['patient_number'],
            'patient_name' => trim(
                (string) $row['first_name'] . ' ' . (string) $row['last_name']
            ),
            'arrived_at' => $row['arrived_at'],
            'visit_source' => $row['visit_source'],
            'source_organization_id' => $row['source_organization_id'] === null
                ? null
                : (int) $row['source_organization_id'],
            'source_organization' => $row['source_organization'],
            'ticket_amount' => (string) $ticketFee['default_amount'],
            'ticket_fee_is_demo' => (bool) $ticketFee['is_demo'],
        ];
        $visits[] = $visit;
        recordBillingAudit(
            $database,
            (int) $user['user_id'],
            'billing.queue.viewed',
            $visit['patient_id'],
            $visit['visit_id']
        );
    }

    jsonSuccess('Ticket-payment queue loaded.', [
        'visits' => $visits,
        'count' => count($visits),
        'ticket_fee' => [
            'fee_code' => $ticketFee['fee_code'],
            'description' => $ticketFee['description'],
            'amount' => (string) $ticketFee['default_amount'],
            'is_demo' => (bool) $ticketFee['is_demo'],
        ],
    ]);
}

function handleBillingVisitGet(string $visitId): void
{
    if (!ctype_digit($visitId) || (int) $visitId < 1) {
        jsonError('A valid visit ID is required.', [
            'visit_id' => 'Enter a positive visit ID.',
        ], 422);
    }

    $database = getDatabaseConnection();
    $user = requireBillingAccess($database);
    $statement = $database->prepare(
        'SELECT visit.visit_id, visit.patient_id, visit.arrived_at,
                visit.visit_source, visit.source_organization_id, visit.status,
                visit.is_emergency, visit.medication_collection_only,
                organization.name AS source_organization,
                patient.patient_number, patient.first_name, patient.last_name
         FROM visits visit
         INNER JOIN patients patient ON patient.patient_id = visit.patient_id
         LEFT JOIN organizations organization
            ON organization.organization_id = visit.source_organization_id
         WHERE visit.visit_id = :visit_id
         LIMIT 1'
    );
    $statement->execute(['visit_id' => (int) $visitId]);
    $row = $statement->fetch();
    if ($row === false) {
        jsonError('Visit not found.', [], 404);
    }
    if ((bool) $row['is_emergency']
        || (bool) $row['medication_collection_only']
        || $row['status'] !== 'awaiting_ticket_payment') {
        jsonError('This visit is not eligible for ticket payment.', [], 409);
    }

    $feeStatement = $database->prepare(
        'SELECT fee_code, description, default_amount, is_demo
         FROM fee_catalog
         WHERE fee_code = :fee_code AND is_active = 1
         LIMIT 1'
    );
    $feeStatement->execute(['fee_code' => 'TICKET_DEMO']);
    $ticketFee = $feeStatement->fetch();
    if ($ticketFee === false || (float) $ticketFee['default_amount'] <= 0) {
        jsonError('The active ticket fee is not configured.', [], 503);
    }

    recordBillingAudit(
        $database,
        (int) $user['user_id'],
        'billing.visit.viewed',
        (int) $row['patient_id'],
        (int) $row['visit_id']
    );

    jsonSuccess('Ticket-payment visit loaded.', [
        'visit' => [
            'visit_id' => (int) $row['visit_id'],
            'patient_number' => $row['patient_number'],
            'patient_name' => trim(
                (string) $row['first_name'] . ' ' . (string) $row['last_name']
            ),
            'arrived_at' => $row['arrived_at'],
            'visit_source' => $row['visit_source'],
            'source_organization' => $row['source_organization'],
            'status' => $row['status'],
        ],
        'ticket_fee' => [
            'fee_code' => $ticketFee['fee_code'],
            'description' => $ticketFee['description'],
            'amount' => (string) $ticketFee['default_amount'],
            'is_demo' => (bool) $ticketFee['is_demo'],
        ],
    ]);
}

function handleBillingTicketPaymentCreate(string $visitId): void
{
    if (!ctype_digit($visitId) || (int) $visitId < 1) {
        jsonError('A valid visit ID is required.', [
            'visit_id' => 'Enter a positive visit ID.',
        ], 422);
    }

    $database = getDatabaseConnection();
    $user = requireBillingAccess($database);
    requireCsrfRequest();
    $input = readJsonRequest();
    $paymentMethod = $input['payment_method'] ?? null;
    if (!is_string($paymentMethod)
        || !in_array($paymentMethod, ['cash', 'card', 'medical_aid'], true)) {
        jsonError('Choose a valid payment method.', [
            'payment_method' => 'Choose cash, card, or medical aid.',
        ], 422);
    }

    try {
        $database->beginTransaction();
        $visitStatement = $database->prepare(
            'SELECT visit_id, patient_id, status, is_emergency,
                    medication_collection_only
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

        if ((bool) $visit['is_emergency']
            || (bool) $visit['medication_collection_only']) {
            $database->rollBack();
            jsonError(
                'Emergency and medication-collection-only visits do not pay the ticket fee.',
                [],
                409
            );
        }
        if ($visit['status'] !== 'awaiting_ticket_payment') {
            $database->rollBack();
            jsonError('This visit is not awaiting ticket payment.', [], 409);
        }

        $feeStatement = $database->prepare(
            'SELECT default_amount
             FROM fee_catalog
             WHERE fee_code = :fee_code AND is_active = 1
             LIMIT 1
             FOR UPDATE'
        );
        $feeStatement->execute(['fee_code' => 'TICKET_DEMO']);
        $ticketFee = $feeStatement->fetch();
        if ($ticketFee === false || (float) $ticketFee['default_amount'] <= 0) {
            $database->rollBack();
            jsonError('The active ticket fee is not configured.', [], 503);
        }

        $bill = billingGetOrCreateBill($database, (int) $visit['visit_id']);
        if ($bill['status'] === 'cancelled' || $bill['status'] === 'paid') {
            $database->rollBack();
            jsonError('This visit bill is already closed.', [], 409);
        }

        $approvedPaymentStatement = $database->prepare(
            'SELECT payment_id
             FROM payments
             WHERE bill_id = :bill_id AND status = \'approved\'
             LIMIT 1
             FOR UPDATE'
        );
        $approvedPaymentStatement->execute(['bill_id' => (int) $bill['bill_id']]);
        if ($approvedPaymentStatement->fetchColumn() !== false) {
            $database->rollBack();
            jsonError('This visit already has an approved payment.', [], 409);
        }

        $ticketItem = billingAddFeeItemOnce(
            $database,
            (int) $bill['bill_id'],
            'TICKET_DEMO',
            'ticket'
        );
        $amount = $ticketItem['unit_amount'];
        $billTotal = billingRefreshBillTotal($database, (int) $bill['bill_id']);
        $receiptNumber = billingGenerateReceiptNumber($database);

        $paymentInsert = $database->prepare(
            'INSERT INTO payments
                (bill_id, recorded_by_user_id, payment_method, amount, status,
                 receipt_number, is_simulated, paid_at)
             VALUES
                (:bill_id, :user_id, :payment_method, :amount, \'approved\',
                 :receipt_number, :is_simulated, CURRENT_TIMESTAMP)'
        );
        $paymentInsert->execute([
            'bill_id' => (int) $bill['bill_id'],
            'user_id' => (int) $user['user_id'],
            'payment_method' => $paymentMethod,
            'amount' => $amount,
            'receipt_number' => $receiptNumber,
            'is_simulated' => $paymentMethod === 'cash' ? 0 : 1,
        ]);
        $paymentId = (int) $database->lastInsertId();

        $balance = billingGetBillBalance($database, (int) $bill['bill_id']);
        $billStatus = (float) $balance['balance'] <= 0
            ? 'paid'
            : 'partially_paid';
        $billUpdate = $database->prepare(
            'UPDATE bills SET status = :status WHERE bill_id = :bill_id'
        );
        $billUpdate->execute([
            'status' => $billStatus,
            'bill_id' => (int) $bill['bill_id'],
        ]);

        $visitUpdate = $database->prepare(
            'UPDATE visits
             SET status = \'awaiting_nurse\'
             WHERE visit_id = :visit_id AND status = \'awaiting_ticket_payment\''
        );
        $visitUpdate->execute(['visit_id' => (int) $visitId]);
        if ($visitUpdate->rowCount() !== 1) {
            $database->rollBack();
            jsonError('This visit is no longer awaiting ticket payment.', [], 409);
        }

        recordBillingAudit(
            $database,
            (int) $user['user_id'],
            'billing.ticket_payment.recorded',
            (int) $visit['patient_id'],
            (int) $visitId
        );
        $database->commit();
    } catch (Throwable $exception) {
        if ($database->inTransaction()) {
            $database->rollBack();
        }
        error_log('Ticket payment recording failed: ' . $exception->getMessage());
        jsonError('Unable to record the ticket payment right now.', [], 500);
    }

    jsonSuccess('Ticket payment recorded; visit sent to nursing.', [
        'payment_id' => $paymentId,
        'receipt_number' => $receiptNumber,
        'bill_id' => (int) $bill['bill_id'],
        'bill_number' => $bill['bill_number'],
        'bill_total' => $billTotal,
        'amount' => $amount,
        'payment_method' => $paymentMethod,
        'is_simulated' => $paymentMethod !== 'cash',
        'payment_status' => 'approved',
        'bill_status' => $billStatus,
        'approved_payments' => $balance['approved_amount'],
        'balance' => $balance['balance'],
        'visit_id' => (int) $visitId,
        'visit_status' => 'awaiting_nurse',
    ], 201);
}

function handleBillingReceiptGet(string $paymentId): void
{
    if (!ctype_digit($paymentId) || (int) $paymentId < 1) {
        jsonError('A valid payment ID is required.', [], 422);
    }

    $database = getDatabaseConnection();
    $user = requireBillingAccess($database);
    $statement = $database->prepare(
        'SELECT payment.payment_id, payment.payment_method, payment.amount,
                payment.receipt_number, payment.is_simulated, payment.paid_at,
                bill.bill_id, bill.bill_number, bill.total_amount, bill.status AS bill_status,
                visit.visit_id, visit.patient_id, visit.visit_source,
                patient.patient_number, patient.first_name, patient.last_name,
                recorder.display_name AS cashier_name
         FROM payments payment
         INNER JOIN bills bill ON bill.bill_id = payment.bill_id
         INNER JOIN visits visit ON visit.visit_id = bill.visit_id
         INNER JOIN patients patient ON patient.patient_id = visit.patient_id
         LEFT JOIN users recorder ON recorder.user_id = payment.recorded_by_user_id
         WHERE payment.payment_id = :payment_id
           AND payment.status = \'approved\'
           AND payment.paid_at IS NOT NULL
           AND payment.receipt_number IS NOT NULL
         LIMIT 1'
    );
    $statement->execute(['payment_id' => (int) $paymentId]);
    $payment = $statement->fetch();
    if ($payment === false) {
        jsonError('Approved payment receipt not found.', [], 404);
    }

    $itemsStatement = $database->prepare(
        'SELECT charge_type, description, quantity, unit_amount,
                quantity * unit_amount AS line_total
         FROM bill_items
         WHERE bill_id = :bill_id
         ORDER BY bill_item_id'
    );
    $itemsStatement->execute(['bill_id' => (int) $payment['bill_id']]);
    $items = array_map(static function (array $item): array {
        return [
            'charge_type' => $item['charge_type'],
            'description' => $item['description'],
            'quantity' => (string) $item['quantity'],
            'unit_amount' => (string) $item['unit_amount'],
            'line_total' => (string) $item['line_total'],
        ];
    }, $itemsStatement->fetchAll());

    recordBillingAudit(
        $database,
        (int) $user['user_id'],
        'billing.receipt.viewed',
        (int) $payment['patient_id'],
        (int) $payment['visit_id']
    );

    jsonSuccess('Receipt loaded.', [
        'payment' => [
            'payment_id' => (int) $payment['payment_id'],
            'receipt_number' => $payment['receipt_number'],
            'payment_method' => $payment['payment_method'],
            'amount' => (string) $payment['amount'],
            'is_simulated' => (bool) $payment['is_simulated'],
            'paid_at' => $payment['paid_at'],
            'cashier_name' => $payment['cashier_name'],
        ],
        'bill' => [
            'bill_id' => (int) $payment['bill_id'],
            'bill_number' => $payment['bill_number'],
            'total_amount' => (string) $payment['total_amount'],
            'status' => $payment['bill_status'],
        ],
        'visit' => [
            'visit_id' => (int) $payment['visit_id'],
            'visit_source' => $payment['visit_source'],
        ],
        'patient' => [
            'patient_number' => $payment['patient_number'],
            'name' => trim(
                (string) $payment['first_name'] . ' ' . (string) $payment['last_name']
            ),
        ],
        'items' => $items,
    ]);
}
