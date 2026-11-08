<?php

declare(strict_types=1);

function monitoringStatusRoles(): array
{
    return [
        'system_administrator',
        'emergency_doctor',
        'emergency_nurse',
        'nurse',
        'general_doctor',
        'resident_specialist',
        'locum_specialist',
        'testing_desk_clerk',
        'laboratory_technician',
        'radiographer',
        'cardiac_technician',
        'therapist',
        'pharmacist',
    ];
}

function monitoringDateIsValid(string $value): bool
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    $errors = DateTimeImmutable::getLastErrors();

    return $date !== false
        && ($errors === false
            || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
        && $date->format('Y-m-d') === $value;
}

function monitoringDateFilters(): array
{
    $from = $_GET['from'] ?? '';
    $to = $_GET['to'] ?? '';
    if (!is_string($from) || !is_string($to)) {
        jsonError('Date filters must be strings.', [
            'from' => 'Enter dates in YYYY-MM-DD format.',
            'to' => 'Enter dates in YYYY-MM-DD format.',
        ], 422);
    }

    $from = trim($from);
    $to = trim($to);
    if (($from === '') !== ($to === '')) {
        jsonError('Enter both dates or leave both blank.', [
            'date_range' => 'Choose both a start and end date.',
        ], 422);
    }
    if ($from !== '' && (!monitoringDateIsValid($from)
        || !monitoringDateIsValid($to) || $from > $to)) {
        jsonError('Choose a valid date range.', [
            'date_range' => 'Use YYYY-MM-DD dates with the start on or before the end.',
        ], 422);
    }

    return $from === '' ? [] : ['from' => $from, 'to' => $to];
}

function monitoringVisitEvents(PDO $database, int $visitId): array
{
    $sql = "
        SELECT arrived_at AS event_at, 'arrival' AS event_type,
               'Visit arrived' AS summary
        FROM visits WHERE visit_id = :arrival_visit
        UNION ALL
        SELECT assessed_at, 'assessment', 'Nurse assessment recorded'
        FROM nurse_assessments WHERE visit_id = :assessment_visit
        UNION ALL
        SELECT ordered_at, 'test_order',
               CONCAT('Test ordered: ', REPLACE(test_type, '_', ' '), ' (', status, ')')
        FROM test_orders WHERE visit_id = :orders_visit
        UNION ALL
        SELECT result.recorded_at, 'test_result',
               CONCAT('Test result recorded: ', REPLACE(test.test_type, '_', ' '),
                      ' (', result.status, ')')
        FROM test_results result
        INNER JOIN test_orders test ON test.test_order_id = result.test_order_id
        WHERE test.visit_id = :results_visit
        UNION ALL
        SELECT consulted_at, 'consultation',
               CONCAT(UPPER(SUBSTRING(consultation_type, 1, 1)),
                      SUBSTRING(consultation_type, 2), ' consultation')
        FROM consultations WHERE visit_id = :consultations_visit
        UNION ALL
        SELECT created_at, 'specialist_referral',
               CONCAT('Specialist referral (', status, ')')
        FROM specialist_referrals WHERE visit_id = :specialist_visit
        UNION ALL
        SELECT created_at, 'outside_referral',
               CONCAT('Outside referral (', status, ')')
        FROM outside_referrals WHERE visit_id = :outside_visit
        UNION ALL
        SELECT created_at, 'therapy_referral',
               CONCAT('Therapy referral: ', therapy_type, ' (', status, ')')
        FROM therapy_referrals WHERE visit_id = :therapy_referral_visit
        UNION ALL
        SELECT session.session_at, 'therapy_session', 'Therapy session recorded'
        FROM therapy_sessions session
        INNER JOIN therapy_referrals referral
            ON referral.therapy_referral_id = session.therapy_referral_id
        WHERE referral.visit_id = :therapy_session_visit
        UNION ALL
        SELECT created_at, 'prescription',
               CONCAT('Prescription created (', status, ')')
        FROM prescriptions WHERE visit_id = :prescription_visit
        UNION ALL
        SELECT payment.paid_at, 'payment',
               CONCAT('Payment approved: ', payment.payment_method, ' ',
                      FORMAT(payment.amount, 2))
        FROM payments payment
        INNER JOIN bills bill ON bill.bill_id = payment.bill_id
        WHERE bill.visit_id = :payment_visit
          AND payment.status = 'approved' AND payment.paid_at IS NOT NULL
        UNION ALL
        SELECT dispensed_at, 'dispensing', 'Medication dispensed'
        FROM dispensings WHERE visit_id = :dispensing_visit
        ORDER BY event_at, event_type";
    $statement = $database->prepare($sql);
    $statement->execute([
        'arrival_visit' => $visitId,
        'assessment_visit' => $visitId,
        'orders_visit' => $visitId,
        'results_visit' => $visitId,
        'consultations_visit' => $visitId,
        'specialist_visit' => $visitId,
        'outside_visit' => $visitId,
        'therapy_referral_visit' => $visitId,
        'therapy_session_visit' => $visitId,
        'prescription_visit' => $visitId,
        'payment_visit' => $visitId,
        'dispensing_visit' => $visitId,
    ]);

    $events = $statement->fetchAll();

    $ticketStatement = $database->prepare(
        'SELECT bill_id,
                (SELECT COALESCE(SUM(item.quantity * item.unit_amount), 0)
                 FROM bill_items item
                 WHERE item.bill_id = bill.bill_id
                   AND item.is_voided = 0
                   AND item.charge_type = \'ticket\') AS ticket_total
         FROM bills bill
         WHERE bill.visit_id = :visit_id
         LIMIT 1'
    );
    $ticketStatement->execute(['visit_id' => $visitId]);
    $bill = $ticketStatement->fetch();
    if ($bill !== false && (float) $bill['ticket_total'] > 0) {
        $paymentStatement = $database->prepare(
            'SELECT paid_at, amount
             FROM payments
             WHERE bill_id = :bill_id AND status = \'approved\' AND paid_at IS NOT NULL
             ORDER BY paid_at, payment_id'
        );
        $paymentStatement->execute(['bill_id' => (int) $bill['bill_id']]);
        $approvedTotal = 0.0;
        foreach ($paymentStatement->fetchAll() as $payment) {
            $approvedTotal += (float) $payment['amount'];
            if ($approvedTotal >= (float) $bill['ticket_total']) {
                $events[] = [
                    'event_at' => $payment['paid_at'],
                    'event_type' => 'ticket_paid',
                    'summary' => 'Ticket fee paid',
                ];
                break;
            }
        }
    }

    usort($events, static function (array $left, array $right): int {
        return [$left['event_at'], $left['event_type']]
            <=> [$right['event_at'], $right['event_type']];
    });

    return $events;
}

function handleVisitStatusGet(): void
{
    $database = getDatabaseConnection();
    $user = getAuthenticatedUser($database);
    if ($user === null) {
        jsonError('Authentication is required.', [], 401);
    }
    if (!in_array($user['role_name'], monitoringStatusRoles(), true)) {
        jsonError('Clinical or administrator access is required.', [], 403);
    }

    $search = $_GET['search'] ?? '';
    if (!is_string($search) || trim($search) === '' || strlen(trim($search)) > 80) {
        jsonError('Enter a patient number or visit ID.', [
            'search' => 'Search must be 1-80 characters.',
        ], 422);
    }
    $search = trim($search);
    $visitId = ctype_digit($search) && (int) $search > 0 ? (int) $search : 0;
    $statement = $database->prepare(
        'SELECT visit.visit_id, visit.status, visit.arrived_at,
                patient.patient_number, patient.first_name, patient.last_name
         FROM visits visit
         INNER JOIN patients patient ON patient.patient_id = visit.patient_id
         WHERE visit.visit_id = :visit_id
            OR patient.patient_number = :patient_number
         ORDER BY (visit.visit_id = :preferred_visit_id) DESC,
                  visit.arrived_at DESC, visit.visit_id DESC
         LIMIT 1'
    );
    $statement->execute([
        'visit_id' => $visitId,
        'patient_number' => $search,
        'preferred_visit_id' => $visitId,
    ]);
    $visit = $statement->fetch();
    if ($visit === false) {
        jsonError('No matching visit was found.', [], 404);
    }

    jsonSuccess('Visit status loaded.', [
        'visit' => [
            'visit_id' => (int) $visit['visit_id'],
            'patient_number' => $visit['patient_number'],
            'patient_name' => trim(
                (string) $visit['first_name'] . ' ' . (string) $visit['last_name']
            ),
            'arrived_at' => $visit['arrived_at'],
            'status' => $visit['status'],
            'timeline' => monitoringVisitEvents($database, (int) $visit['visit_id']),
        ],
    ]);
}

function handleAdminAuditLogGet(): void
{
    $database = getDatabaseConnection();
    requireAdministrator($database);

    $userFilter = $_GET['user'] ?? '';
    $patientFilter = $_GET['patient'] ?? '';
    if (!is_string($userFilter) || !is_string($patientFilter)
        || strlen($userFilter) > 100 || strlen($patientFilter) > 100) {
        jsonError('Audit filters must be text of 100 characters or fewer.', [
            'filters' => 'Shorten the user or patient filter.',
        ], 422);
    }
    $userFilter = trim($userFilter);
    $patientFilter = trim($patientFilter);
    $dateFilters = monitoringDateFilters();

    $sql = 'SELECT audit.audit_id, audit.action, audit.visit_id, audit.created_at,
                   staff.user_id, staff.username, staff.display_name,
                   patient.patient_id, patient.patient_number,
                   patient.first_name, patient.last_name
            FROM audit_log audit
            LEFT JOIN users staff ON staff.user_id = audit.user_id
            LEFT JOIN patients patient ON patient.patient_id = audit.patient_id
            WHERE 1 = 1';
    $parameters = [];
    if ($userFilter !== '') {
        $sql .= ' AND (staff.display_name LIKE :user_name
                       OR staff.username LIKE :username
                       OR CAST(staff.user_id AS CHAR) LIKE :user_id)';
        $pattern = '%' . $userFilter . '%';
        $parameters += [
            'user_name' => $pattern,
            'username' => $pattern,
            'user_id' => $pattern,
        ];
    }
    if ($patientFilter !== '') {
        $sql .= ' AND (patient.patient_number LIKE :patient_number
                       OR patient.first_name LIKE :patient_first_name
                       OR patient.last_name LIKE :patient_last_name
                       OR CAST(patient.patient_id AS CHAR) LIKE :patient_id)';
        $pattern = '%' . $patientFilter . '%';
        $parameters += [
            'patient_number' => $pattern,
            'patient_first_name' => $pattern,
            'patient_last_name' => $pattern,
            'patient_id' => $pattern,
        ];
    }
    if ($dateFilters !== []) {
        $sql .= ' AND audit.created_at >= :from_date
                  AND audit.created_at < DATE_ADD(:to_date, INTERVAL 1 DAY)';
        $parameters += [
            'from_date' => $dateFilters['from'],
            'to_date' => $dateFilters['to'],
        ];
    }
    $sql .= ' ORDER BY audit.created_at DESC, audit.audit_id DESC LIMIT 500';
    $statement = $database->prepare($sql);
    $statement->execute($parameters);

    $entries = array_map(static function (array $row): array {
        return [
            'audit_id' => (int) $row['audit_id'],
            'action' => $row['action'],
            'visit_id' => $row['visit_id'] === null ? null : (int) $row['visit_id'],
            'created_at' => $row['created_at'],
            'user_id' => $row['user_id'] === null ? null : (int) $row['user_id'],
            'username' => $row['username'],
            'user_name' => $row['display_name'],
            'patient_id' => $row['patient_id'] === null
                ? null
                : (int) $row['patient_id'],
            'patient_number' => $row['patient_number'],
            'patient_name' => trim(
                (string) $row['first_name'] . ' ' . (string) $row['last_name']
            ),
        ];
    }, $statement->fetchAll());

    jsonSuccess('Audit entries loaded.', ['entries' => $entries]);
}

function handleAdminReportsGet(): void
{
    $database = getDatabaseConnection();
    requireAdministrator($database);
    $dateFilters = monitoringDateFilters();
    $dateClause = '';
    $parameters = [];
    if ($dateFilters !== []) {
        $dateClause = ' AND %s >= :from_date AND %s < DATE_ADD(:to_date, INTERVAL 1 DAY)';
        $parameters = [
            'from_date' => $dateFilters['from'],
            'to_date' => $dateFilters['to'],
        ];
    }

    $queryRows = static function (
        PDO $database,
        string $sql,
        array $parameters
    ): array {
        $statement = $database->prepare($sql);
        $statement->execute($parameters);
        return $statement->fetchAll();
    };

    $patientsDateFilter = $dateFilters === []
        ? ''
        : sprintf($dateClause, 'visit.arrived_at', 'visit.arrived_at');
    $testsDateFilter = $dateFilters === []
        ? ''
        : sprintf($dateClause, 'test.ordered_at', 'test.ordered_at');
    $specialistDateFilter = $dateFilters === []
        ? ''
        : sprintf($dateClause, 'created_at', 'created_at');
    $outsideDateFilter = $dateFilters === []
        ? ''
        : sprintf($dateClause, 'created_at', 'created_at');
    $paymentsDateFilter = $dateFilters === []
        ? ''
        : sprintf($dateClause, 'payment.paid_at', 'payment.paid_at');

    $patientsPerDay = $queryRows(
        $database,
        'SELECT DATE(visit.arrived_at) AS report_date,
                COUNT(DISTINCT visit.patient_id) AS patient_count
         FROM visits visit
         WHERE 1 = 1' . $patientsDateFilter . '
         GROUP BY DATE(visit.arrived_at)
         ORDER BY report_date DESC',
        $parameters
    );
    $testsByType = $queryRows(
        $database,
        'SELECT test.test_type, COUNT(*) AS order_count
         FROM test_orders test
         WHERE test.status <> \'cancelled\'' . $testsDateFilter . '
         GROUP BY test.test_type
         ORDER BY test.test_type',
        $parameters
    );
    $specialistReferrals = $queryRows(
        $database,
        'SELECT COUNT(*) AS referral_count
         FROM specialist_referrals
         WHERE 1 = 1' . $specialistDateFilter,
        $parameters
    );
    $outsideReferrals = $queryRows(
        $database,
        'SELECT COUNT(*) AS referral_count
         FROM outside_referrals
         WHERE 1 = 1' . $outsideDateFilter,
        $parameters
    );
    $revenueByDate = $queryRows(
        $database,
        'SELECT DATE(payment.paid_at) AS report_date,
                SUM(payment.amount) AS revenue
         FROM payments payment
         WHERE payment.status = \'approved\' AND payment.paid_at IS NOT NULL'
            . $paymentsDateFilter . '
         GROUP BY DATE(payment.paid_at)
         ORDER BY report_date DESC',
        $parameters
    );
    $revenueByMethod = $queryRows(
        $database,
        'SELECT payment.payment_method, SUM(payment.amount) AS revenue
         FROM payments payment
         WHERE payment.status = \'approved\' AND payment.paid_at IS NOT NULL'
            . $paymentsDateFilter . '
         GROUP BY payment.payment_method
         ORDER BY payment.payment_method',
        $parameters
    );

    foreach ($patientsPerDay as &$row) {
        $row['patient_count'] = (int) $row['patient_count'];
    }
    unset($row);
    foreach ($testsByType as &$row) {
        $row['order_count'] = (int) $row['order_count'];
    }
    unset($row);
    foreach ($revenueByDate as &$row) {
        $row['revenue'] = number_format((float) $row['revenue'], 2, '.', '');
    }
    unset($row);
    foreach ($revenueByMethod as &$row) {
        $row['revenue'] = number_format((float) $row['revenue'], 2, '.', '');
    }
    unset($row);

    jsonSuccess('Reports loaded.', [
        'date_range' => $dateFilters,
        'patients_per_day' => $patientsPerDay,
        'tests_by_type' => $testsByType,
        'specialist_referrals' => (int) $specialistReferrals[0]['referral_count'],
        'outside_referrals' => (int) $outsideReferrals[0]['referral_count'],
        'revenue_by_date' => $revenueByDate,
        'revenue_by_method' => $revenueByMethod,
    ]);
}
