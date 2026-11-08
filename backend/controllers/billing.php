<?php

declare(strict_types=1);

function requireBillingAccess(PDO $database, bool $allowReception = false): array
{
    $user = getAuthenticatedUser($database);
    if ($user === null) {
        jsonError('Authentication is required.', [], 401);
    }

    $allowedRoles = ['cashier', 'system_administrator'];
    if ($allowReception) {
        $allowedRoles[] = 'receptionist';
    }
    if (!in_array($user['role_name'], $allowedRoles, true)) {
        jsonError('Billing access is not authorized.', [], 403);
    }

    return $user;
}

function handleBillingDashboardGet(string $from, string $to): void
{
    $database = getDatabaseConnection();
    requireBillingAccess($database);
    $today = date('Y-m-d');
    $firstDay = date('Y-m-01');
    $from = $from === '' ? $firstDay : $from;
    $to = $to === '' ? $today : $to;
    foreach (['from' => $from, 'to' => $to] as $field => $date) {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
            jsonError('Choose valid payment dates.', [
                $field => 'Use the YYYY-MM-DD date format.',
            ], 422);
        }
    }
    if ($from > $to) {
        jsonError('The start date must not be after the end date.', [
            'from' => 'Choose an earlier start date.',
        ], 422);
    }

    $statsStatement = $database->query(
        'SELECT
            (SELECT COUNT(*)
             FROM bills bill
             WHERE bill.status <> \'cancelled\'
               AND COALESCE((
                    SELECT SUM(item.quantity * item.unit_amount)
                    FROM bill_items item
                    WHERE item.bill_id = bill.bill_id AND item.is_voided = 0
               ), 0.00) > COALESCE((
                    SELECT SUM(payment.amount)
                    FROM payments payment
                    WHERE payment.bill_id = bill.bill_id
                      AND payment.status = \'approved\'
               ), 0.00)) AS outstanding_bills,
            (SELECT COUNT(*)
             FROM visits
             WHERE status IN (\'awaiting_ticket_payment\', \'awaiting_payment\')
            ) AS awaiting_payment_visits,
            (SELECT COUNT(*)
             FROM bills bill
             WHERE bill.status <> \'cancelled\'
               AND COALESCE((
                    SELECT SUM(payment.amount)
                    FROM payments payment
                    WHERE payment.bill_id = bill.bill_id
                      AND payment.status = \'approved\'
               ), 0.00) > 0
               AND COALESCE((
                    SELECT SUM(payment.amount)
                    FROM payments payment
                    WHERE payment.bill_id = bill.bill_id
                      AND payment.status = \'approved\'
               ), 0.00) < COALESCE((
                    SELECT SUM(item.quantity * item.unit_amount)
                    FROM bill_items item
                    WHERE item.bill_id = bill.bill_id AND item.is_voided = 0
               ), 0.00)
            ) AS partially_paid_bills,
            (SELECT COUNT(*)
             FROM bills bill
             WHERE bill.status <> \'cancelled\'
               AND COALESCE((
                    SELECT SUM(item.quantity * item.unit_amount)
                    FROM bill_items item
                    WHERE item.bill_id = bill.bill_id AND item.is_voided = 0
               ), 0.00) > 0
               AND COALESCE((
                    SELECT SUM(payment.amount)
                    FROM payments payment
                    WHERE payment.bill_id = bill.bill_id
                      AND payment.status = \'approved\'
               ), 0.00) >= COALESCE((
                    SELECT SUM(item.quantity * item.unit_amount)
                    FROM bill_items item
                    WHERE item.bill_id = bill.bill_id AND item.is_voided = 0
               ), 0.00)
            ) AS fully_paid_bills'
    );
    $stats = $statsStatement->fetch();

    $paymentsStatement = $database->prepare(
        'SELECT payment.payment_id, payment.receipt_number, payment.payment_method,
                payment.amount, payment.status, payment.is_simulated, payment.paid_at,
                visit.visit_id, patient.patient_number, patient.first_name,
                patient.last_name, recorder.display_name AS cashier_name
         FROM payments payment
         INNER JOIN bills bill ON bill.bill_id = payment.bill_id
         INNER JOIN visits visit ON visit.visit_id = bill.visit_id
         INNER JOIN patients patient ON patient.patient_id = visit.patient_id
         LEFT JOIN users recorder ON recorder.user_id = payment.recorded_by_user_id
         WHERE payment.paid_at >= :from_date
           AND payment.paid_at < DATE_ADD(:to_date, INTERVAL 1 DAY)
         ORDER BY payment.paid_at DESC, payment.payment_id DESC
         LIMIT 20'
    );
    $paymentsStatement->execute([
        'from_date' => $from . ' 00:00:00',
        'to_date' => $to,
    ]);
    $recentPayments = array_map(static function (array $row): array {
        return [
            'payment_id' => (int) $row['payment_id'],
            'receipt_number' => $row['receipt_number'],
            'payment_method' => $row['payment_method'],
            'amount' => (string) $row['amount'],
            'status' => $row['status'],
            'is_simulated' => (bool) $row['is_simulated'],
            'paid_at' => $row['paid_at'],
            'visit_id' => (int) $row['visit_id'],
            'patient_number' => $row['patient_number'],
            'patient_name' => trim(
                (string) $row['first_name'] . ' ' . (string) $row['last_name']
            ),
            'cashier_name' => $row['cashier_name'],
        ];
    }, $paymentsStatement->fetchAll());

    $totalStatement = $database->prepare(
        'SELECT COALESCE(SUM(amount), 0.00)
         FROM payments
         WHERE status = \'approved\'
           AND paid_at >= :from_date
           AND paid_at < DATE_ADD(:to_date, INTERVAL 1 DAY)'
    );
    $totalStatement->execute([
        'from_date' => $from . ' 00:00:00',
        'to_date' => $to,
    ]);
    $feesStatement = $database->query(
        'SELECT fee_code, description, default_amount, currency_code,
                is_demo, cashier_addable
         FROM fee_catalog
         WHERE is_active = 1
         ORDER BY fee_code'
    );
    $fees = array_map(static function (array $row): array {
        return [
            'fee_code' => $row['fee_code'],
            'description' => $row['description'],
            'currency' => $row['currency_code'],
            'amount' => (float) $row['default_amount'] > 0
                ? (string) $row['default_amount']
                : null,
            'requires_manual_amount' => $row['fee_code'] === 'OUTSIDE_TESTS_MANUAL',
            'is_demo' => (bool) $row['is_demo'],
            'cashier_addable' => (bool) $row['cashier_addable'],
        ];
    }, $feesStatement->fetchAll());
    $configuredFees = [];
    foreach ($fees as $fee) {
        $configuredFees[$fee['fee_code']] = $fee;
    }
    $requiredFeeCodes = [
        'TICKET_DEMO',
        'TEST_BLOOD_DEMO',
        'TEST_GLUCOSE_DEMO',
        'TEST_URINE_DEMO',
        'TEST_PREGNANCY_DEMO',
        'TEST_PAP_SMEAR_DEMO',
        'TEST_X_RAY_DEMO',
        'TEST_ECG_DEMO',
        'THERAPY_SESSION_DEMO',
        'EMERGENCY_CARE_DEMO',
        'MINOR_TREATMENT_DEMO',
    ];
    $missingFeeCodes = array_values(array_filter(
        $requiredFeeCodes,
        static fn (string $code): bool =>
            !isset($configuredFees[$code]) || $configuredFees[$code]['amount'] === null
    ));

    jsonSuccess('Billing dashboard loaded.', [
        'currency' => $fees[0]['currency'] ?? 'SZL',
        'stats' => [
            'outstanding_bills' => (int) $stats['outstanding_bills'],
            'awaiting_payment_visits' => (int) $stats['awaiting_payment_visits'],
            'partially_paid_bills' => (int) $stats['partially_paid_bills'],
            'fully_paid_bills' => (int) $stats['fully_paid_bills'],
            'payments_received' => (string) $totalStatement->fetchColumn(),
        ],
        'date_range' => ['from' => $from, 'to' => $to],
        'recent_payments' => $recentPayments,
        'configured_fees' => $fees,
        'missing_fee_codes' => $missingFeeCodes,
    ]);
}

function handleBillingPatientSearch(string $search): void
{
    $database = getDatabaseConnection();
    $user = requireBillingAccess($database);
    $search = trim($search);
    if (strlen($search) > 100) {
        jsonError('Search text must be 100 characters or fewer.', [
            'search' => 'Shorten the search text.',
        ], 422);
    }
    if ($search === '') {
        jsonSuccess('Enter a patient number, name, or visit ID to search.', [
            'visits' => [],
        ]);
    }

    $pattern = '%' . $search . '%';
    $statement = $database->prepare(
        'SELECT patient.patient_id, patient.patient_number,
                patient.first_name, patient.last_name, patient.phone, patient.address,
                visit.visit_id, visit.arrived_at, visit.status AS visit_status,
                bill.bill_id, bill.bill_number, bill.status AS saved_bill_status,
                COALESCE((
                    SELECT SUM(item.quantity * item.unit_amount)
                    FROM bill_items item
                    WHERE item.bill_id = bill.bill_id AND item.is_voided = 0
                ), 0.00) AS billed_amount,
                COALESCE((
                    SELECT SUM(payment.amount)
                    FROM payments payment
                    WHERE payment.bill_id = bill.bill_id
                      AND payment.status = \'approved\'
                ), 0.00) AS approved_amount
         FROM patients patient
         INNER JOIN visits visit ON visit.patient_id = patient.patient_id
         LEFT JOIN bills bill ON bill.visit_id = visit.visit_id
         WHERE patient.patient_number LIKE :patient_number
            OR patient.first_name LIKE :first_name
            OR patient.last_name LIKE :last_name
            OR CONCAT_WS(\' \', patient.first_name, patient.last_name) LIKE :full_name
            OR CAST(visit.visit_id AS CHAR) LIKE :visit_id
         ORDER BY visit.arrived_at DESC, visit.visit_id DESC
         LIMIT 100'
    );
    $statement->execute([
        'patient_number' => $pattern,
        'first_name' => $pattern,
        'last_name' => $pattern,
        'full_name' => $pattern,
        'visit_id' => $pattern,
    ]);
    $visits = array_map(static function (array $row): array {
        $billed = (float) $row['billed_amount'];
        $paid = (float) $row['approved_amount'];
        $billStatus = $row['bill_id'] === null
            ? 'no_bill'
            : ($row['saved_bill_status'] === 'cancelled'
                ? 'cancelled'
                : ($billed <= 0
                    ? $row['saved_bill_status']
                    : ($paid >= $billed ? 'paid' : ($paid > 0 ? 'partially_paid' : 'pending'))));
        return [
            'patient_id' => (int) $row['patient_id'],
            'patient_number' => $row['patient_number'],
            'patient_name' => trim(
                (string) $row['first_name'] . ' ' . (string) $row['last_name']
            ),
            'phone' => $row['phone'],
            'address' => $row['address'],
            'visit_id' => (int) $row['visit_id'],
            'arrived_at' => $row['arrived_at'],
            'visit_status' => $row['visit_status'],
            'bill_id' => $row['bill_id'] === null ? null : (int) $row['bill_id'],
            'bill_number' => $row['bill_number'],
            'bill_status' => $billStatus,
            'billed_amount' => (string) $row['billed_amount'],
            'paid_amount' => (string) $row['approved_amount'],
            'balance' => number_format($billed - $paid, 2, '.', ''),
        ];
    }, $statement->fetchAll());

    recordBillingAudit(
        $database,
        (int) $user['user_id'],
        'billing.patient_search.viewed',
        null,
        null
    );
    jsonSuccess('Patient visits loaded.', ['visits' => $visits]);
}

function handleBillingVisitBillGet(string $visitId): void
{
    if (!ctype_digit($visitId) || (int) $visitId < 1) {
        jsonError('A valid visit ID is required.', [], 422);
    }

    $database = getDatabaseConnection();
    $user = requireBillingAccess($database);
    $currencyCode = $database->query(
        'SELECT currency_code FROM fee_catalog ORDER BY fee_id LIMIT 1'
    )->fetchColumn();
    $statement = $database->prepare(
        'SELECT visit.visit_id, visit.patient_id, visit.arrived_at,
                visit.visit_source, visit.status AS visit_status,
                patient.patient_number, patient.first_name, patient.last_name,
                patient.phone, patient.address, bill.bill_id, bill.bill_number,
                bill.status AS bill_status
         FROM visits visit
         INNER JOIN patients patient ON patient.patient_id = visit.patient_id
         LEFT JOIN bills bill ON bill.visit_id = visit.visit_id
         WHERE visit.visit_id = :visit_id
         LIMIT 1'
    );
    $statement->execute(['visit_id' => (int) $visitId]);
    $visit = $statement->fetch();
    if ($visit === false) {
        jsonError('Visit not found.', [], 404);
    }

    $bill = null;
    $items = [];
    $payments = [];
    $medicalAidSchemes = [];
    $medicalAidMemberships = [];
    $availableServices = [];
    $balance = ['billed_amount' => '0.00', 'approved_amount' => '0.00', 'balance' => '0.00'];
    if ($visit['bill_id'] !== null) {
        $billId = (int) $visit['bill_id'];
        $balance = billingGetBillBalance($database, $billId);
        $itemsStatement = $database->prepare(
            'SELECT item.bill_item_id, item.charge_type, item.description,
                    item.quantity, item.unit_amount, item.is_voided,
                    item.created_by_user_id, fee.cashier_addable,
                    item.quantity * item.unit_amount AS line_total
             FROM bill_items item
             LEFT JOIN fee_catalog fee ON fee.fee_id = item.fee_id
             WHERE item.bill_id = :bill_id
             ORDER BY item.bill_item_id'
        );
        $itemsStatement->execute(['bill_id' => $billId]);
        $hasPayments = (float) $balance['approved_amount'] > 0;
        $items = array_map(static function (array $item) use ($hasPayments): array {
            return [
                'bill_item_id' => (int) $item['bill_item_id'],
                'charge_type' => $item['charge_type'],
                'description' => $item['description'],
                'quantity' => (string) $item['quantity'],
                'unit_amount' => (string) $item['unit_amount'],
                'line_total' => (string) $item['line_total'],
                'is_voided' => (bool) $item['is_voided'],
                'can_remove' => !$hasPayments
                    && !(bool) $item['is_voided']
                    && $item['charge_type'] === 'other'
                    && $item['created_by_user_id'] !== null
                    && (bool) $item['cashier_addable'],
            ];
        }, $itemsStatement->fetchAll());
        if ($visit['bill_status'] !== 'cancelled'
            && $visit['visit_status'] !== 'cancelled') {
            $servicesStatement = $database->query(
                'SELECT fee_id, fee_code, description, default_amount,
                        currency_code, is_demo
                 FROM fee_catalog
                 WHERE is_active = 1 AND cashier_addable = 1
                   AND default_amount > 0
                 ORDER BY description, fee_code'
            );
            $availableServices = array_map(static function (array $service): array {
                return [
                    'fee_id' => (int) $service['fee_id'],
                    'fee_code' => $service['fee_code'],
                    'description' => $service['description'],
                    'amount' => (string) $service['default_amount'],
                    'currency' => $service['currency_code'],
                    'is_demo' => (bool) $service['is_demo'],
                ];
            }, $servicesStatement->fetchAll());
        }
        $paymentsStatement = $database->prepare(
            'SELECT payment_id, receipt_number, payment_method, amount, status,
                    is_simulated, paid_at
             FROM payments
             WHERE bill_id = :bill_id
             ORDER BY created_at DESC, payment_id DESC'
        );
        $paymentsStatement->execute(['bill_id' => $billId]);
        $payments = array_map(static function (array $payment): array {
            return [
                'payment_id' => (int) $payment['payment_id'],
                'receipt_number' => $payment['receipt_number'],
                'payment_method' => $payment['payment_method'],
                'amount' => (string) $payment['amount'],
                'status' => $payment['status'],
                'is_simulated' => (bool) $payment['is_simulated'],
                'paid_at' => $payment['paid_at'],
            ];
        }, $paymentsStatement->fetchAll());
        $schemeStatement = $database->query(
            'SELECT medical_aid_scheme_id, scheme_name
             FROM medical_aid_schemes
             WHERE is_active = 1
             ORDER BY scheme_name'
        );
        $medicalAidSchemes = array_map(static function (array $scheme): array {
            return [
                'scheme_id' => (int) $scheme['medical_aid_scheme_id'],
                'scheme_name' => $scheme['scheme_name'],
            ];
        }, $schemeStatement->fetchAll());
        $membershipStatement = $database->prepare(
            'SELECT membership.membership_id, membership.medical_aid_scheme_id,
                    scheme.scheme_name, membership.member_number, membership.member_name
             FROM patient_medical_aid_memberships membership
             INNER JOIN medical_aid_schemes scheme
                ON scheme.medical_aid_scheme_id = membership.medical_aid_scheme_id
             WHERE membership.patient_id = :patient_id
               AND membership.is_active = 1 AND scheme.is_active = 1
             ORDER BY membership.membership_id DESC'
        );
        $membershipStatement->execute(['patient_id' => (int) $visit['patient_id']]);
        $medicalAidMemberships = array_map(static function (array $membership): array {
            return [
                'membership_id' => (int) $membership['membership_id'],
                'scheme_id' => (int) $membership['medical_aid_scheme_id'],
                'scheme_name' => $membership['scheme_name'],
                'member_number' => $membership['member_number'],
                'member_name' => $membership['member_name'],
            ];
        }, $membershipStatement->fetchAll());
        $computedStatus = $visit['bill_status'] === 'cancelled'
            ? 'cancelled'
            : ((float) $balance['billed_amount'] <= 0
                ? $visit['bill_status']
                : ((float) $balance['balance'] <= 0
                    ? 'paid'
                    : ((float) $balance['approved_amount'] > 0
                        ? 'partially_paid'
                        : 'pending')));
        $bill = [
            'bill_id' => $billId,
            'bill_number' => $visit['bill_number'],
            'status' => $computedStatus,
            'can_add_services' => $visit['bill_status'] !== 'cancelled'
                && $visit['visit_status'] !== 'cancelled',
        ];
    }

    recordBillingAudit(
        $database,
        (int) $user['user_id'],
        'billing.visit_bill.viewed',
        (int) $visit['patient_id'],
        (int) $visit['visit_id']
    );
    jsonSuccess('Visit bill loaded.', [
        'patient' => [
            'patient_number' => $visit['patient_number'],
            'name' => trim(
                (string) $visit['first_name'] . ' ' . (string) $visit['last_name']
            ),
            'phone' => $visit['phone'],
            'address' => $visit['address'],
        ],
        'visit' => [
            'visit_id' => (int) $visit['visit_id'],
            'arrived_at' => $visit['arrived_at'],
            'visit_source' => $visit['visit_source'],
            'status' => $visit['visit_status'],
        ],
        'bill' => $bill,
        'totals' => $balance,
        'items' => $items,
        'available_services' => $availableServices,
        'currency' => $currencyCode === false ? null : $currencyCode,
        'payments' => $payments,
        'medical_aid_schemes' => $medicalAidSchemes,
        'medical_aid_memberships' => $medicalAidMemberships,
    ]);
}

function handleBillingBillItemCreate(string $billId): void
{
    if (!ctype_digit($billId) || (int) $billId < 1) {
        jsonError('A valid bill ID is required.', [], 422);
    }
    $database = getDatabaseConnection();
    $user = requireBillingAccess($database);
    requireCsrfRequest();
    $input = readJsonRequest();
    $feeId = $input['fee_id'] ?? null;
    $quantity = $input['quantity'] ?? 1;
    $requestId = $input['client_request_id'] ?? null;
    if ((!is_int($feeId) && !(is_string($feeId) && ctype_digit($feeId)))
        || (int) $feeId < 1) {
        jsonError('Choose an available configured service.', [
            'fee_id' => 'Choose an active fee from the service list.',
        ], 422);
    }
    if ((!is_int($quantity) && !(is_string($quantity) && ctype_digit($quantity)))
        || (int) $quantity < 1 || (int) $quantity > 100) {
        jsonError('Enter a valid service quantity.', [
            'quantity' => 'Quantity must be a whole number from 1 to 100.',
        ], 422);
    }
    if (!is_string($requestId)
        || !preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $requestId
        )) {
        jsonError('A valid service request ID is required.', [
            'client_request_id' => 'Retry with the same unique request ID.',
        ], 422);
    }

    try {
        $database->beginTransaction();
        $billStatement = $database->prepare(
            'SELECT bill.bill_id, bill.visit_id, bill.status AS bill_status,
                    visit.patient_id, visit.status AS visit_status
             FROM bills bill
             INNER JOIN visits visit ON visit.visit_id = bill.visit_id
             WHERE bill.bill_id = :bill_id
             LIMIT 1 FOR UPDATE'
        );
        $billStatement->execute(['bill_id' => (int) $billId]);
        $bill = $billStatement->fetch();
        if ($bill === false) {
            $database->rollBack();
            jsonError('Bill not found.', [], 404);
        }
        if ($bill['bill_status'] === 'cancelled'
            || $bill['visit_status'] === 'cancelled') {
            $database->rollBack();
            jsonError('This bill is closed and cannot accept new charges.', [], 409);
        }

        $existingStatement = $database->prepare(
            'SELECT bill_item_id, bill_id, fee_id, quantity
             FROM bill_items
             WHERE client_request_id = :client_request_id
             LIMIT 1'
        );
        $existingStatement->execute(['client_request_id' => $requestId]);
        $existing = $existingStatement->fetch();
        if ($existing !== false) {
            if ((int) $existing['bill_id'] !== (int) $billId
                || (int) $existing['fee_id'] !== (int) $feeId
                || (int) $existing['quantity'] !== (int) $quantity) {
                $database->rollBack();
                jsonError('This request ID was already used for a different charge.', [], 409);
            }
            $balance = billingGetBillBalance($database, (int) $billId);
            $total = billingRefreshBillTotal($database, (int) $billId);
            $database->commit();
            jsonSuccess('This service charge was already recorded.', [
                'duplicate' => true,
                'bill_item_id' => (int) $existing['bill_item_id'],
                'bill_total' => $total,
                'previously_paid' => $balance['approved_amount'],
                'balance' => $balance['balance'],
            ]);
        }

        $feeStatement = $database->prepare(
            'SELECT fee_id, fee_code, description, default_amount, currency_code,
                    is_demo, is_active, cashier_addable
             FROM fee_catalog
             WHERE fee_id = :fee_id
             LIMIT 1 FOR UPDATE'
        );
        $feeStatement->execute(['fee_id' => (int) $feeId]);
        $fee = $feeStatement->fetch();
        if ($fee === false || !(bool) $fee['is_active']
            || !(bool) $fee['cashier_addable']
            || !billingFeeAllowsCashierAdd((string) $fee['fee_code'])
            || (float) $fee['default_amount'] <= 0) {
            $database->rollBack();
            jsonError('This service is not available for cashier addition.', [], 409);
        }
        $insert = $database->prepare(
            'INSERT INTO bill_items
                (bill_id, fee_id, charge_type, description, quantity, unit_amount,
                 client_request_id, created_by_user_id)
             VALUES
                (:bill_id, :fee_id, \'other\', :description, :quantity, :unit_amount,
                 :client_request_id, :user_id)'
        );
        $insert->execute([
            'bill_id' => (int) $billId,
            'fee_id' => (int) $fee['fee_id'],
            'description' => $fee['description'],
            'quantity' => (int) $quantity,
            'unit_amount' => (string) $fee['default_amount'],
            'client_request_id' => $requestId,
            'user_id' => (int) $user['user_id'],
        ]);
        $billItemId = (int) $database->lastInsertId();
        $total = billingRefreshBillTotal($database, (int) $billId);
        $balance = billingGetBillBalance($database, (int) $billId);
        recordBillingAudit(
            $database,
            (int) $user['user_id'],
            'billing.service_added:' . $fee['fee_code'],
            (int) $bill['patient_id'],
            (int) $bill['visit_id']
        );
        $database->commit();
    } catch (Throwable $exception) {
        if ($database->inTransaction()) {
            $database->rollBack();
        }
        error_log('Cashier service charge failed: ' . $exception->getMessage());
        jsonError('Unable to add this service charge right now.', [], 500);
    }
    jsonSuccess('Service added to the visit bill.', [
        'duplicate' => false,
        'bill_item_id' => $billItemId,
        'fee_code' => $fee['fee_code'],
        'description' => $fee['description'],
        'quantity' => (int) $quantity,
        'unit_amount' => (string) $fee['default_amount'],
        'currency' => $fee['currency_code'],
        'bill_total' => $total,
        'previously_paid' => $balance['approved_amount'],
        'balance' => $balance['balance'],
    ], 201);
}

function handleBillingBillItemVoid(string $billId, string $billItemId): void
{
    if (!ctype_digit($billId) || (int) $billId < 1
        || !ctype_digit($billItemId) || (int) $billItemId < 1) {
        jsonError('Valid bill and bill-item IDs are required.', [], 422);
    }
    $database = getDatabaseConnection();
    $user = requireBillingAccess($database);
    requireCsrfRequest();

    try {
        $database->beginTransaction();
        $billStatement = $database->prepare(
            'SELECT bill.bill_id, bill.status, visit.patient_id, visit.visit_id,
                    visit.status AS visit_status
             FROM bills bill
             INNER JOIN visits visit ON visit.visit_id = bill.visit_id
             WHERE bill.bill_id = :bill_id
             LIMIT 1 FOR UPDATE'
        );
        $billStatement->execute(['bill_id' => (int) $billId]);
        $bill = $billStatement->fetch();
        if ($bill === false) {
            $database->rollBack();
            jsonError('Bill not found.', [], 404);
        }
        if ($bill['status'] === 'cancelled' || $bill['visit_status'] === 'cancelled') {
            $database->rollBack();
            jsonError('This bill is closed and cannot be changed.', [], 409);
        }
        $paymentStatement = $database->prepare(
            'SELECT 1 FROM payments
             WHERE bill_id = :bill_id AND status = \'approved\'
             LIMIT 1 FOR UPDATE'
        );
        $paymentStatement->execute(['bill_id' => (int) $billId]);
        if ($paymentStatement->fetchColumn() !== false) {
            $database->rollBack();
            jsonError('A service cannot be removed after a payment has been recorded.', [], 409);
        }
        $itemStatement = $database->prepare(
            'SELECT item.bill_item_id, item.is_voided, item.charge_type,
                    item.created_by_user_id, fee.cashier_addable
             FROM bill_items item
             LEFT JOIN fee_catalog fee ON fee.fee_id = item.fee_id
             WHERE item.bill_item_id = :bill_item_id
               AND item.bill_id = :bill_id
             LIMIT 1 FOR UPDATE'
        );
        $itemStatement->execute([
            'bill_item_id' => (int) $billItemId,
            'bill_id' => (int) $billId,
        ]);
        $item = $itemStatement->fetch();
        if ($item === false) {
            $database->rollBack();
            jsonError('Bill item not found.', [], 404);
        }
        if ($item['charge_type'] !== 'other'
            || $item['created_by_user_id'] === null
            || !(bool) $item['cashier_addable']) {
            $database->rollBack();
            jsonError('Department-managed services cannot be removed here.', [], 403);
        }
        if (!(bool) $item['is_voided']) {
            $void = $database->prepare(
                'UPDATE bill_items
                 SET is_voided = 1, voided_at = CURRENT_TIMESTAMP,
                     voided_by_user_id = :user_id
                 WHERE bill_item_id = :bill_item_id'
            );
            $void->execute([
                'user_id' => (int) $user['user_id'],
                'bill_item_id' => (int) $billItemId,
            ]);
            recordBillingAudit(
                $database,
                (int) $user['user_id'],
                'billing.service_voided:' . (int) $billItemId,
                (int) $bill['patient_id'],
                (int) $bill['visit_id']
            );
        }
        $total = billingRefreshBillTotal($database, (int) $billId);
        $balance = billingGetBillBalance($database, (int) $billId);
        $database->commit();
    } catch (Throwable $exception) {
        if ($database->inTransaction()) {
            $database->rollBack();
        }
        error_log('Cashier service removal failed: ' . $exception->getMessage());
        jsonError('Unable to remove this service charge right now.', [], 500);
    }
    jsonSuccess('Service removed from the current bill.', [
        'bill_total' => $total,
        'previously_paid' => $balance['approved_amount'],
        'balance' => $balance['balance'],
    ]);
}

function handleBillingPaymentCreate(string $billId): void
{
    if (!ctype_digit($billId) || (int) $billId < 1) {
        jsonError('A valid bill ID is required.', [], 422);
    }

    $database = getDatabaseConnection();
    $user = requireBillingAccess($database);
    requireCsrfRequest();
    $input = readJsonRequest();
    $paymentMethod = $input['payment_method'] ?? null;
    $requestedAmount = $input['amount'] ?? null;
    $requestId = $input['client_request_id'] ?? null;
    $membershipId = $input['membership_id'] ?? null;
    $schemeId = $input['medical_aid_scheme_id'] ?? null;
    $memberNumber = $input['member_number'] ?? null;
    $memberName = $input['member_name'] ?? null;
    $medicalAidOutcome = $input['medical_aid_outcome'] ?? 'approved';
    if (!is_string($paymentMethod)
        || !in_array($paymentMethod, ['cash', 'card', 'medical_aid'], true)) {
        jsonError('Choose cash, card, or medical aid.', [
            'payment_method' => 'Choose an available payment method.',
        ], 422);
    }
    if ($paymentMethod === 'medical_aid'
        && (!is_string($medicalAidOutcome)
            || !in_array($medicalAidOutcome, ['approved', 'rejected'], true))) {
        jsonError('Choose a simulated medical-aid outcome.', [
            'medical_aid_outcome' => 'Choose approval or rejection.',
        ], 422);
    }
    if ($requestedAmount !== null
        && (!is_string($requestedAmount)
            || !preg_match('/^\d{1,10}(?:\.\d{1,2})?$/', $requestedAmount)
            || (float) $requestedAmount <= 0)) {
        jsonError('Enter a valid payment amount.', [
            'amount' => 'Enter a positive amount with up to two decimal places.',
        ], 422);
    }
    if (!is_string($requestId)
        || !preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $requestId
        )) {
        jsonError('A valid payment request ID is required.', [
            'client_request_id' => 'Retry with the same unique request ID.',
        ], 422);
    }
    if ($membershipId !== null
        && (!is_string($membershipId) || !ctype_digit($membershipId) || (int) $membershipId < 1)) {
        jsonError('Choose a valid saved medical-aid membership.', [], 422);
    }
    if ($paymentMethod === 'medical_aid') {
        if ($membershipId === null
            && (!is_string($schemeId) || !ctype_digit($schemeId) || (int) $schemeId < 1
                || !is_string($memberNumber) || trim($memberNumber) === ''
                || strlen(trim($memberNumber)) > 100)) {
            jsonError('Provide a scheme and member number for the simulated medical-aid payment.', [
                'medical_aid' => 'Select a saved membership or enter the scheme and member number.',
            ], 422);
        }
        if ($memberName !== null
            && (!is_string($memberName) || strlen(trim($memberName)) > 150)) {
            jsonError('The medical-aid member name is invalid.', [
                'member_name' => 'Use no more than 150 characters.',
            ], 422);
        }
    }
    $amountHashPart = $requestedAmount === null
        ? 'full-balance'
        : number_format((float) $requestedAmount, 2, '.', '');
    $requestHash = hash(
        'sha256',
        implode('|', [
            (int) $billId,
            $paymentMethod,
            $amountHashPart,
            $paymentMethod === 'medical_aid' ? $medicalAidOutcome : 'approved',
            (string) $membershipId,
            (string) $schemeId,
            is_string($memberNumber) ? trim($memberNumber) : '',
            is_string($memberName) ? trim($memberName) : '',
        ])
    );

    try {
        $database->beginTransaction();
        $billStatement = $database->prepare(
            'SELECT bill.bill_id, bill.bill_number, bill.status,
                    visit.visit_id, visit.patient_id, visit.status AS visit_status,
                    patient.first_name, patient.last_name
             FROM bills bill
             INNER JOIN visits visit ON visit.visit_id = bill.visit_id
             INNER JOIN patients patient ON patient.patient_id = visit.patient_id
             WHERE bill.bill_id = :bill_id
             LIMIT 1
             FOR UPDATE'
        );
        $billStatement->execute(['bill_id' => (int) $billId]);
        $bill = $billStatement->fetch();
        if ($bill === false) {
            $database->rollBack();
            jsonError('Bill not found.', [], 404);
        }
        if ($bill['status'] === 'cancelled') {
            $database->rollBack();
            jsonError('Payments cannot be recorded against a cancelled bill.', [], 409);
        }
        if ($bill['visit_status'] === 'cancelled') {
            $database->rollBack();
            jsonError('Payments cannot be recorded against a cancelled visit.', [], 409);
        }

        $existingStatement = $database->prepare(
            'SELECT payment_id, bill_id, client_request_hash, receipt_number, amount,
                    status,
                    payment_method, is_simulated, paid_at
             FROM payments
             WHERE client_request_id = :request_id
             LIMIT 1'
        );
        $existingStatement->execute(['request_id' => $requestId]);
        $existingPayment = $existingStatement->fetch();
        if ($existingPayment !== false) {
            if ((int) $existingPayment['bill_id'] !== (int) $billId
                || !hash_equals((string) $existingPayment['client_request_hash'], $requestHash)) {
                $database->rollBack();
                jsonError('This payment request ID was already used for a different payment.', [], 409);
            }
            $currentBalance = billingGetBillBalance($database, (int) $billId);
            $database->commit();
            jsonSuccess('This payment was already recorded.', [
                'duplicate' => true,
                'payment_id' => (int) $existingPayment['payment_id'],
                'receipt_number' => $existingPayment['receipt_number'],
                'amount' => (string) $existingPayment['amount'],
                'payment_status' => $existingPayment['status'],
                'payment_method' => $existingPayment['payment_method'],
                'is_simulated' => (bool) $existingPayment['is_simulated'],
                'paid_at' => $existingPayment['paid_at'],
                'balance' => $currentBalance['balance'],
            ]);
        }

        $balance = billingGetBillBalance($database, (int) $billId);
        $availableCents = (int) round((float) $balance['balance'] * 100);
        if ($availableCents <= 0) {
            $database->rollBack();
            jsonError('This bill has no outstanding balance.', [], 409);
        }
        $amount = $requestedAmount === null
            ? number_format($availableCents / 100, 2, '.', '')
            : number_format((float) $requestedAmount, 2, '.', '');
        $amountCents = (int) round((float) $amount * 100);
        if ($amountCents <= 0 || $amountCents > $availableCents) {
            $database->rollBack();
            jsonError('Payment cannot exceed the outstanding balance.', [
                'amount' => 'Enter an amount no greater than the current balance.',
            ], 422);
        }

        $savedMembershipId = null;
        if ($paymentMethod === 'medical_aid') {
            if ($membershipId !== null) {
                $membershipStatement = $database->prepare(
                    'SELECT membership.membership_id
                     FROM patient_medical_aid_memberships membership
                     INNER JOIN medical_aid_schemes scheme
                        ON scheme.medical_aid_scheme_id = membership.medical_aid_scheme_id
                     WHERE membership.membership_id = :membership_id
                       AND membership.patient_id = :patient_id
                       AND membership.is_active = 1 AND scheme.is_active = 1
                     LIMIT 1
                     FOR UPDATE'
                );
                $membershipStatement->execute([
                    'membership_id' => (int) $membershipId,
                    'patient_id' => (int) $bill['patient_id'],
                ]);
                $savedMembershipId = $membershipStatement->fetchColumn();
                if ($savedMembershipId === false) {
                    $database->rollBack();
                    jsonError('The selected medical-aid membership is not active for this patient.', [], 422);
                }
            } else {
                $schemeStatement = $database->prepare(
                    'SELECT medical_aid_scheme_id
                     FROM medical_aid_schemes
                     WHERE medical_aid_scheme_id = :scheme_id AND is_active = 1
                     LIMIT 1
                     FOR UPDATE'
                );
                $schemeStatement->execute(['scheme_id' => (int) $schemeId]);
                if ($schemeStatement->fetchColumn() === false) {
                    $database->rollBack();
                    jsonError('The selected medical-aid scheme is unavailable.', [], 422);
                }
                $membershipLookup = $database->prepare(
                    'SELECT membership_id
                     FROM patient_medical_aid_memberships
                     WHERE patient_id = :patient_id
                       AND medical_aid_scheme_id = :scheme_id
                       AND member_number = :member_number
                     ORDER BY membership_id
                     LIMIT 1
                     FOR UPDATE'
                );
                $membershipLookup->execute([
                    'patient_id' => (int) $bill['patient_id'],
                    'scheme_id' => (int) $schemeId,
                    'member_number' => trim((string) $memberNumber),
                ]);
                $savedMembershipId = $membershipLookup->fetchColumn();
                if ($savedMembershipId === false) {
                    $membershipInsert = $database->prepare(
                        'INSERT INTO patient_medical_aid_memberships
                            (patient_id, medical_aid_scheme_id, member_number,
                             member_name, is_active)
                         VALUES
                            (:patient_id, :scheme_id, :member_number, :member_name, 1)'
                    );
                    $membershipInsert->execute([
                        'patient_id' => (int) $bill['patient_id'],
                        'scheme_id' => (int) $schemeId,
                        'member_number' => trim((string) $memberNumber),
                        'member_name' => is_string($memberName) && trim($memberName) !== ''
                            ? trim($memberName)
                            : trim(
                                (string) ($bill['first_name'] ?? '') . ' '
                                . (string) ($bill['last_name'] ?? '')
                            ),
                    ]);
                    $savedMembershipId = (int) $database->lastInsertId();
                } else {
                    $database->prepare(
                        'UPDATE patient_medical_aid_memberships
                         SET is_active = 1
                         WHERE membership_id = :membership_id'
                    )->execute(['membership_id' => (int) $savedMembershipId]);
                }
            }
            $savedMembershipId = (int) $savedMembershipId;
        }

        if ($paymentMethod === 'medical_aid' && $medicalAidOutcome === 'rejected') {
            $receiptNumber = billingGenerateReceiptNumber($database);
            $rejectedInsert = $database->prepare(
                'INSERT INTO payments
                    (bill_id, recorded_by_user_id, membership_id, payment_method, amount,
                     status, receipt_number, is_simulated, client_request_id,
                     client_request_hash, paid_at)
                 VALUES
                    (:bill_id, :user_id, :membership_id, :payment_method, :amount,
                     \'rejected\', :receipt_number, 1, :request_id, :request_hash,
                     CURRENT_TIMESTAMP)'
            );
            $rejectedInsert->execute([
                'bill_id' => (int) $billId,
                'user_id' => (int) $user['user_id'],
                'membership_id' => $savedMembershipId,
                'payment_method' => $paymentMethod,
                'amount' => $amount,
                'receipt_number' => $receiptNumber,
                'request_id' => $requestId,
                'request_hash' => $requestHash,
            ]);
            $paymentId = (int) $database->lastInsertId();
            recordBillingAudit(
                $database,
                (int) $user['user_id'],
                'billing.medical_aid.rejected.simulated',
                (int) $bill['patient_id'],
                (int) $bill['visit_id']
            );
            $billTotal = billingRefreshBillTotal($database, (int) $billId);
            $updatedBalance = billingGetBillBalance($database, (int) $billId);
            $billStatus = (float) $updatedBalance['balance'] <= 0
                ? 'paid'
                : ((float) $updatedBalance['approved_amount'] > 0
                    ? 'partially_paid'
                    : 'pending');
            $database->commit();
            jsonSuccess('Simulated medical-aid payment was rejected; no payment was applied.', [
                'duplicate' => false,
                'payment_id' => $paymentId,
                'receipt_number' => $receiptNumber,
                'bill_id' => (int) $billId,
                'bill_number' => $bill['bill_number'],
                'bill_total' => $billTotal,
                'amount' => $amount,
                'payment_method' => $paymentMethod,
                'is_simulated' => true,
                'payment_status' => 'rejected',
                'bill_status' => $billStatus,
                'approved_payments' => $updatedBalance['approved_amount'],
                'balance' => $updatedBalance['balance'],
                'visit_id' => (int) $bill['visit_id'],
            ], 201);
        }

        $receiptNumber = billingGenerateReceiptNumber($database);
        $paymentInsert = $database->prepare(
            'INSERT INTO payments
                (bill_id, recorded_by_user_id, membership_id, payment_method, amount, status,
                 receipt_number, is_simulated, client_request_id,
                 client_request_hash, paid_at)
             VALUES
                (:bill_id, :user_id, :membership_id, :payment_method, :amount, \'approved\',
                 :receipt_number, :is_simulated, :request_id, :request_hash,
                 CURRENT_TIMESTAMP)'
        );
        $paymentInsert->execute([
            'bill_id' => (int) $billId,
            'user_id' => (int) $user['user_id'],
            'membership_id' => $savedMembershipId,
            'payment_method' => $paymentMethod,
            'amount' => $amount,
            'receipt_number' => $receiptNumber,
            'is_simulated' => $paymentMethod === 'cash' ? 0 : 1,
            'request_id' => $requestId,
            'request_hash' => $requestHash,
        ]);
        $paymentId = (int) $database->lastInsertId();
        $updatedBalance = billingGetBillBalance($database, (int) $billId);
        $billTotal = billingRefreshBillTotal($database, (int) $billId);
        $billStatus = (float) $updatedBalance['balance'] <= 0
            ? 'paid'
            : 'partially_paid';
        recordBillingAudit(
            $database,
            (int) $user['user_id'],
            'billing.payment.recorded',
            (int) $bill['patient_id'],
            (int) $bill['visit_id']
        );
        if ($paymentMethod === 'medical_aid') {
            recordBillingAudit(
                $database,
                (int) $user['user_id'],
                'billing.medical_aid.approved.simulated',
                (int) $bill['patient_id'],
                (int) $bill['visit_id']
            );
        }
        $database->commit();
    } catch (Throwable $exception) {
        if ($database->inTransaction()) {
            $database->rollBack();
        }
        error_log('Cashier payment recording failed: ' . $exception->getMessage());
        jsonError('Unable to record the payment right now.', [], 500);
    }

    jsonSuccess('Payment recorded.', [
        'duplicate' => false,
        'payment_id' => $paymentId,
        'receipt_number' => $receiptNumber,
        'bill_id' => (int) $billId,
        'bill_number' => $bill['bill_number'],
        'bill_total' => $billTotal,
        'amount' => $amount,
        'payment_method' => $paymentMethod,
        'is_simulated' => $paymentMethod !== 'cash',
        'membership_id' => $savedMembershipId,
        'payment_status' => 'approved',
        'bill_status' => $billStatus,
        'approved_payments' => $updatedBalance['approved_amount'],
        'balance' => $updatedBalance['balance'],
        'visit_id' => (int) $bill['visit_id'],
    ], 201);
}

function recordBillingAudit(
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
        'SELECT fee_id, fee_code, description, default_amount, currency_code, is_demo
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
            'ticket_currency' => $ticketFee['currency_code'],
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
        'currency' => $ticketFee['currency_code'],
        'ticket_fee' => [
            'fee_code' => $ticketFee['fee_code'],
            'description' => $ticketFee['description'],
            'amount' => (string) $ticketFee['default_amount'],
            'currency' => $ticketFee['currency_code'],
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
    $user = requireBillingAccess($database, true);
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
        'SELECT fee_code, description, default_amount, currency_code, is_demo
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
            'currency' => $ticketFee['currency_code'],
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
    $user = requireBillingAccess($database, true);
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
    $user = requireBillingAccess($database, true);
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
    $currencyCode = $database->query(
        'SELECT currency_code FROM fee_catalog ORDER BY fee_id LIMIT 1'
    )->fetchColumn();
    $itemTotalStatement = $database->prepare(
        'SELECT COALESCE(SUM(quantity * unit_amount), 0.00)
         FROM bill_items
         WHERE bill_id = :bill_id AND created_at <= :paid_at AND is_voided = 0'
    );
    $itemTotalStatement->execute([
        'bill_id' => (int) $payment['bill_id'],
        'paid_at' => $payment['paid_at'],
    ]);
    $paidItemTotal = (float) $itemTotalStatement->fetchColumn();
    $previousPaymentsStatement = $database->prepare(
        'SELECT COALESCE(SUM(amount), 0.00)
         FROM payments
         WHERE bill_id = :bill_id
           AND status = \'approved\'
           AND payment_id < :payment_id'
    );
    $previousPaymentsStatement->execute([
        'bill_id' => (int) $payment['bill_id'],
        'payment_id' => (int) $payment['payment_id'],
    ]);
    $previousPayments = (float) $previousPaymentsStatement->fetchColumn();

    $itemsStatement = $database->prepare(
        'SELECT charge_type, description, quantity, unit_amount,
                quantity * unit_amount AS line_total
         FROM bill_items
         WHERE bill_id = :bill_id AND created_at <= :paid_at AND is_voided = 0
         ORDER BY bill_item_id'
    );
    $itemsStatement->execute([
        'bill_id' => (int) $payment['bill_id'],
        'paid_at' => $payment['paid_at'],
    ]);
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
        'currency' => $currencyCode === false ? null : $currencyCode,
        'payment' => [
            'payment_id' => (int) $payment['payment_id'],
            'receipt_number' => $payment['receipt_number'],
            'payment_method' => $payment['payment_method'],
            'amount' => (string) $payment['amount'],
            'is_simulated' => (bool) $payment['is_simulated'],
            'paid_at' => $payment['paid_at'],
            'cashier_name' => $payment['cashier_name'],
            'previous_payments' => number_format($previousPayments, 2, '.', ''),
            'remaining_balance' => number_format(
                max(0, $paidItemTotal - $previousPayments - (float) $payment['amount']),
                2,
                '.',
                ''
            ),
            'status' => 'approved',
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
