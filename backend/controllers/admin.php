<?php

declare(strict_types=1);

function adminUserResponse(array $user): array
{
    return [
        'user_id' => (int) $user['user_id'],
        'display_name' => $user['display_name'],
        'username' => $user['username'],
        'role' => [
            'role_id' => (int) $user['role_id'],
            'role_name' => $user['role_name'],
        ],
        'is_active' => (bool) $user['is_active'],
        'created_at' => $user['created_at'],
    ];
}

function handleAdminUsersGet(string $search): void
{
    $database = getDatabaseConnection();
    requireAdministrator($database);

    $search = trim($search);
    if (strlen($search) > 100) {
        jsonError('Search text must be 100 characters or fewer.', [
            'search' => 'Shorten the search text.',
        ], 422);
    }

    $sql = 'SELECT u.user_id, u.display_name, u.username, u.is_active,
                   u.created_at, r.role_id, r.role_name
            FROM users u
            INNER JOIN roles r ON r.role_id = u.role_id';
    $parameters = [];

    if ($search !== '') {
        $sql .= ' WHERE u.display_name LIKE :name_search
                  OR u.username LIKE :username_search
                  OR r.role_name LIKE :role_search';
        $pattern = '%' . $search . '%';
        $parameters = [
            'name_search' => $pattern,
            'username_search' => $pattern,
            'role_search' => $pattern,
        ];
    }

    $sql .= ' ORDER BY u.created_at DESC, u.user_id DESC LIMIT 200';
    $statement = $database->prepare($sql);
    $statement->execute($parameters);
    $users = array_map('adminUserResponse', $statement->fetchAll());

    jsonSuccess('Staff accounts loaded.', ['users' => $users]);
}

function handleAdminRolesGet(): void
{
    $database = getDatabaseConnection();
    requireAdministrator($database);

    $statement = $database->query(
        'SELECT role_id, role_name, description
         FROM roles
         WHERE role_name <> \'system_administrator\'
         ORDER BY role_name'
    );
    $roles = array_map(static function (array $role): array {
        return [
            'role_id' => (int) $role['role_id'],
            'role_name' => $role['role_name'],
            'description' => $role['description'],
        ];
    }, $statement->fetchAll());

    jsonSuccess('Roles loaded.', ['roles' => $roles]);
}

function adminFeeResponse(array $fee): array
{
    return [
        'fee_id' => (int) $fee['fee_id'],
        'fee_code' => $fee['fee_code'],
        'description' => $fee['description'],
        'amount' => (string) $fee['default_amount'],
        'currency' => $fee['currency_code'],
        'is_demo' => (bool) $fee['is_demo'],
        'is_active' => (bool) $fee['is_active'],
        'cashier_addable' => (bool) $fee['cashier_addable'],
        'updated_at' => $fee['updated_at'],
        'updated_by' => $fee['updated_by'],
    ];
}

function handleAdminFeesGet(string $search): void
{
    $database = getDatabaseConnection();
    requireAdministrator($database);
    $search = trim($search);
    if (strlen($search) > 100) {
        jsonError('Search text must be 100 characters or fewer.', [
            'search' => 'Shorten the search text.',
        ], 422);
    }

    $sql = 'SELECT fee.fee_id, fee.fee_code, fee.description,
                   fee.default_amount, fee.currency_code, fee.is_demo,
                   fee.is_active, fee.cashier_addable, fee.updated_at,
                   staff.display_name AS updated_by
            FROM fee_catalog fee
            LEFT JOIN users staff ON staff.user_id = fee.updated_by_user_id';
    $parameters = [];
    if ($search !== '') {
        $sql .= ' WHERE fee.fee_code LIKE :code_search
                  OR fee.description LIKE :description_search';
        $pattern = '%' . $search . '%';
        $parameters = [
            'code_search' => $pattern,
            'description_search' => $pattern,
        ];
    }
    $sql .= ' ORDER BY fee.is_active DESC, fee.fee_code LIMIT 250';
    $statement = $database->prepare($sql);
    $statement->execute($parameters);
    $fees = array_map('adminFeeResponse', $statement->fetchAll());
    jsonSuccess('Configured fees loaded.', ['fees' => $fees]);
}

function handleAdminFeeCreate(): void
{
    $database = getDatabaseConnection();
    $admin = requireAdministrator($database);
    requireCsrfRequest();
    $input = readJsonRequest();
    $feeCode = $input['fee_code'] ?? null;
    $description = $input['description'] ?? null;
    $amount = $input['amount'] ?? null;
    $isActive = $input['is_active'] ?? null;
    $cashierAddable = $input['cashier_addable'] ?? null;
    $errors = [];
    if (!is_string($feeCode)
        || !preg_match('/^[A-Z][A-Z0-9_]{1,59}$/', $feeCode)) {
        $errors['fee_code'] = 'Use 2-60 uppercase letters, numbers or underscores.';
    }
    if (!is_string($description) || trim($description) === ''
        || strlen(trim($description)) > 180) {
        $errors['description'] = 'Enter a description of no more than 180 characters.';
    }
    if (!is_string($amount) || !preg_match('/^\d{1,8}(?:\.\d{1,2})?$/', $amount)) {
        $errors['amount'] = 'Enter a non-negative amount with up to two decimals.';
    }
    if (!is_bool($isActive) || !is_bool($cashierAddable)) {
        $errors['status'] = 'Choose valid active and cashier-addable settings.';
    }
    if (is_string($feeCode) && is_bool($cashierAddable) && $cashierAddable
        && !billingFeeAllowsCashierAdd($feeCode)) {
        $errors['cashier_addable'] = 'Workflow-managed fees must be charged by their department.';
    }
    if ($errors !== []) {
        jsonError('Please correct the fee details.', $errors, 422);
    }
    if ($cashierAddable && (float) $amount <= 0) {
        jsonError('A cashier-addable service must have a positive configured price.', [
            'amount' => 'Enter a positive amount before enabling cashier additions.',
        ], 422);
    }

    try {
        $database->beginTransaction();
        $insert = $database->prepare(
            'INSERT INTO fee_catalog
                (fee_code, description, default_amount, is_active, cashier_addable,
                 updated_by_user_id)
             VALUES
                (:fee_code, :description, :amount, :is_active, :cashier_addable,
                 :updated_by_user_id)'
        );
        $insert->execute([
            'fee_code' => $feeCode,
            'description' => trim($description),
            'amount' => $amount,
            'is_active' => $isActive ? 1 : 0,
            'cashier_addable' => $cashierAddable ? 1 : 0,
            'updated_by_user_id' => (int) $admin['user_id'],
        ]);
        $feeId = (int) $database->lastInsertId();
        $audit = $database->prepare(
            'INSERT INTO audit_log (user_id, action)
             VALUES (:user_id, :action)'
        );
        $audit->execute([
            'user_id' => (int) $admin['user_id'],
            'action' => 'admin.fee.created:' . $feeCode,
        ]);
        $database->commit();
    } catch (PDOException $exception) {
        if ($database->inTransaction()) {
            $database->rollBack();
        }
        if (($exception->errorInfo[0] ?? $exception->getCode()) === '23000') {
            jsonError('That fee code already exists.', [
                'fee_code' => 'Choose a unique fee code.',
            ], 409);
        }
        error_log('Administrator fee creation failed: ' . $exception->getMessage());
        jsonError('Unable to create the fee right now.', [], 500);
    }
    jsonSuccess('Fee created.', ['fee_id' => $feeId], 201);
}

function handleAdminFeeUpdate(string $feeId): void
{
    if (!ctype_digit($feeId) || (int) $feeId < 1) {
        jsonError('A valid fee ID is required.', [], 422);
    }
    $database = getDatabaseConnection();
    $admin = requireAdministrator($database);
    requireCsrfRequest();
    $input = readJsonRequest();
    $description = $input['description'] ?? null;
    $amount = $input['amount'] ?? null;
    $isActive = $input['is_active'] ?? null;
    $cashierAddable = $input['cashier_addable'] ?? null;
    $errors = [];
    if (!is_string($description) || trim($description) === ''
        || strlen(trim($description)) > 180) {
        $errors['description'] = 'Enter a description of no more than 180 characters.';
    }
    if (!is_string($amount) || !preg_match('/^\d{1,8}(?:\.\d{1,2})?$/', $amount)) {
        $errors['amount'] = 'Enter a non-negative amount with up to two decimals.';
    }
    if (!is_bool($isActive) || !is_bool($cashierAddable)) {
        $errors['status'] = 'Choose valid active and cashier-addable settings.';
    }
    if ($errors !== []) {
        jsonError('Please correct the fee details.', $errors, 422);
    }
    if ($cashierAddable && (float) $amount <= 0) {
        jsonError('A cashier-addable service must have a positive configured price.', [
            'amount' => 'Enter a positive amount before enabling cashier additions.',
        ], 422);
    }

    try {
        $database->beginTransaction();
        $feeStatement = $database->prepare(
            'SELECT fee_id, fee_code FROM fee_catalog
             WHERE fee_id = :fee_id LIMIT 1 FOR UPDATE'
        );
        $feeStatement->execute(['fee_id' => (int) $feeId]);
        $fee = $feeStatement->fetch();
        if ($fee === false) {
            $database->rollBack();
            jsonError('Fee not found.', [], 404);
        }
        if ($cashierAddable
            && !billingFeeAllowsCashierAdd((string) $fee['fee_code'])) {
            $database->rollBack();
            jsonError('Workflow-managed fees must be charged by their department.', [
                'cashier_addable' => 'This fee code cannot be added by cashiers.',
            ], 422);
        }
        $update = $database->prepare(
            'UPDATE fee_catalog
             SET description = :description, default_amount = :amount,
                 is_active = :is_active, cashier_addable = :cashier_addable,
                 updated_by_user_id = :updated_by_user_id
             WHERE fee_id = :fee_id'
        );
        $update->execute([
            'description' => trim($description),
            'amount' => $amount,
            'is_active' => $isActive ? 1 : 0,
            'cashier_addable' => $cashierAddable ? 1 : 0,
            'updated_by_user_id' => (int) $admin['user_id'],
            'fee_id' => (int) $feeId,
        ]);
        $audit = $database->prepare(
            'INSERT INTO audit_log (user_id, action)
             VALUES (:user_id, :action)'
        );
        $audit->execute([
            'user_id' => (int) $admin['user_id'],
            'action' => 'admin.fee.updated:' . $fee['fee_code'],
        ]);
        $database->commit();
    } catch (PDOException $exception) {
        if ($database->inTransaction()) {
            $database->rollBack();
        }
        error_log('Administrator fee update failed: ' . $exception->getMessage());
        jsonError('Unable to update the fee right now.', [], 500);
    }
    jsonSuccess('Fee updated.', ['fee_id' => (int) $feeId]);
}

function handleAdminUserCreate(): void
{
    $database = getDatabaseConnection();
    $admin = requireAdministrator($database);
    requireCsrfRequest();

    $input = readJsonRequest();
    $displayName = $input['display_name'] ?? null;
    $username = $input['username'] ?? null;
    $password = $input['password'] ?? null;
    $roleId = $input['role_id'] ?? null;
    $errors = [];

    if (!is_string($displayName) || trim($displayName) === ''
        || strlen(trim($displayName)) > 150) {
        $errors['display_name'] = 'Enter a name of no more than 150 characters.';
    }
    if (!is_string($username)
        || !preg_match('/^[A-Za-z0-9._-]{3,80}$/', $username)) {
        $errors['username'] = 'Use 3-80 letters, numbers, periods, underscores or hyphens.';
    }
    if (!is_string($password) || strlen($password) < 12 || strlen($password) > 4096) {
        $errors['password'] = 'Use a password between 12 and 4096 characters.';
    }
    if ((!is_int($roleId) && !(is_string($roleId) && ctype_digit($roleId)))
        || (int) $roleId < 1) {
        $errors['role_id'] = 'Select a valid role.';
    }
    if ($errors !== []) {
        jsonError('Please correct the highlighted fields.', $errors, 422);
    }

    try {
        $roleStatement = $database->prepare(
            'SELECT role_id, role_name
             FROM roles
             WHERE role_id = :role_id
             LIMIT 1'
        );
        $roleStatement->execute(['role_id' => (int) $roleId]);
        $role = $roleStatement->fetch();
        if ($role === false) {
            jsonError('The selected role does not exist.', [
                'role_id' => 'Select an available role.',
            ], 422);
        }
        if ($role['role_name'] === 'system_administrator') {
            jsonError('Administrator accounts cannot be created here.', [
                'role_id' => 'Choose a staff role.',
            ], 403);
        }

        $database->beginTransaction();
        $insertStatement = $database->prepare(
            'INSERT INTO users
                (role_id, username, password_hash, display_name, must_change_password)
             VALUES (:role_id, :username, :password_hash, :display_name, 1)'
        );
        $insertStatement->execute([
            'role_id' => (int) $role['role_id'],
            'username' => $username,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'display_name' => trim($displayName),
        ]);
        $newUserId = (int) $database->lastInsertId();
        $audit = $database->prepare(
            'INSERT INTO audit_log (user_id, action)
             VALUES (:user_id, :action)'
        );
        $audit->execute([
            'user_id' => (int) $admin['user_id'],
            'action' => 'admin.staff.created:' . $newUserId,
        ]);
        $database->commit();

        jsonSuccess('Staff account created.', [
            'user' => [
                'user_id' => $newUserId,
                'display_name' => trim($displayName),
                'username' => $username,
                'role' => [
                    'role_id' => (int) $role['role_id'],
                    'role_name' => $role['role_name'],
                ],
                'is_active' => true,
            ],
        ], 201);
    } catch (PDOException $exception) {
        if ($database->inTransaction()) {
            $database->rollBack();
        }
        if ($exception->getCode() === '23000') {
            jsonError('That username is already in use.', [
                'username' => 'Choose a different username.',
            ], 409);
        }

        error_log('Admin staff account creation failed: ' . $exception->getMessage());
        jsonError('Unable to create the staff account right now.', [], 500);
    }
}

function handleAdminUserStatus(string $userId): void
{
    if (!ctype_digit($userId) || (int) $userId < 1) {
        jsonError('A valid user ID is required.', [], 400);
    }

    $database = getDatabaseConnection();
    $currentAdmin = requireAdministrator($database);
    requireCsrfRequest();

    $input = readJsonRequest();
    $isActive = $input['is_active'] ?? null;
    if (!is_bool($isActive)) {
        jsonError('Account status must be true or false.', [
            'is_active' => 'Choose active or inactive.',
        ], 422);
    }

    $targetUserId = (int) $userId;
    if ($targetUserId === (int) $currentAdmin['user_id']) {
        jsonError('You cannot change your own account status.', [], 409);
    }

    try {
        $database->beginTransaction();
        $targetStatement = $database->prepare(
            'SELECT u.user_id, u.is_active, r.role_name
             FROM users u
             INNER JOIN roles r ON r.role_id = u.role_id
             WHERE u.user_id = :user_id
             LIMIT 1
             FOR UPDATE'
        );
        $targetStatement->execute(['user_id' => $targetUserId]);
        $target = $targetStatement->fetch();

        if ($target === false) {
            $database->rollBack();
            jsonError('Staff account not found.', [], 404);
        }

        if (!$isActive && $target['role_name'] === 'system_administrator'
            && (int) $target['is_active'] === 1) {
            $countStatement = $database->prepare(
                'SELECT COUNT(*)
                 FROM users u
                 INNER JOIN roles r ON r.role_id = u.role_id
                 WHERE r.role_name = :role_name AND u.is_active = 1'
            );
            $countStatement->execute(['role_name' => 'system_administrator']);
            if ((int) $countStatement->fetchColumn() <= 1) {
                $database->rollBack();
                jsonError('The last active administrator cannot be deactivated.', [], 409);
            }
        }

        $updateStatement = $database->prepare(
            'UPDATE users SET is_active = :is_active WHERE user_id = :user_id'
        );
        $updateStatement->execute([
            'is_active' => $isActive ? 1 : 0,
            'user_id' => $targetUserId,
        ]);
        if ((int) $target['is_active'] !== ($isActive ? 1 : 0)) {
            $audit = $database->prepare(
                'INSERT INTO audit_log (user_id, action)
                 VALUES (:user_id, :action)'
            );
            $audit->execute([
                'user_id' => (int) $currentAdmin['user_id'],
                'action' => ($isActive ? 'admin.staff.activated:' : 'admin.staff.deactivated:')
                    . $targetUserId,
            ]);
        }
        $database->commit();

        jsonSuccess('Staff account status updated.', [
            'user_id' => $targetUserId,
            'is_active' => $isActive,
        ]);
    } catch (PDOException $exception) {
        if ($database->inTransaction()) {
            $database->rollBack();
        }
        error_log('Admin staff status update failed: ' . $exception->getMessage());
        jsonError('Unable to update account status right now.', [], 500);
    }
}

function handleAdminUserPasswordReset(string $userId): void
{
    if (!ctype_digit($userId) || (int) $userId < 1) {
        jsonError('A valid user ID is required.', [], 400);
    }
    $database = getDatabaseConnection();
    $admin = requireAdministrator($database);
    requireCsrfRequest();
    $targetUserId = (int) $userId;
    if ($targetUserId === (int) $admin['user_id']) {
        jsonError('Use the password-change page to update your own password.', [], 409);
    }

    $temporaryPassword = bin2hex(random_bytes(16));
    try {
        $database->beginTransaction();
        $target = $database->prepare(
            'SELECT user_id FROM users WHERE user_id = :user_id LIMIT 1 FOR UPDATE'
        );
        $target->execute(['user_id' => $targetUserId]);
        if ($target->fetch() === false) {
            $database->rollBack();
            jsonError('Staff account not found.', [], 404);
        }

        $update = $database->prepare(
            'UPDATE users
             SET password_hash = :password_hash, must_change_password = 1
             WHERE user_id = :user_id'
        );
        $update->execute([
            'password_hash' => password_hash($temporaryPassword, PASSWORD_DEFAULT),
            'user_id' => $targetUserId,
        ]);
        $audit = $database->prepare(
            'INSERT INTO audit_log (user_id, action)
             VALUES (:user_id, :action)'
        );
        $audit->execute([
            'user_id' => (int) $admin['user_id'],
            'action' => 'admin.staff.password_reset:' . $targetUserId,
        ]);
        $database->commit();
    } catch (PDOException $exception) {
        if ($database->inTransaction()) {
            $database->rollBack();
        }
        error_log('Admin staff password reset failed: ' . $exception->getMessage());
        jsonError('Unable to reset the staff password right now.', [], 500);
    }

    jsonSuccess('Temporary password generated. Share it with the staff member securely.', [
        'temporary_password' => $temporaryPassword,
    ]);
}
