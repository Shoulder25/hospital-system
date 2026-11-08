<?php

declare(strict_types=1);

function testingDepartments(): array
{
    return [
        'laboratory' => [
            'roles' => ['laboratory_technician'],
            'tests' => ['blood', 'glucose', 'urine', 'pregnancy', 'pap_smear'],
        ],
        'radiology' => [
            'roles' => ['radiographer'],
            'tests' => ['x_ray'],
        ],
        'cardiac' => [
            'roles' => ['cardiac_technician'],
            'tests' => ['ecg'],
        ],
    ];
}

function requireTestingAccess(PDO $database, array $roles): array
{
    $user = getAuthenticatedUser($database);
    if ($user === null) {
        jsonError('Authentication is required.', [], 401);
    }

    if ($user['role_name'] !== 'system_administrator'
        && !in_array($user['role_name'], $roles, true)) {
        jsonError('You are not authorized to access this testing function.', [], 403);
    }

    return $user;
}

function recordTestingAudit(
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

function testingOrderRows(
    PDO $database,
    string $scope,
    string $search,
    string $status
): array {
    $departments = testingDepartments();
    $departmentFilter = '';
    $parameters = [];
    if ($scope === 'testing_desk') {
        $validDepartmentConditions = [];
        foreach ($departments as $department => $configuration) {
            $placeholders = [];
            foreach ($configuration['tests'] as $index => $testType) {
                $placeholder = 'desk_test_' . $department . '_' . $index;
                $placeholders[] = ':' . $placeholder;
                $parameters[$placeholder] = $testType;
            }
            $validDepartmentConditions[] = '(t.department = \''
                . $department . '\' AND t.test_type IN ('
                . implode(', ', $placeholders) . '))';
        }
        $departmentFilter = ' AND ('
            . implode(' OR ', $validDepartmentConditions) . ')';
    } else {
        if (!isset($departments[$scope])) {
            jsonError('Unknown testing department.', [], 404);
        }
        $departmentFilter = ' AND t.department = :department';
        $parameters['department'] = $scope;
        $allowedTypes = $departments[$scope]['tests'];
        $typePlaceholders = [];
        foreach ($allowedTypes as $index => $testType) {
            $placeholder = 'test_type_' . $index;
            $typePlaceholders[] = ':' . $placeholder;
            $parameters[$placeholder] = $testType;
        }
        $departmentFilter .= ' AND t.test_type IN ('
            . implode(', ', $typePlaceholders) . ')';
    }

    $statusFilter = ' AND t.status <> \'cancelled\'';
    if ($status !== '') {
        $allowedStatuses = ['all', 'ordered', 'in_progress', 'completed', 'cancelled'];
        if (!in_array($status, $allowedStatuses, true)) {
            jsonError('Select a valid order status.', [
                'status' => 'Choose all, ordered, in progress, completed, or cancelled.',
            ], 422);
        }
        if ($status !== 'all') {
            $statusFilter = ' AND t.status = :status';
            $parameters['status'] = $status;
        } else {
            $statusFilter = '';
        }
    }

    $searchFilter = '';
    if ($search !== '') {
        $pattern = '%' . $search . '%';
        $searchFilter = ' AND (
            p.patient_number LIKE :patient_number
            OR p.first_name LIKE :first_name
            OR p.last_name LIKE :last_name
            OR CONCAT_WS(\' \', p.first_name, p.last_name) LIKE :full_name
            OR CAST(v.visit_id AS CHAR) LIKE :visit_id
            OR CAST(t.test_order_id AS CHAR) LIKE :order_id
        )';
        $parameters += [
            'patient_number' => $pattern,
            'first_name' => $pattern,
            'last_name' => $pattern,
            'full_name' => $pattern,
            'visit_id' => $pattern,
            'order_id' => $pattern,
        ];
    }

    $statement = $database->prepare(
        'SELECT t.test_order_id, t.visit_id, t.test_type, t.department,
                t.status AS order_status, t.ordered_at,
                t.retest_of_order_id, t.testing_desk_routed_at,
                routed.display_name AS routed_by,
                ordering_user.display_name AS ordered_by,
                ordering_role.role_name AS ordered_by_role,
                p.patient_id, p.patient_number, p.first_name, p.last_name,
                v.arrived_at, v.is_emergency,
                tr.test_result_id, tr.result_text, tr.result_notes,
                tr.status AS result_status, tr.recorded_at,
                recorder.display_name AS recorded_by
         FROM test_orders t
         INNER JOIN visits v ON v.visit_id = t.visit_id
         INNER JOIN patients p ON p.patient_id = v.patient_id
         LEFT JOIN users routed
            ON routed.user_id = t.testing_desk_routed_by_user_id
         INNER JOIN users ordering_user
            ON ordering_user.user_id = t.ordered_by_user_id
         INNER JOIN roles ordering_role
            ON ordering_role.role_id = ordering_user.role_id
         LEFT JOIN test_results tr ON tr.test_order_id = t.test_order_id
         LEFT JOIN users recorder ON recorder.user_id = tr.recorded_by_user_id
         WHERE 1 = 1'
            . $departmentFilter . $statusFilter . $searchFilter .
        ' ORDER BY
            CASE t.status
                WHEN \'ordered\' THEN 1
                WHEN \'in_progress\' THEN 2
                WHEN \'completed\' THEN 3
                ELSE 4
            END,
            t.ordered_at ASC, t.test_order_id ASC,
            tr.recorded_at ASC, tr.test_result_id ASC
          LIMIT 500'
    );
    $statement->execute($parameters);

    $orders = [];
    foreach ($statement->fetchAll() as $row) {
        $orderId = (int) $row['test_order_id'];
        if (!isset($orders[$orderId])) {
            $orders[$orderId] = [
                'test_order_id' => $orderId,
                'visit_id' => (int) $row['visit_id'],
                'patient_id' => (int) $row['patient_id'],
                'patient_number' => $row['patient_number'],
                'patient_name' => trim(
                    (string) $row['first_name'] . ' ' . (string) $row['last_name']
                ),
                'arrived_at' => $row['arrived_at'],
                'is_emergency' => (bool) $row['is_emergency'],
                'test_type' => $row['test_type'],
                'department' => $row['department'],
                'status' => $row['order_status'],
                'ordered_at' => $row['ordered_at'],
                'retest_of_order_id' => $row['retest_of_order_id'] === null
                    ? null
                    : (int) $row['retest_of_order_id'],
                'ordered_by' => $row['ordered_by'],
                'ordered_by_role' => $row['ordered_by_role'],
                'desk_routed_at' => $row['testing_desk_routed_at'],
                'routed_by' => $row['routed_by'],
                'results' => [],
            ];
        }
        if ($row['test_result_id'] !== null) {
            $orders[$orderId]['results'][] = [
                'test_result_id' => (int) $row['test_result_id'],
                'text' => $row['result_text'],
                'notes' => $row['result_notes'],
                'status' => $row['result_status'],
                'recorded_at' => $row['recorded_at'],
                'recorded_by' => $row['recorded_by'],
            ];
        }
    }

    return array_values($orders);
}

function handleTestingOrdersGet(string $scope, string $search, string $status): void
{
    $database = getDatabaseConnection();
    if ($scope === 'testing_desk') {
        requireTestingAccess($database, ['testing_desk_clerk']);
    } else {
        $departments = testingDepartments();
        if (!isset($departments[$scope])) {
            jsonError('Unknown testing department.', [], 404);
        }
        requireTestingAccess($database, $departments[$scope]['roles']);
    }

    $search = trim($search);
    if (strlen($search) > 100) {
        jsonError('Search text must be 100 characters or fewer.', [
            'search' => 'Shorten the search text.',
        ], 422);
    }

    $orders = testingOrderRows($database, $scope, $search, $status);
    $validTypeByDepartment = [];
    foreach (testingDepartments() as $department => $configuration) {
        $validTypeByDepartment[$department] = $configuration['tests'];
    }
    $countsStatement = $database->query(
        'SELECT department, test_type, status, testing_desk_routed_at,
                COUNT(*) AS order_count
         FROM test_orders
         WHERE status <> \'cancelled\'
         GROUP BY department, test_type, status, testing_desk_routed_at'
    );
    $counts = ['ordered' => 0, 'in_progress' => 0, 'completed' => 0];
    foreach ($countsStatement->fetchAll() as $countRow) {
        $department = $countRow['department'];
        if ($scope !== 'testing_desk'
            && $department !== $scope) {
            continue;
        }
        if ($scope === 'testing_desk'
            && $countRow['status'] === 'ordered'
            && $countRow['testing_desk_routed_at'] !== null) {
            continue;
        }
        if (!isset($validTypeByDepartment[$department])
            || !in_array($countRow['test_type'], $validTypeByDepartment[$department], true)
            || !in_array($countRow['status'], array_keys($counts), true)) {
            continue;
        }
        $counts[$countRow['status']] += (int) $countRow['order_count'];
    }
    jsonSuccess(
        $scope === 'testing_desk' ? 'Testing centre orders loaded.' : 'Department orders loaded.',
        ['orders' => $orders, 'counts' => $counts]
    );
}

function testingOrderForUpdate(PDO $database, int $orderId): array
{
    $statement = $database->prepare(
        'SELECT t.test_order_id, t.visit_id, t.test_type, t.department,
                t.status, t.testing_desk_routed_at,
                v.patient_id
         FROM test_orders t
         INNER JOIN visits v ON v.visit_id = t.visit_id
         WHERE t.test_order_id = :order_id
         LIMIT 1
         FOR UPDATE'
    );
    $statement->execute(['order_id' => $orderId]);
    $order = $statement->fetch();
    if ($order === false) {
        jsonError('Test order not found.', [], 404);
    }

    return $order;
}

function handleTestingDeskRoute(string $orderId): void
{
    if (!ctype_digit($orderId) || (int) $orderId < 1) {
        jsonError('A valid test order ID is required.', [], 400);
    }
    $database = getDatabaseConnection();
    $user = requireTestingAccess($database, ['testing_desk_clerk']);
    requireCsrfRequest();

    try {
        $database->beginTransaction();
        $order = testingOrderForUpdate($database, (int) $orderId);
        $departments = testingDepartments();
        if (!isset($departments[$order['department']])
            || !in_array(
                $order['test_type'],
                $departments[$order['department']]['tests'],
                true
            )) {
            $database->rollBack();
            jsonError('This test order has an invalid department assignment.', [], 409);
        }
        if ($order['status'] !== 'ordered') {
            $database->rollBack();
            jsonError('Only new test orders can be routed by the testing desk.', [], 409);
        }
        if ($order['testing_desk_routed_at'] !== null) {
            $database->rollBack();
            jsonError('This test order has already been routed.', [], 409);
        }

        $routeStatement = $database->prepare(
            'UPDATE test_orders
             SET testing_desk_routed_at = CURRENT_TIMESTAMP,
                 testing_desk_routed_by_user_id = :user_id
             WHERE test_order_id = :order_id
               AND testing_desk_routed_at IS NULL
               AND status = \'ordered\''
        );
        $routeStatement->execute([
            'user_id' => (int) $user['user_id'],
            'order_id' => (int) $orderId,
        ]);
        if ($routeStatement->rowCount() !== 1) {
            $database->rollBack();
            jsonError('This test order has already been routed.', [], 409);
        }
        recordTestingAudit(
            $database,
            (int) $user['user_id'],
            'testing_desk.routed.' . $order['department'],
            (int) $order['patient_id'],
            (int) $order['visit_id']
        );
        $database->commit();
    } catch (Throwable $exception) {
        if ($database->inTransaction()) {
            $database->rollBack();
        }
        error_log('Testing desk routing failed: ' . $exception->getMessage());
        jsonError('Unable to route the test order right now.', [], 500);
    }

    jsonSuccess('Patient routed to ' . $order['department'] . '.', [
        'test_order_id' => (int) $orderId,
        'visit_id' => (int) $order['visit_id'],
        'department' => $order['department'],
        'status' => 'ordered',
        'routed' => true,
    ]);
}

function handleTestingOrderStart(string $department, string $orderId): void
{
    if (!ctype_digit($orderId) || (int) $orderId < 1) {
        jsonError('A valid test order ID is required.', [], 400);
    }
    $departments = testingDepartments();
    if (!isset($departments[$department])) {
        jsonError('Unknown testing department.', [], 404);
    }

    $database = getDatabaseConnection();
    $user = requireTestingAccess($database, $departments[$department]['roles']);
    requireCsrfRequest();
    try {
        $database->beginTransaction();
        $order = testingOrderForUpdate($database, (int) $orderId);
        if ($order['department'] !== $department
            || !in_array($order['test_type'], $departments[$department]['tests'], true)) {
            $database->rollBack();
            jsonError('This test order belongs to another department.', [], 403);
        }
        if ($order['status'] !== 'ordered') {
            $database->rollBack();
            jsonError('Only ordered tests can be started.', [], 409);
        }
        if ($order['testing_desk_routed_at'] === null) {
            $database->rollBack();
            jsonError('The testing centre desk must route this order before processing.', [], 409);
        }

        $updateStatement = $database->prepare(
            'UPDATE test_orders
             SET status = \'in_progress\'
             WHERE test_order_id = :order_id AND status = \'ordered\''
        );
        $updateStatement->execute(['order_id' => (int) $orderId]);
        if ($updateStatement->rowCount() !== 1) {
            $database->rollBack();
            jsonError('This test order is no longer available to start.', [], 409);
        }
        recordTestingAudit(
            $database,
            (int) $user['user_id'],
            $department . '.test.started',
            (int) $order['patient_id'],
            (int) $order['visit_id']
        );
        $database->commit();
    } catch (Throwable $exception) {
        if ($database->inTransaction()) {
            $database->rollBack();
        }
        error_log('Test processing start failed: ' . $exception->getMessage());
        jsonError('Unable to start processing this test right now.', [], 500);
    }

    jsonSuccess('Test marked in progress.', [
        'test_order_id' => (int) $orderId,
        'status' => 'in_progress',
    ]);
}

function handleTestingResultCreate(string $department): void
{
    $departments = testingDepartments();
    if (!isset($departments[$department])) {
        jsonError('Unknown testing department.', [], 404);
    }

    $database = getDatabaseConnection();
    $user = requireTestingAccess($database, $departments[$department]['roles']);
    requireCsrfRequest();
    $input = readJsonRequest();
    $orderId = $input['test_order_id'] ?? null;
    if ((!is_int($orderId) && !(is_string($orderId) && ctype_digit($orderId)))
        || (int) $orderId < 1) {
        jsonError('A valid test order ID is required.', [
            'test_order_id' => 'Enter the original test order ID.',
        ], 422);
    }
    $resultText = $input['result_text'] ?? null;
    $notes = $input['notes'] ?? null;
    $errors = [];
    if (!is_string($resultText) || trim($resultText) === ''
        || strlen(trim($resultText)) > 50000) {
        $errors['result_text'] = 'Enter a result or report of up to 50000 characters.';
    }
    if ($notes !== null
        && (!is_string($notes) || strlen(trim($notes)) > 10000)) {
        $errors['notes'] = 'Notes must be text of 10000 characters or fewer.';
    }
    if ($errors !== []) {
        jsonError('Please correct the test result.', $errors, 422);
    }

    try {
        $database->beginTransaction();
        $order = testingOrderForUpdate($database, (int) $orderId);
        if ($order['department'] !== $department
            || !in_array($order['test_type'], $departments[$department]['tests'], true)) {
            $database->rollBack();
            jsonError('This test order belongs to another department.', [], 403);
        }
        if ($order['status'] !== 'in_progress') {
            $database->rollBack();
            jsonError('Start the test before recording its result.', [], 409);
        }
        $existingResult = $database->prepare(
            'SELECT test_result_id FROM test_results
             WHERE test_order_id = :order_id
               AND status IN (\'final\', \'amended\')
             LIMIT 1'
        );
        $existingResult->execute(['order_id' => (int) $orderId]);
        if ($existingResult->fetchColumn() !== false) {
            $database->rollBack();
            jsonError('A final result already exists for this test order.', [], 409);
        }

        $insertStatement = $database->prepare(
            'INSERT INTO test_results
                (test_order_id, recorded_by_user_id, result_text, result_notes, status)
             VALUES
                (:order_id, :user_id, :result_text, :result_notes, \'final\')'
        );
        $insertStatement->execute([
            'order_id' => (int) $orderId,
            'user_id' => (int) $user['user_id'],
            'result_text' => trim($resultText),
            'result_notes' => is_string($notes) && trim($notes) !== ''
                ? trim($notes)
                : null,
        ]);
        $resultId = (int) $database->lastInsertId();

        $completeStatement = $database->prepare(
            'UPDATE test_orders
             SET status = \'completed\'
             WHERE test_order_id = :order_id AND status = \'in_progress\''
        );
        $completeStatement->execute(['order_id' => (int) $orderId]);
        if ($completeStatement->rowCount() !== 1) {
            $database->rollBack();
            jsonError('This test order is no longer in progress.', [], 409);
        }
        $testFeeCodes = [
            'blood' => 'TEST_BLOOD_DEMO',
            'glucose' => 'TEST_GLUCOSE_DEMO',
            'urine' => 'TEST_URINE_DEMO',
            'pregnancy' => 'TEST_PREGNANCY_DEMO',
            'pap_smear' => 'TEST_PAP_SMEAR_DEMO',
            'x_ray' => 'TEST_X_RAY_DEMO',
            'ecg' => 'TEST_ECG_DEMO',
        ];
        if (!isset($testFeeCodes[$order['test_type']])) {
            throw new RuntimeException('No demo fee is configured for this test type.');
        }
        $bill = billingGetOrCreateBill($database, (int) $order['visit_id']);
        billingAddFeeItemOnce(
            $database,
            (int) $bill['bill_id'],
            $testFeeCodes[$order['test_type']],
            'test',
            (int) $orderId
        );
        billingRefreshBillTotal($database, (int) $bill['bill_id']);
        recordTestingAudit(
            $database,
            (int) $user['user_id'],
            $department . '.result.recorded',
            (int) $order['patient_id'],
            (int) $order['visit_id']
        );
        $database->commit();
    } catch (Throwable $exception) {
        if ($database->inTransaction()) {
            $database->rollBack();
        }
        error_log('Test result recording failed: ' . $exception->getMessage());
        jsonError('Unable to save the test result right now.', [], 500);
    }

    jsonSuccess('Test result saved. The original order and result are retained.', [
        'test_result_id' => $resultId,
        'test_order_id' => (int) $orderId,
        'visit_id' => (int) $order['visit_id'],
        'status' => 'completed',
    ], 201);
}
