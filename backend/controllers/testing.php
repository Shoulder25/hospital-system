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
    string $status,
    string $testType = ''
): array {
    $departments = testingDepartments();
    $departmentFilter = '';
    $parameters = [];
    if ($scope === 'testing_desk') {
        $validDepartmentConditions = [];
        foreach ($departments as $department => $configuration) {
            $placeholders = [];
            foreach ($configuration['tests'] as $index => $allowedTestType) {
                $placeholder = 'desk_test_' . $department . '_' . $index;
                $placeholders[] = ':' . $placeholder;
                $parameters[$placeholder] = $allowedTestType;
            }
            $validDepartmentConditions[] = '(t.department = \''
                . $department . '\' AND t.test_type IN ('
                . implode(', ', $placeholders) . '))';
        }
        $departmentFilter = ' AND ('
            . implode(' OR ', $validDepartmentConditions) . ')';
    } elseif ($action !== 'initialize') {
        if (!isset($departments[$scope])) {
            jsonError('Unknown testing department.', [], 404);
        }
        $departmentFilter = ' AND t.department = :department';
        $parameters['department'] = $scope;
        $allowedTypes = $departments[$scope]['tests'];
        $typePlaceholders = [];
        foreach ($allowedTypes as $index => $allowedTestType) {
            $placeholder = 'test_type_' . $index;
            $typePlaceholders[] = ':' . $placeholder;
            $parameters[$placeholder] = $allowedTestType;
        }
        $departmentFilter .= ' AND t.test_type IN ('
            . implode(', ', $typePlaceholders) . ')';
        if ($scope === 'laboratory') {
            $departmentFilter .= ' AND t.testing_desk_routed_at IS NOT NULL';
        }
    }

    $testTypeFilter = '';
    if ($testType !== '') {
        if (!isset($departments[$scope])
            || !in_array($testType, $departments[$scope]['tests'], true)) {
            jsonError('Select a test type supported by this department.', [
                'test_type' => 'Choose a supported test type.',
            ], 422);
        }
        $testTypeFilter = ' AND t.test_type = :selected_test_type';
        $parameters['selected_test_type'] = $testType;
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
            . $departmentFilter . $testTypeFilter . $statusFilter . $searchFilter .
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
                'specimen' => null,
                'specimens' => [],
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

    if ($scope === 'laboratory' && $orders !== []) {
        $orderIds = array_keys($orders);
        $placeholders = [];
        $specimenParameters = [];
        foreach ($orderIds as $index => $orderId) {
            $placeholder = 'specimen_order_' . $index;
            $placeholders[] = ':' . $placeholder;
            $specimenParameters[$placeholder] = $orderId;
        }
        $specimenStatement = $database->prepare(
            'SELECT specimen.lab_specimen_id, specimen.test_order_id,
                    specimen.specimen_type, specimen.status,
                    specimen.collected_at, specimen.rejection_reason,
                    specimen.notes, specimen.updated_at,
                    collected_by.display_name AS recorded_by,
                    updated_by.display_name AS updated_by
             FROM lab_specimens specimen
             INNER JOIN users collected_by
                ON collected_by.user_id = specimen.recorded_by_user_id
             LEFT JOIN users updated_by
                ON updated_by.user_id = specimen.updated_by_user_id
             WHERE specimen.test_order_id IN (' . implode(', ', $placeholders) . ')
             ORDER BY specimen.lab_specimen_id ASC'
        );
        $specimenStatement->execute($specimenParameters);
        foreach ($specimenStatement->fetchAll() as $specimenRow) {
            $orderId = (int) $specimenRow['test_order_id'];
            $specimen = [
                'lab_specimen_id' => (int) $specimenRow['lab_specimen_id'],
                'type' => $specimenRow['specimen_type'],
                'status' => $specimenRow['status'],
                'collected_at' => $specimenRow['collected_at'],
                'rejection_reason' => $specimenRow['rejection_reason'],
                'notes' => $specimenRow['notes'],
                'updated_at' => $specimenRow['updated_at'],
                'recorded_by' => $specimenRow['recorded_by'],
                'updated_by' => $specimenRow['updated_by'],
            ];
            $orders[$orderId]['specimens'][] = $specimen;
            $orders[$orderId]['specimen'] = $specimen;
        }
    }

    return array_values($orders);
}

function handleTestingOrdersGet(
    string $scope,
    string $search,
    string $status,
    string $testType = ''
): void
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

    $orders = testingOrderRows($database, $scope, $search, $status, $testType);
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
        if ($scope === 'laboratory'
            && $countRow['testing_desk_routed_at'] === null) {
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
        if ($department === 'laboratory') {
            $specimenStatement = $database->prepare(
                'INSERT INTO lab_specimens
                    (test_order_id, status, recorded_by_user_id, updated_by_user_id)
                 VALUES (:order_id, \'awaiting_collection\', :user_id, :user_id)'
            );
            $specimenStatement->execute([
                'order_id' => (int) $orderId,
                'user_id' => (int) $user['user_id'],
            ]);
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

function handleLaboratorySpecimenUpdate(string $orderId): void
{
    if (!ctype_digit($orderId) || (int) $orderId < 1) {
        jsonError('A valid test order ID is required.', [], 400);
    }

    $database = getDatabaseConnection();
    $user = requireTestingAccess($database, ['laboratory_technician']);
    requireCsrfRequest();
    $input = readJsonRequest();
    $action = $input['action'] ?? null;
    if (!is_string($action)
        || !in_array($action, [
            'initialize',
            'collect',
            'processing',
            'reject',
            'recollect',
        ], true)) {
        jsonError('Choose a valid specimen action.', [
            'action' => 'Select initialize, collect, processing, reject, or recollect.',
        ], 422);
    }

    $specimenType = $input['specimen_type'] ?? null;
    $rejectionReason = $input['rejection_reason'] ?? null;
    $notes = $input['notes'] ?? null;
    $errors = [];
    if ($action === 'collect'
        && (!is_string($specimenType) || trim($specimenType) === ''
            || strlen(trim($specimenType)) > 100)) {
        $errors['specimen_type'] = 'Enter a specimen type of up to 100 characters.';
    }
    if ($action === 'reject'
        && (!is_string($rejectionReason) || trim($rejectionReason) === ''
            || strlen(trim($rejectionReason)) > 10000)) {
        $errors['rejection_reason'] = 'Enter a rejection reason of up to 10000 characters.';
    }
    if ($notes !== null
        && (!is_string($notes) || strlen(trim($notes)) > 10000)) {
        $errors['notes'] = 'Notes must be text of 10000 characters or fewer.';
    }
    if ($errors !== []) {
        jsonError('Please correct the specimen information.', $errors, 422);
    }

    try {
        $database->beginTransaction();
        $order = testingOrderForUpdate($database, (int) $orderId);
        if ($order['department'] !== 'laboratory'
            || !in_array($order['test_type'], testingDepartments()['laboratory']['tests'], true)) {
            $database->rollBack();
            jsonError('This order is not a laboratory investigation.', [], 403);
        }
        if ($order['status'] !== 'in_progress') {
            $database->rollBack();
            jsonError('Start the laboratory order before updating its specimen.', [], 409);
        }

        $latestStatement = $database->prepare(
            'SELECT lab_specimen_id, status
             FROM lab_specimens
             WHERE test_order_id = :order_id
             ORDER BY lab_specimen_id DESC
             LIMIT 1
             FOR UPDATE'
        );
        $latestStatement->execute(['order_id' => (int) $orderId]);
        $specimen = $latestStatement->fetch();
        if ($specimen === false) {
            if ($action !== 'initialize') {
                $database->rollBack();
                jsonError('No specimen collection attempt exists for this order.', [], 409);
            }
            $insertStatement = $database->prepare(
                'INSERT INTO lab_specimens
                    (test_order_id, status, recorded_by_user_id, updated_by_user_id)
                 VALUES (:order_id, \'awaiting_collection\', :user_id, :user_id)'
            );
            $insertStatement->execute([
                'order_id' => (int) $orderId,
                'user_id' => (int) $user['user_id'],
            ]);
            $specimenId = (int) $database->lastInsertId();
            $specimenStatus = 'awaiting_collection';
            $specimen = null;
        }

        if ($action === 'initialize' && $specimen !== null) {
            $database->rollBack();
            jsonError('Specimen tracking has already been initialized.', [], 409);
        }

        $userId = (int) $user['user_id'];
        if ($specimen !== null) {
            $specimenId = (int) $specimen['lab_specimen_id'];
            $specimenStatus = $specimen['status'];
        }
        if ($action === 'recollect') {
            if ($specimenStatus !== 'rejected') {
                $database->rollBack();
                jsonError('A new collection can only follow a rejected specimen.', [], 409);
            }
            $insertStatement = $database->prepare(
                'INSERT INTO lab_specimens
                    (test_order_id, status, recorded_by_user_id, updated_by_user_id)
                 VALUES (:order_id, \'awaiting_collection\', :user_id, :user_id)'
            );
            $insertStatement->execute([
                'order_id' => (int) $orderId,
                'user_id' => $userId,
            ]);
            $specimenId = (int) $database->lastInsertId();
            $specimenStatus = 'awaiting_collection';
        } else {
            $allowedTransitions = [
                'collect' => ['awaiting_collection'],
                'processing' => ['collected'],
                'reject' => ['awaiting_collection', 'collected', 'processing'],
            ];
            if (!in_array($specimenStatus, $allowedTransitions[$action], true)) {
                $database->rollBack();
                jsonError('This specimen is not in a state that allows that action.', [], 409);
            }

            if ($action === 'collect') {
                $updateStatement = $database->prepare(
                    'UPDATE lab_specimens
                     SET specimen_type = :specimen_type,
                         status = \'collected\',
                         collected_at = CURRENT_TIMESTAMP,
                         rejection_reason = NULL,
                         notes = :notes,
                         updated_by_user_id = :user_id
                     WHERE lab_specimen_id = :specimen_id
                       AND status = \'awaiting_collection\''
                );
                $updateStatement->execute([
                    'specimen_type' => trim((string) $specimenType),
                    'notes' => is_string($notes) && trim($notes) !== ''
                        ? trim($notes)
                        : null,
                    'user_id' => $userId,
                    'specimen_id' => $specimenId,
                ]);
                $specimenStatus = 'collected';
            } elseif ($action === 'processing') {
                $updateStatement = $database->prepare(
                    'UPDATE lab_specimens
                     SET status = \'processing\',
                         notes = COALESCE(:notes, notes),
                         updated_by_user_id = :user_id
                     WHERE lab_specimen_id = :specimen_id
                       AND status = \'collected\''
                );
                $updateStatement->execute([
                    'notes' => is_string($notes) && trim($notes) !== ''
                        ? trim($notes)
                        : null,
                    'user_id' => $userId,
                    'specimen_id' => $specimenId,
                ]);
                $specimenStatus = 'processing';
            } else {
                $updateStatement = $database->prepare(
                    'UPDATE lab_specimens
                     SET status = \'rejected\',
                         rejection_reason = :reason,
                         notes = COALESCE(:notes, notes),
                         updated_by_user_id = :user_id
                     WHERE lab_specimen_id = :specimen_id
                       AND status IN (\'awaiting_collection\', \'collected\', \'processing\')'
                );
                $updateStatement->execute([
                    'reason' => trim((string) $rejectionReason),
                    'notes' => is_string($notes) && trim($notes) !== ''
                        ? trim($notes)
                        : null,
                    'user_id' => $userId,
                    'specimen_id' => $specimenId,
                ]);
                $specimenStatus = 'rejected';
            }
            if ($updateStatement->rowCount() !== 1) {
                $database->rollBack();
                jsonError('The specimen was changed by another user. Refresh and try again.', [], 409);
            }
        }

        recordTestingAudit(
            $database,
            $userId,
            'laboratory.specimen.' . $action,
            (int) $order['patient_id'],
            (int) $order['visit_id']
        );
        $database->commit();
    } catch (Throwable $exception) {
        if ($database->inTransaction()) {
            $database->rollBack();
        }
        error_log('Laboratory specimen update failed: ' . $exception->getMessage());
        jsonError('Unable to update specimen tracking right now.', [], 500);
    }

    jsonSuccess('Specimen tracking updated.', [
        'test_order_id' => (int) $orderId,
        'lab_specimen_id' => $specimenId,
        'status' => $specimenStatus,
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
    $resultStatus = $input['result_status'] ?? 'final';
    $errors = [];
    if (!is_string($resultText) || trim($resultText) === ''
        || strlen(trim($resultText)) > 50000) {
        $errors['result_text'] = 'Enter a result or report of up to 50000 characters.';
    }
    if ($notes !== null
        && (!is_string($notes) || strlen(trim($notes)) > 10000)) {
        $errors['notes'] = 'Notes must be text of 10000 characters or fewer.';
    }
    $allowedResultStatuses = $department === 'laboratory'
        ? ['preliminary', 'final', 'amended']
        : ['final'];
    if (!is_string($resultStatus)
        || !in_array($resultStatus, $allowedResultStatuses, true)) {
        $errors['result_status'] = 'Choose a result status supported by this department.';
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
        if ($resultStatus === 'amended') {
            if ($department !== 'laboratory' || $order['status'] !== 'completed') {
                $database->rollBack();
                jsonError('Only completed laboratory orders accept added result entries.', [], 409);
            }
        } elseif ($order['status'] !== 'in_progress') {
            $database->rollBack();
            jsonError('Start the test before recording its result.', [], 409);
        }

        if ($resultStatus === 'final' || $resultStatus === 'amended') {
            $existingFinal = $database->prepare(
                'SELECT test_result_id FROM test_results
                 WHERE test_order_id = :order_id AND status = \'final\'
                 LIMIT 1'
            );
            $existingFinal->execute(['order_id' => (int) $orderId]);
            $hasFinal = $existingFinal->fetchColumn() !== false;
            if ($resultStatus === 'final' && $hasFinal) {
                $database->rollBack();
                jsonError('A final result already exists for this test order.', [], 409);
            }
            if ($resultStatus === 'amended' && !$hasFinal) {
                $database->rollBack();
                jsonError('A final result is required before adding a result entry.', [], 409);
            }
        }

        if ($department === 'laboratory'
            && ($resultStatus === 'preliminary' || $resultStatus === 'final')) {
            $specimenStatement = $database->prepare(
                'SELECT lab_specimen_id, status
                 FROM lab_specimens
                 WHERE test_order_id = :order_id
                 ORDER BY lab_specimen_id DESC
                 LIMIT 1
                 FOR UPDATE'
            );
            $specimenStatement->execute(['order_id' => (int) $orderId]);
            $specimen = $specimenStatement->fetch();
            if ($specimen === false || $specimen['status'] !== 'processing') {
                $database->rollBack();
                jsonError('A specimen must be in processing before results can be recorded.', [], 409);
            }
        }

        $insertStatement = $database->prepare(
            'INSERT INTO test_results
                (test_order_id, recorded_by_user_id, result_text, result_notes, status)
             VALUES
                (:order_id, :user_id, :result_text, :result_notes, :result_status)'
        );
        $insertStatement->execute([
            'order_id' => (int) $orderId,
            'user_id' => (int) $user['user_id'],
            'result_text' => trim($resultText),
            'result_notes' => is_string($notes) && trim($notes) !== ''
                ? trim($notes)
                : null,
            'result_status' => $resultStatus,
        ]);
        $resultId = (int) $database->lastInsertId();

        if ($resultStatus === 'final') {
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
            if ($department === 'laboratory') {
                $completeSpecimen = $database->prepare(
                    'UPDATE lab_specimens
                     SET status = \'completed\', updated_by_user_id = :user_id
                     WHERE lab_specimen_id = :specimen_id AND status = \'processing\''
                );
                $completeSpecimen->execute([
                    'user_id' => (int) $user['user_id'],
                    'specimen_id' => (int) $specimen['lab_specimen_id'],
                ]);
                if ($completeSpecimen->rowCount() !== 1) {
                    $database->rollBack();
                    jsonError('The specimen is no longer in processing.', [], 409);
                }
            }
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
        if ($resultStatus === 'final') {
            $bill = billingGetOrCreateBill($database, (int) $order['visit_id']);
            billingAddFeeItemOnce(
                $database,
                (int) $bill['bill_id'],
                $testFeeCodes[$order['test_type']],
                'test',
                (int) $orderId
            );
            billingRefreshBillTotal($database, (int) $bill['bill_id']);
        }
        recordTestingAudit(
            $database,
            (int) $user['user_id'],
            $department . '.result.' . $resultStatus,
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
        'result_status' => $resultStatus,
        'status' => $resultStatus === 'final' ? 'completed' : $order['status'],
    ], 201);
}
